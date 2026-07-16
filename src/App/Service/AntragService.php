<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Mitgliedsstatus;
use App\Repository\AntragRepository;
use App\Repository\MitgliedRepository;
use App\Support\Db;

/**
 * Antragseingang (F2) — portiert aus der bestehenden Anmelde-App:
 * Formular ⇒ Mitglied `unbestaetigt` + antrag_rohdaten (Payload als
 * Mandatsnachweis), Double-Opt-In mit POST-Bestätigung, Warteseite/Resend.
 *
 * Unterschiede zur Altapp: IBAN verschlüsselt (im Payload maskiert), Mailversand
 * über die zentrale Queue (Priorität „sofort"), IBAN-Prüfung für alle SEPA-Länder,
 * Konfiguration aus .env. IBAN erscheint nie im Klartext (DB/Payload/Logs).
 */
final class AntragService
{
    public function __construct(
        private readonly Db $db,
        private readonly MitgliedRepository $mitglieder,
        private readonly AntragRepository $antraege,
        private readonly Krypto $krypto,
        private readonly MailDienst $mail,
        private readonly Versionierung $versionierung,
        private readonly Audit $audit,
        private readonly string $appUrl,
        private readonly string $pepper,
    ) {
    }

    public function ipHash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip . '|' . $this->pepper);
    }

    public function anzahlProIp(?string $ipHash, int $stunden = 1): int
    {
        return $ipHash === null ? 0 : $this->antraege->anzahlProIpSeit($ipHash, $stunden);
    }

    /**
     * Nimmt einen validierten Antrag an: legt Mitglied (unbestätigt) + Rohdaten an
     * und reiht die DOI-Mail ein. Erwartet bereits geprüfte Eingaben inkl. IBAN.
     *
     * @param array<string,mixed> $eingabe
     * @return string das Bestätigungstoken
     */
    public function einreichen(array $eingabe, ?string $ipHash): string
    {
        $ibanNormal = (string) $eingabe['iban'];
        $ibanVerschluesselt = $this->krypto->verschluesseln($ibanNormal);
        $ibanMaskiert = $this->krypto->maskiereIban($ibanNormal);

        $mitgliedId = $this->mitglieder->anlegen([
            'status'        => Mitgliedsstatus::UNBESTAETIGT,
            'anrede'        => $eingabe['anrede'],
            'vorname'       => $eingabe['vorname'] ?: null,
            'nachname'      => $eingabe['nachname'],
            'strasse'       => $eingabe['strasse'],
            'plz'           => $eingabe['plz'],
            'ort'           => $eingabe['ort'],
            'land'          => $eingabe['land'],
            'email'         => $eingabe['email'],
            'telefon'       => $eingabe['telefon'] ?: null,
            'jahresbeitrag' => $eingabe['jahresbeitrag'],
            'zahlweise'     => 'lastschrift',
        ]);

        $token = bin2hex(random_bytes(32));

        // Payload = Mandatsnachweis. IBAN nur verschlüsselt + maskiert, nie im Klartext.
        $payload = [
            'anrede'             => $eingabe['anrede'],
            'vorname'            => $eingabe['vorname'],
            'nachname'           => $eingabe['nachname'],
            'strasse'            => $eingabe['strasse'],
            'plz'                => $eingabe['plz'],
            'ort'                => $eingabe['ort'],
            'land'               => $eingabe['land'],
            'email'              => $eingabe['email'],
            'telefon'            => $eingabe['telefon'],
            'jahresbeitrag'      => $eingabe['jahresbeitrag'],
            'kontoinhaber'       => $eingabe['kontoinhaber'] ?? $eingabe['nachname'],
            'iban_verschluesselt' => $ibanVerschluesselt,
            'iban_maskiert'      => $ibanMaskiert,
            'mandat_zustimmung'  => true,
            'eingereicht_am'     => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('c'),
        ];

        $this->antraege->anlegen($mitgliedId, $payload, $ipHash, $token);
        $this->sendeDoiMail((string) $eingabe['email'], $token, $ibanMaskiert, (string) $eingabe['nachname']);
        $this->audit->protokolliere(null, 'antrag_eingegangen', 'mitglied', $mitgliedId);

        return $token;
    }

    /**
     * Bestätigt einen Antrag (nur per POST aufzurufen): unbestätigt ⇒ beantragt.
     * Idempotent.
     *
     * @return string 'ok' | 'schon' | 'unbekannt'
     */
    public function bestaetige(string $token): string
    {
        $antrag = $this->antraege->findePerToken($token);
        if ($antrag === null || $antrag['mitglied_id'] === null) {
            return 'unbekannt';
        }
        if ($antrag['bestaetigt_am'] !== null) {
            return 'schon';
        }

        $mitgliedId = (int) $antrag['mitglied_id'];
        $mitglied = $this->mitglieder->findePerId($mitgliedId);
        if ($mitglied === null) {
            return 'unbekannt';
        }
        if ($mitglied['status'] !== Mitgliedsstatus::UNBESTAETIGT) {
            return 'schon';
        }

        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $this->versionierung->mitSnapshot('mitglied', $mitgliedId, null, function (Db $db) use ($mitgliedId, $jetzt): void {
            $db->ausfuehren(
                'UPDATE mitglied SET status = :s, bestaetigt_am = :b, updated_at = :b WHERE id = :id',
                ['s' => Mitgliedsstatus::BEANTRAGT, 'b' => $jetzt, 'id' => $mitgliedId],
            );
        });
        $this->antraege->markiereBestaetigt((int) $antrag['id']);
        $this->audit->protokolliere(null, 'antrag_bestaetigt', 'mitglied', $mitgliedId);

        return 'ok';
    }

    /**
     * Sendet die DOI-Mail erneut, sofern noch unbestätigt.
     */
    public function erneutSenden(string $token): bool
    {
        $antrag = $this->antraege->findePerToken($token);
        if ($antrag === null || $antrag['bestaetigt_am'] !== null || $antrag['mitglied_id'] === null) {
            return false;
        }
        $mitglied = $this->mitglieder->findePerId((int) $antrag['mitglied_id']);
        if ($mitglied === null || $mitglied['status'] !== Mitgliedsstatus::UNBESTAETIGT) {
            return false;
        }

        $payload = json_decode((string) $antrag['payload'], true) ?: [];
        $this->sendeDoiMail((string) $mitglied['email'], $token, (string) ($payload['iban_maskiert'] ?? ''), (string) $mitglied['nachname']);

        return true;
    }

    private function sendeDoiMail(string $empfaenger, string $token, string $ibanMaskiert, string $nachname): void
    {
        $link = rtrim($this->appUrl, '/') . '/antrag/bestaetigen?token=' . $token;

        $text = "Guten Tag,\n\n"
            . "vielen Dank für Ihren Aufnahmeantrag beim Förderverein Gymnasium Herzogenrath.\n\n"
            . "Bitte bestätigen Sie Ihre E-Mail-Adresse und Ihren Antrag, indem Sie die folgende Seite öffnen "
            . "und dort auf »Jetzt bestätigen« klicken:\n\n"
            . $link . "\n\n"
            . "SEPA-Lastschriftmandat\n"
            . "----------------------\n"
            . "Mit der Bestätigung ermächtigen Sie den Förderverein Gymnasium Herzogenrath, den Mitgliedsbeitrag "
            . "per SEPA-Lastschrift von Ihrem Konto einzuziehen (IBAN {$ibanMaskiert}, Kontoinhaber {$nachname}). "
            . "Zugleich weisen Sie Ihr Kreditinstitut an, die Lastschriften einzulösen. Sie können innerhalb von "
            . "acht Wochen ab Belastung die Erstattung verlangen; es gelten die mit Ihrer Bank vereinbarten Bedingungen.\n\n"
            . "Wenn Sie diesen Antrag nicht gestellt haben, ignorieren Sie diese E-Mail einfach.\n\n"
            . "Mit freundlichen Grüßen\nFörderverein Gymnasium Herzogenrath";

        $this->mail->einreihen($empfaenger, 'Bitte bestätigen Sie Ihren Aufnahmeantrag', $text, prioritaet: MailDienst::PRIO_SOFORT);
    }
}
