<?php

declare(strict_types=1);

namespace App\Service;

use AbcAeffchen\Sephpa\SephpaDirectDebit;
use AbcAeffchen\SepaUtilities\SepaUtilities;
use App\Domain\Bankarbeitstage;
use App\Domain\Einzugslaufstatus;
use App\Domain\Forderungsstatus;
use App\Repository\EinzugslaufRepository;
use App\Repository\ForderungRepository;
use App\Support\Db;

/**
 * Geführter SEPA-Einzugslauf (F5): Forderungen bündeln → Pre-Notification →
 * pain.008-XML → Abschluss → Rücklastschriften. IBAN-Klartext erscheint nur im
 * XML; jeder Statuswechsel wird im Audit-Log protokolliert.
 */
final class EinzugslaufService
{
    private const MIN_BANKTAGE = 2;

    public function __construct(
        private readonly Db $db,
        private readonly EinzugslaufRepository $laeufe,
        private readonly ForderungRepository $forderungen,
        private readonly MandatService $mandate,
        private readonly SollstellungService $sollstellung,
        private readonly Krypto $krypto,
        private readonly MailDienst $mail,
        private readonly AnredeDienst $anrede,
        private readonly Einstellungen $einstellungen,
        private readonly SepaXmlValidator $validator,
        private readonly Audit $audit,
        private readonly string $basisPfad,
    ) {
    }

    /**
     * Legt einen Lauf an und bindet alle aufnehmbaren Forderungen.
     *
     * @return array{id:int,warnungen:array<int,string>}
     */
    public function anlegen(string $bezeichnung, string $faelligkeit, ?int $benutzerId): array
    {
        $bezeichnung = trim($bezeichnung);
        if ($bezeichnung === '') {
            throw new \InvalidArgumentException('Bitte eine Bezeichnung angeben.');
        }
        $this->pruefeVereinsdaten();

        $faelligDatum = \DateTimeImmutable::createFromFormat('!Y-m-d', $faelligkeit);
        if (!$faelligDatum instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Ungültiges Fälligkeitsdatum.');
        }

        $heute = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $frueheste = Bankarbeitstage::fruehesteFaelligkeit($heute, self::MIN_BANKTAGE);
        if ($faelligDatum < $frueheste) {
            throw new \DomainException(sprintf(
                'Die Fälligkeit muss mindestens %d Bankarbeitstage in der Zukunft liegen (frühestens %s).',
                self::MIN_BANKTAGE,
                $frueheste->format('d.m.Y'),
            ));
        }

        $warnungen = [];
        $frist = $this->einstellungen->holeInt('prenotification_tage', 14);
        if (Bankarbeitstage::anzahlZwischen($heute, $faelligDatum) < $frist) {
            $warnungen[] = sprintf('Die Pre-Notification-Frist von %d Tagen wird unterschritten.', $frist);
        }

        $id = $this->laeufe->anlegen($bezeichnung, $faelligDatum->format('Y-m-d'), $benutzerId);
        $ids = array_map(static fn (array $z): int => (int) $z['id'], $this->laeufe->aufnehmbareForderungen());
        $this->laeufe->bindeForderungen($id, $ids);
        $this->summenAktualisieren($id);
        $this->audit->protokolliere($benutzerId, 'einzugslauf_angelegt', 'einzugslauf', $id, ['anzahl' => count($ids)]);

        return ['id' => $id, 'warnungen' => $warnungen];
    }

    /**
     * Vorschau mit FRST/RCUR-Bestimmung, maskierter IBAN und Summen je Sequenztyp.
     *
     * @return array{positionen:array<int,array<string,mixed>>,summe_frst:string,summe_rcur:string,summe:string,anzahl:int,anzahl_email:int,anzahl_post:int}
     */
    public function vorschau(int $laufId): array
    {
        $positionen = [];
        $centFrst = 0;
        $centRcur = 0;
        $email = 0;
        $post = 0;

        foreach ($this->laeufe->positionen($laufId) as $p) {
            $seq = ((int) $p['sequenz_genutzt']) === 1 ? 'RCUR' : 'FRST';
            $cents = $this->cents((string) $p['betrag']);
            if ($seq === 'FRST') {
                $centFrst += $cents;
            } else {
                $centRcur += $cents;
            }
            $perPost = ((int) $p['kein_email_kontakt']) === 1 || trim((string) ($p['email'] ?? '')) === '';
            $perPost ? $post++ : $email++;

            $p['sequenztyp'] = $seq;
            $p['iban_maskiert'] = $this->maskiere($p);
            $p['per_post'] = $perPost;
            $positionen[] = $p;
        }

        return [
            'positionen'   => $positionen,
            'summe_frst'   => $this->euro($centFrst),
            'summe_rcur'   => $this->euro($centRcur),
            'summe'        => $this->euro($centFrst + $centRcur),
            'anzahl'       => count($positionen),
            'anzahl_email' => $email,
            'anzahl_post'  => $post,
        ];
    }

    public function positionAbwaehlen(int $laufId, int $forderungId, ?int $benutzerId): void
    {
        $lauf = $this->ladePruefe($laufId, Einzugslaufstatus::ENTWURF, 'Positionen können nur im Entwurf abgewählt werden.');
        $this->db->ausfuehren(
            'UPDATE forderung SET einzugslauf_id = NULL, updated_at = :now WHERE id = :id AND einzugslauf_id = :lauf',
            ['now' => $this->jetzt(), 'id' => $forderungId, 'lauf' => $laufId],
        );
        $this->summenAktualisieren($laufId);
        $this->audit->protokolliere($benutzerId, 'einzugslauf_position_abgewaehlt', 'einzugslauf', $laufId, ['forderung' => $forderungId]);
    }

    /**
     * Pre-Notification: Mails je Position in die Queue; Post-Mitglieder auf die Brief-Liste.
     */
    public function ankuendigen(int $laufId, ?int $benutzerId): void
    {
        $lauf = $this->ladePruefe($laufId, Einzugslaufstatus::ENTWURF, 'Nur ein Entwurf kann angekündigt werden.');
        Einzugslaufstatus::pruefeWechsel((string) $lauf['status'], Einzugslaufstatus::ANGEKUENDIGT);

        $ci = $this->einstellungen->hole('glaeubiger_id');
        $faellig = $this->deutsch((string) $lauf['faelligkeitsdatum']);
        $email = 0;
        $post = 0;

        foreach ($this->laeufe->positionen($laufId) as $p) {
            if (((int) $p['kein_email_kontakt']) === 1 || trim((string) ($p['email'] ?? '')) === '') {
                $post++;
                continue;
            }
            $this->sendePreNotification($p, $faellig, $ci, (int) $lauf['id']);
            $email++;
        }

        $this->db->ausfuehren(
            "UPDATE einzugslauf SET status = 'angekuendigt', angekuendigt_am = :ang, anzahl_email = :e, anzahl_post = :p, updated_at = :upd WHERE id = :id",
            ['ang' => $this->jetzt(), 'upd' => $this->jetzt(), 'e' => $email, 'p' => $post, 'id' => $laufId],
        );
        $this->audit->protokolliere($benutzerId, 'einzugslauf_angekuendigt', 'einzugslauf', $laufId, ['email' => $email, 'post' => $post]);
    }

    /**
     * Mitglieder ohne E-Mail (für die postalische Ankündigung / AP5-Export).
     *
     * @return array<int,array<string,mixed>>
     */
    public function briefListe(int $laufId): array
    {
        return array_values(array_filter(
            $this->laeufe->positionen($laufId),
            static fn (array $p): bool => ((int) $p['kein_email_kontakt']) === 1 || trim((string) ($p['email'] ?? '')) === '',
        ));
    }

    /**
     * Erzeugt (oder liefert erneut) die pain.008-Datei. Beim ersten Mal werden die
     * Nebenwirkungen transaktional gesetzt; ein erneuter Aufruf liefert dieselbe Datei.
     *
     * @return array{xml:string,dateiname:string}
     */
    public function xmlErzeugen(int $laufId, ?int $benutzerId): array
    {
        $lauf = $this->laeufe->findePerId($laufId);
        if ($lauf === null) {
            throw new \RuntimeException('Einzugslauf nicht gefunden.');
        }

        // Bereits exportiert → gespeicherte Datei erneut liefern (keine Doppel-Erzeugung).
        if ((string) $lauf['status'] === Einzugslaufstatus::EXPORTIERT && $lauf['xml_pfad'] && is_file((string) $lauf['xml_pfad'])) {
            return ['xml' => (string) file_get_contents((string) $lauf['xml_pfad']), 'dateiname' => basename((string) $lauf['xml_pfad'])];
        }

        Einzugslaufstatus::pruefeWechsel((string) $lauf['status'], Einzugslaufstatus::EXPORTIERT);
        $this->pruefeVereinsdaten();

        $positionen = $this->laeufe->positionen($laufId);
        if ($positionen === []) {
            throw new \DomainException('Der Lauf enthält keine Positionen.');
        }

        $xml = $this->baueXml($lauf, $positionen);

        $fehler = $this->validator->fehler($xml);
        if ($fehler !== []) {
            throw new \RuntimeException('Das erzeugte XML ist nicht schemavalide: ' . implode('; ', array_slice($fehler, 0, 3)));
        }

        $jahr = substr((string) $lauf['faelligkeitsdatum'], 0, 4);
        $dateiname = "einzug-{$jahr}-{$laufId}.xml";
        $pfad = $this->speichere($dateiname, $xml);

        // Nebenwirkungen transaktional.
        $this->db->inTransaktion(function (Db $db) use ($laufId, $positionen, $lauf, $pfad): void {
            $faellig = (string) $lauf['faelligkeitsdatum'];
            foreach ($positionen as $p) {
                $db->ausfuehren(
                    "UPDATE forderung SET status = 'im_einzug', updated_at = :now WHERE id = :id",
                    ['now' => $this->jetzt(), 'id' => (int) $p['forderung_id']],
                );
                if ($p['mandat_id'] !== null) {
                    $db->ausfuehren(
                        'UPDATE mandat SET sequenz_genutzt = 1, zuletzt_genutzt_am = :faellig, updated_at = :now WHERE id = :id',
                        ['faellig' => $faellig, 'now' => $this->jetzt(), 'id' => (int) $p['mandat_id']],
                    );
                }
            }
            $db->ausfuehren(
                "UPDATE einzugslauf SET status = 'exportiert', xml_erzeugt_am = :erz, xml_pfad = :pfad, updated_at = :upd WHERE id = :id",
                ['erz' => $this->jetzt(), 'upd' => $this->jetzt(), 'pfad' => $pfad, 'id' => $laufId],
            );
        });

        $this->audit->protokolliere($benutzerId, 'einzugslauf_exportiert', 'einzugslauf', $laufId, ['datei' => $dateiname]);

        return ['xml' => $xml, 'dateiname' => $dateiname];
    }

    public function abschliessen(int $laufId, ?int $benutzerId): void
    {
        $lauf = $this->ladePruefe($laufId, Einzugslaufstatus::EXPORTIERT, 'Nur ein exportierter Lauf kann abgeschlossen werden.');
        $faellig = (string) $lauf['faelligkeitsdatum'];

        $this->db->inTransaktion(function (Db $db) use ($laufId, $faellig): void {
            $db->ausfuehren(
                "UPDATE forderung SET status = 'bezahlt', bezahlt_am = :faellig, zahlungsart = 'lastschrift', updated_at = :now
                  WHERE einzugslauf_id = :lauf AND status = 'im_einzug'",
                ['faellig' => $faellig, 'now' => $this->jetzt(), 'lauf' => $laufId],
            );
            $db->ausfuehren(
                "UPDATE einzugslauf SET status = 'abgeschlossen', abgeschlossen_am = :abg, updated_at = :upd WHERE id = :id",
                ['abg' => $this->jetzt(), 'upd' => $this->jetzt(), 'id' => $laufId],
            );
        });
        $this->audit->protokolliere($benutzerId, 'einzugslauf_abgeschlossen', 'einzugslauf', $laufId);
    }

    public function loeschen(int $laufId, ?int $benutzerId): void
    {
        $lauf = $this->laeufe->findePerId($laufId);
        if ($lauf === null) {
            throw new \RuntimeException('Einzugslauf nicht gefunden.');
        }
        if (!Einzugslaufstatus::darfGeloeschtWerden((string) $lauf['status'])) {
            throw new \DomainException('Ein exportierter oder abgeschlossener Lauf kann nicht gelöscht werden.');
        }
        // Forderungen freigeben (bleiben offen), dann den Lauf entfernen.
        $this->db->ausfuehren('UPDATE forderung SET einzugslauf_id = NULL, updated_at = :now WHERE einzugslauf_id = :id', ['now' => $this->jetzt(), 'id' => $laufId]);
        $this->db->ausfuehren('DELETE FROM einzugslauf WHERE id = :id', ['id' => $laufId]);
        $this->audit->protokolliere($benutzerId, 'einzugslauf_geloescht', 'einzugslauf', $laufId);
    }

    /**
     * Erfasst eine Rücklastschrift: Forderung wieder offen (aus dem Lauf gelöst),
     * optional Gebühr, optional Umstellung auf Selbstzahler (Mandat inaktiv).
     */
    public function ruecklastschriftErfassen(int $forderungId, bool $mitGebuehr, bool $aufSelbstzahler, ?int $benutzerId): void
    {
        $forderung = $this->forderungen->findePerId($forderungId);
        if ($forderung === null) {
            throw new \RuntimeException('Forderung nicht gefunden.');
        }
        $mitgliedId = (int) $forderung['mitglied_id'];

        // Wieder offen und aus dem Lauf lösen (erneut einziehbar). Vorfall via Audit dokumentiert.
        $this->db->ausfuehren(
            "UPDATE forderung SET status = 'offen', einzugslauf_id = NULL, bezahlt_am = NULL, zahlungsart = NULL, updated_at = :now WHERE id = :id",
            ['now' => $this->jetzt(), 'id' => $forderungId],
        );
        $this->audit->protokolliere($benutzerId, 'ruecklastschrift_erfasst', 'forderung', $forderungId);

        if ($mitGebuehr) {
            $gebuehr = $this->einstellungen->hole('ruecklastschrift_gebuehr', '0.00');
            if ((float) $gebuehr > 0) {
                $this->sollstellung->gebuehrAnlegen($mitgliedId, $gebuehr, (int) $forderung['jahr'], $benutzerId);
            }
        }
        if ($aufSelbstzahler) {
            $this->mandate->umstellenAufSelbstzahler($mitgliedId, $benutzerId);
        }
    }

    // ---- intern ----------------------------------------------------------

    /**
     * @param array<string,mixed> $lauf
     * @param array<int,array<string,mixed>> $positionen
     */
    private function baueXml(array $lauf, array $positionen): string
    {
        $vereinName = $this->einstellungen->hole('verein_name');
        $vereinIban = $this->krypto->entschluesseln($this->einstellungen->hole('verein_iban'));
        $vereinBic = $this->einstellungen->hole('verein_bic');
        $ci = $this->einstellungen->hole('glaeubiger_id');
        $faellig = (string) $lauf['faelligkeitsdatum'];

        $msgId = 'FGH-' . $lauf['id'] . '-' . date('YmdHis');
        $sephpa = new SephpaDirectDebit($vereinName, $msgId, SephpaDirectDebit::SEPA_PAIN_008_001_02);

        // Positionen nach Sequenztyp gruppieren (FRST/RCUR).
        $gruppen = ['FRST' => [], 'RCUR' => []];
        foreach ($positionen as $p) {
            $seq = ((int) $p['sequenz_genutzt']) === 1 ? 'RCUR' : 'FRST';
            $gruppen[$seq][] = $p;
        }

        foreach ($gruppen as $seqTp => $gruppe) {
            if ($gruppe === []) {
                continue;
            }
            $collectionInfo = [
                'pmtInfId'     => 'L' . $lauf['id'] . '-' . $seqTp,
                'lclInstrm'    => SepaUtilities::LOCAL_INSTRUMENT_CORE_DIRECT_DEBIT,
                'seqTp'        => $seqTp === 'FRST' ? SepaUtilities::SEQUENCE_TYPE_FIRST : SepaUtilities::SEQUENCE_TYPE_RECURRING,
                'cdtr'         => $vereinName,
                'iban'         => $vereinIban,
                'ci'           => $ci,
                'reqdColltnDt' => $faellig,
            ];
            if ($vereinBic !== '') {
                $collectionInfo['bic'] = $vereinBic;
            }
            $collection = $sephpa->addCollection($collectionInfo);

            foreach ($gruppe as $p) {
                $iban = $this->krypto->entschluesseln((string) $p['iban_verschluesselt']);
                $zweck = sprintf(
                    'Mitgliedsbeitrag %s Foerderverein Gymnasium Herzogenrath, Mitglied %s',
                    $p['jahr'],
                    $p['mitgliedsnummer'] ?? '',
                );
                $payment = [
                    'pmtId'      => 'L' . $lauf['id'] . '-F' . $p['forderung_id'],
                    'instdAmt'   => (float) $p['betrag'],
                    'mndtId'     => $p['mandatsreferenz'],
                    'dtOfSgntr'  => $p['erteilt_am'] ?: $faellig,
                    'dbtr'       => $p['kontoinhaber'],
                    'iban'       => $iban,
                    'rmtInf'     => $zweck,
                ];
                if (!empty($p['bic'])) {
                    $payment['bic'] = $p['bic'];
                }
                $collection->addPayment($payment);
            }
        }

        return $sephpa->generateXml();
    }

    /**
     * @param array<string,mixed> $p
     */
    private function sendePreNotification(array $p, string $faellig, string $ci, int $laufId): void
    {
        $anrede = $this->anrede->briefanrede([
            'anrede'   => $p['anrede'],
            'nachname' => $p['nachname'],
        ]);
        $betrag = number_format((float) $p['betrag'], 2, ',', '.');
        $iban = $this->maskiere($p);
        $text = "{$anrede},\n\n"
            . "wir kündigen den Einzug Ihres Mitgliedsbeitrags per SEPA-Lastschrift an:\n\n"
            . "Betrag: {$betrag} €\n"
            . "Fälligkeit: {$faellig}\n"
            . "Mandatsreferenz: {$p['mandatsreferenz']}\n"
            . "Gläubiger-Identifikationsnummer: {$ci}\n"
            . "IBAN: {$iban}\n\n"
            . "Bitte sorgen Sie für ausreichende Deckung.\n\n"
            . "Mit freundlichen Grüßen\nFörderverein Gymnasium Herzogenrath";

        // Template-Schlüssel „prenotification" folgt in AP4; bis dahin Fixtext.
        $this->mail->einreihen((string) $p['email'], 'Ankündigung SEPA-Lastschrift', $text, mitgliedId: (int) $p['mitglied_id']);
    }

    private function summenAktualisieren(int $laufId): void
    {
        $cent = 0;
        $anzahl = 0;
        foreach ($this->laeufe->positionen($laufId) as $p) {
            $cent += $this->cents((string) $p['betrag']);
            $anzahl++;
        }
        $this->db->ausfuehren(
            'UPDATE einzugslauf SET summe = :summe, anzahl = :anzahl, updated_at = :now WHERE id = :id',
            ['summe' => $this->euro($cent), 'anzahl' => $anzahl, 'now' => $this->jetzt(), 'id' => $laufId],
        );
    }

    private function pruefeVereinsdaten(): void
    {
        if (trim($this->einstellungen->hole('glaeubiger_id')) === '' || trim($this->einstellungen->hole('verein_iban')) === '' || trim($this->einstellungen->hole('verein_name')) === '') {
            throw new \DomainException('Bitte zuerst Vereinsname, Gläubiger-Identifikationsnummer und Vereins-IBAN in den Einstellungen hinterlegen.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function ladePruefe(int $laufId, string $erwarteterStatus, string $fehlermeldung): array
    {
        $lauf = $this->laeufe->findePerId($laufId);
        if ($lauf === null) {
            throw new \RuntimeException('Einzugslauf nicht gefunden.');
        }
        if ((string) $lauf['status'] !== $erwarteterStatus) {
            throw new \DomainException($fehlermeldung);
        }

        return $lauf;
    }

    private function speichere(string $dateiname, string $xml): string
    {
        $verzeichnis = $this->basisPfad . '/var/sepa';
        if (!is_dir($verzeichnis)) {
            @mkdir($verzeichnis, 0770, true);
        }
        $pfad = $verzeichnis . '/' . $dateiname;
        file_put_contents($pfad, $xml);

        return $pfad;
    }

    /**
     * @param array<string,mixed> $p
     */
    private function maskiere(array $p): string
    {
        if (empty($p['iban_verschluesselt'])) {
            return '—';
        }
        try {
            return $this->krypto->maskiereIban($this->krypto->entschluesseln((string) $p['iban_verschluesselt']));
        } catch (\Throwable) {
            return '…';
        }
    }

    private function cents(string $betrag): int
    {
        return (int) round(((float) $betrag) * 100);
    }

    private function euro(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function deutsch(string $iso): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));

        return $d instanceof \DateTimeImmutable ? $d->format('d.m.Y') : $iso;
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
