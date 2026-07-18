<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Mitgliedsstatus;
use App\Repository\AntragRepository;
use App\Repository\MitgliedRepository;
use App\Support\Db;

/**
 * Fachlogik rund um Mitglieder: Stammdaten- und Beitragsänderung sowie die
 * Statusaktionen des Lebenszyklus (§3.1). Jede Änderung läuft über den
 * Versionierungs-Service (CLAUDE.md Regel 2); Mails nur über die Queue,
 * protokolliert im Audit-Log. Nummernvergabe ist kollisionssicher (ab 2000).
 */
final class MitgliedService
{
    private const NUMMERNKREIS_START = 2000;

    /** Whitelist bearbeitbarer Stammdatenfelder. */
    private const STAMMFELDER = [
        'anrede', 'vorname', 'nachname', 'briefanrede_manuell', 'adresszeile_manuell',
        'strasse', 'plz', 'ort', 'land', 'email', 'kein_email_kontakt', 'telefon',
        'zahlweise', 'notizen',
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Versionierung $versionierung,
        private readonly MitgliedRepository $mitglieder,
        private readonly AntragRepository $antraege,
        private readonly MailDienst $mail,
        private readonly AnredeDienst $anrede,
        private readonly Audit $audit,
        private readonly MandatService $mandate,
        private readonly SollstellungService $sollstellung,
        private readonly VorlagenService $vorlagen,
    ) {
    }

    /**
     * Legt ein Mitglied von Hand an (Papierantrag, telefonische Meldung): Status
     * `beantragt`, ohne Double-Opt-In — die Daten hat der Vorstand selbst erfasst.
     * Die Aktivierung läuft danach über den regulären Weg (aktivieren()).
     *
     * @param array<string,mixed> $felder  nur Whitelist-Felder (STAMMFELDER)
     * @return int die neue Mitglieds-ID
     */
    public function anlegen(array $felder, string $jahresbeitrag, int $benutzerId): int
    {
        $daten = array_intersect_key($felder, array_flip(self::STAMMFELDER));
        if (trim((string) ($daten['nachname'] ?? '')) === '') {
            throw new \InvalidArgumentException('Ein Mitglied braucht mindestens einen Nachnamen.');
        }
        $daten['status'] = Mitgliedsstatus::BEANTRAGT;
        $daten['jahresbeitrag'] = number_format((float) str_replace(',', '.', $jahresbeitrag), 2, '.', '');

        $id = $this->mitglieder->anlegen($daten);
        $this->audit->protokolliere($benutzerId, 'mitglied_angelegt', 'mitglied', $id, ['quelle' => 'manuell']);

        return $id;
    }

    /**
     * Ändert Stammdaten (nur Whitelist-Felder) versioniert.
     *
     * @param array<string,mixed> $felder
     * @return array{version_id:string,geaenderte_felder:array<int,string>}
     */
    public function stammdatenAendern(int $id, array $felder, int $benutzerId): array
    {
        $zu = array_intersect_key($felder, array_flip(self::STAMMFELDER));
        if ($zu === []) {
            throw new \InvalidArgumentException('Keine gültigen Felder zum Ändern.');
        }

        $ergebnis = $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $zu): void {
            $this->update($db, $id, $zu);
        });
        $this->audit->protokolliere($benutzerId, 'mitglied_geaendert', 'mitglied', $id, ['felder' => $ergebnis['geaenderte_felder']]);

        return $ergebnis;
    }

    public function beitragAendern(int $id, string $neuerBeitrag, int $benutzerId): array
    {
        $normal = number_format((float) str_replace(',', '.', $neuerBeitrag), 2, '.', '');

        $ergebnis = $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $normal): void {
            $this->update($db, $id, ['jahresbeitrag' => $normal]);
        });
        $this->audit->protokolliere($benutzerId, 'beitrag_geaendert', 'mitglied', $id, ['neu' => $normal]);

        return $ergebnis;
    }

    /**
     * Setzt einen Revert auf eine frühere Version um (löst keine Mails/Sollstellungen aus).
     *
     * @param array<int,string>|null $nurFelder
     */
    public function revert(int $id, int $versionId, int $benutzerId, ?array $nurFelder = null): array
    {
        $ergebnis = $this->versionierung->revert('mitglied', $id, $versionId, $benutzerId, $nurFelder);
        $this->audit->protokolliere($benutzerId, 'mitglied_revert', 'mitglied', $id, ['version' => $versionId]);

        return $ergebnis;
    }

    /**
     * Aktiviert ein beantragtes Mitglied: vergibt die nächste Mitgliedsnummer,
     * setzt Eintrittsdatum und reiht die Begrüßungsmail ein.
     *
     * @return int die vergebene Mitgliedsnummer
     */
    public function aktivieren(int $id, int $benutzerId, ?string $eintrittsdatum = null): int
    {
        $mitglied = $this->ladePruefe($id, Mitgliedsstatus::AKTIV);
        $eintritt = $eintrittsdatum ?: $this->heute();
        $nummer = 0;

        $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $eintritt, &$nummer): void {
            $nummer = $this->vergebeNummer($db, $id);
            $this->update($db, $id, [
                'status'         => Mitgliedsstatus::AKTIV,
                'mitgliedsnummer' => $nummer,
                'eintrittsdatum' => $eintritt,
            ]);
        });

        // Hooks (AP2): Mandat aus dem Antrag anlegen und Beitragsforderung fürs
        // laufende Jahr erzeugen (Q1: voller Jahresbeitrag im Eintrittsjahr).
        $this->mandate->ausAntragErstellen($id, $nummer, $benutzerId);
        $this->sollstellung->einzelsollstellung($id, (string) $mitglied['jahresbeitrag'], $benutzerId);

        // $mitglied ist der Stand VOR der Aktivierung — Nummer und Eintrittsdatum
        // nachtragen, sonst bleibt {{mitgliedsnummer}} in der Begrüßungsmail leer.
        $this->begruessung(array_merge($mitglied, [
            'mitgliedsnummer' => $nummer,
            'eintrittsdatum'  => $eintritt,
        ]));
        $this->audit->protokolliere($benutzerId, 'mitglied_aktiviert', 'mitglied', $id, ['nummer' => $nummer]);

        return $nummer;
    }

    public function ablehnen(int $id, int $benutzerId): void
    {
        $this->ladePruefe($id, Mitgliedsstatus::ABGELEHNT);
        $this->statusSetzen($id, [Mitgliedsstatus::ABGELEHNT], $benutzerId);
        $this->audit->protokolliere($benutzerId, 'antrag_abgelehnt', 'mitglied', $id);
    }

    public function kuendigen(int $id, int $benutzerId, ?string $kuendigungAm = null, ?string $wirksamZum = null): void
    {
        $mitglied = $this->ladePruefe($id, Mitgliedsstatus::GEKUENDIGT);
        $kuendigungAm = $kuendigungAm ?: $this->heute();
        $wirksamZum = $wirksamZum ?: (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y') . '-12-31';

        $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $kuendigungAm, $wirksamZum): void {
            $this->update($db, $id, [
                'status'        => Mitgliedsstatus::GEKUENDIGT,
                'kuendigung_am' => $kuendigungAm,
                'wirksam_zum'   => $wirksamZum,
            ]);
        });

        $this->kuendigungsbestaetigung($mitglied, $wirksamZum);
        $this->audit->protokolliere($benutzerId, 'kuendigung_erfasst', 'mitglied', $id, ['wirksam_zum' => $wirksamZum]);
    }

    public function kuendigungWiderrufen(int $id, int $benutzerId): void
    {
        $this->ladePruefe($id, Mitgliedsstatus::AKTIV);
        $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id): void {
            $this->update($db, $id, [
                'status'        => Mitgliedsstatus::AKTIV,
                'kuendigung_am' => null,
                'wirksam_zum'   => null,
            ]);
        });
        $this->audit->protokolliere($benutzerId, 'kuendigung_widerrufen', 'mitglied', $id);
    }

    public function austrittVollziehen(int $id, int $benutzerId): void
    {
        $mitglied = $this->ladePruefe($id, Mitgliedsstatus::AUSGESCHIEDEN);
        $austritt = $mitglied['wirksam_zum'] ?: $this->heute();

        $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $austritt): void {
            $this->update($db, $id, [
                'status'         => Mitgliedsstatus::AUSGESCHIEDEN,
                'austrittsdatum' => $austritt,
            ]);
        });
        $this->audit->protokolliere($benutzerId, 'austritt_vollzogen', 'mitglied', $id);
    }

    /**
     * Wartung: unbestätigte Anträge älter als $tage verwerfen und Token entwerten.
     *
     * @return int Anzahl verworfener Anträge
     */
    public function verwerfeUnbestaetigte(int $tage = 30): int
    {
        $grenze = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify("-{$tage} days")->format('Y-m-d H:i:s');

        $zeilen = $this->db->alleZeilen(
            "SELECT id FROM mitglied WHERE status = 'unbestaetigt' AND created_at < :grenze",
            ['grenze' => $grenze],
        );

        $anzahl = 0;
        foreach ($zeilen as $zeile) {
            $id = (int) $zeile['id'];
            $this->versionierung->mitSnapshot('mitglied', $id, null, function (Db $db) use ($id): void {
                $this->update($db, $id, ['status' => Mitgliedsstatus::VERWORFEN]);
            });
            $this->antraege->tokenLoeschen($id);
            $this->audit->protokolliere(null, 'antrag_verworfen', 'mitglied', $id);
            $anzahl++;
        }

        return $anzahl;
    }

    // ---- intern ----------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function ladePruefe(int $id, string $zielStatus): array
    {
        $mitglied = $this->mitglieder->findePerId($id);
        if ($mitglied === null) {
            throw new \RuntimeException("Mitglied #{$id} nicht gefunden.");
        }
        Mitgliedsstatus::pruefeWechsel((string) $mitglied['status'], $zielStatus);

        return $mitglied;
    }

    /**
     * @param array<int,string> $erlaubteZiele  ungenutzt; Wechsel wurde bereits geprüft
     */
    private function statusSetzen(int $id, array $erlaubteZiele, int $benutzerId): void
    {
        $status = $erlaubteZiele[0];
        $this->versionierung->mitSnapshot('mitglied', $id, $benutzerId, function (Db $db) use ($id, $status): void {
            $this->update($db, $id, ['status' => $status]);
        });
    }

    /**
     * Vergibt die nächste freie Mitgliedsnummer (>= 2000), kollisionssicher durch
     * UNIQUE-Constraint + Wiederholung bei paralleler Vergabe.
     */
    private function vergebeNummer(Db $db, int $id): int
    {
        for ($versuch = 0; $versuch < 10; $versuch++) {
            $max = (int) $db->einWert('SELECT COALESCE(MAX(mitgliedsnummer), 0) FROM mitglied');
            $kandidat = max($max + 1, self::NUMMERNKREIS_START);
            try {
                $db->ausfuehren('UPDATE mitglied SET mitgliedsnummer = :nr WHERE id = :id', ['nr' => $kandidat, 'id' => $id]);

                return $kandidat;
            } catch (\PDOException $e) {
                // UNIQUE-Verletzung durch parallele Vergabe → erneut versuchen.
                continue;
            }
        }

        throw new \RuntimeException('Mitgliedsnummer konnte nicht vergeben werden (zu viele Kollisionen).');
    }

    /**
     * @param array<string,mixed> $felder
     */
    private function update(Db $db, int $id, array $felder): void
    {
        $felder['updated_at'] = $this->jetzt();
        $sets = [];
        $params = ['id' => $id];
        foreach ($felder as $spalte => $wert) {
            $sets[] = "{$spalte} = :{$spalte}";
            $params[$spalte] = $wert;
        }
        $db->ausfuehren('UPDATE mitglied SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    private function begruessung(array $mitglied): void
    {
        if (!$this->hatEmail($mitglied)) {
            return;
        }
        $mail = $this->vorlagen->rendere('begruessung', $this->vorlagen->kontext($mitglied));
        $this->mail->einreihen((string) $mitglied['email'], $mail['betreff'], $mail['text'], $mail['html'], mitgliedId: (int) $mitglied['id']);
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    private function kuendigungsbestaetigung(array $mitglied, string $wirksamZum): void
    {
        if (!$this->hatEmail($mitglied)) {
            return;
        }
        $kontext = $this->vorlagen->kontext($mitglied, ['faelligkeitsdatum' => $this->datumDeutsch($wirksamZum)]);
        $mail = $this->vorlagen->rendere('kuendigungsbestaetigung', $kontext);
        $this->mail->einreihen((string) $mitglied['email'], $mail['betreff'], $mail['text'], $mail['html'], mitgliedId: (int) $mitglied['id']);
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    private function hatEmail(array $mitglied): bool
    {
        return (int) ($mitglied['kein_email_kontakt'] ?? 0) !== 1
            && trim((string) ($mitglied['email'] ?? '')) !== '';
    }

    private function datumDeutsch(string $iso): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));

        return $d instanceof \DateTimeImmutable ? $d->format('d.m.Y') : $iso;
    }

    private function heute(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
