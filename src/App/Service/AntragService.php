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
 * Wie in der Altapp gibt es zwei Tokens: das Bestätigungstoken steht nur in der
 * DOI-Mail, die Warteseite läuft über ein eigenes Resend-Token — sonst läge der
 * Bestätigungslink in Historie und Referrer der Warteseite.
 *
 * Unterschiede zur Altapp: IBAN verschlüsselt (im Payload maskiert), Mailversand
 * über die zentrale Queue (Priorität „sofort"), IBAN-Prüfung für alle SEPA-Länder,
 * Konfiguration aus .env. IBAN erscheint nie im Klartext (DB/Payload/Logs).
 */
final class AntragService
{
    /** Sperrfrist fürs Nachsenden der DOI-Mail (Altapp: 2 Minuten). */
    public const RESEND_SPERRE_SEKUNDEN = 120;

    public function __construct(
        private readonly Db $db,
        private readonly MitgliedRepository $mitglieder,
        private readonly AntragRepository $antraege,
        private readonly Krypto $krypto,
        private readonly MailDienst $mail,
        private readonly Versionierung $versionierung,
        private readonly Audit $audit,
        private readonly Einstellungen $einstellungen,
        private readonly string $appUrl,
        private readonly string $pepper,
    ) {
    }

    /**
     * Gläubiger-ID des Vereins (Einstellung, gepflegt unter /einstellungen).
     * Derselbe Wert, den der SEPA-Export nutzt.
     */
    public function glaeubigerId(): string
    {
        return trim($this->einstellungen->hole('glaeubiger_id', ''));
    }

    public function vereinName(): string
    {
        return trim($this->einstellungen->hole('verein_name', ''))
            ?: 'Verein der Freunde und Förderer des Städtischen Gymnasiums Herzogenrath e.V.';
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
     * @return string das Resend-Token für die Warteseite (das Bestätigungstoken
     *                geht ausschließlich per Mail an die Antragstellerin)
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
            'jahresbeitrag' => $eingabe['jahresbeitrag'],
            'zahlweise'     => 'lastschrift',
        ]);

        $token = bin2hex(random_bytes(32));
        $resendToken = $this->resendToken();

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
            'jahresbeitrag'      => $eingabe['jahresbeitrag'],
            'kontoinhaber'       => $eingabe['kontoinhaber'] ?? $eingabe['nachname'],
            'iban_verschluesselt' => $ibanVerschluesselt,
            'iban_maskiert'      => $ibanMaskiert,
            'mandat_zustimmung'  => true,
            'datenschutz_zustimmung' => true,
            'eingereicht_am'     => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('c'),
        ];

        $this->antraege->anlegen($mitgliedId, $payload, $ipHash, $token, $resendToken);
        $this->sendeDoiMail(
            (string) $eingabe['email'],
            $token,
            $ibanMaskiert,
            (string) ($eingabe['kontoinhaber'] ?? $eingabe['nachname']),
            (string) $eingabe['jahresbeitrag'],
        );
        $this->audit->protokolliere(null, 'antrag_eingegangen', 'mitglied', $mitgliedId);

        return $resendToken;
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
            // Getrennte Platzhalter für denselben Wert: MariaDB läuft mit nativen
            // Prepares (Db: ATTR_EMULATE_PREPARES=false) und weist einen doppelt
            // verwendeten benannten Parameter mit HY093 ab.
            $db->ausfuehren(
                'UPDATE mitglied SET status = :s, bestaetigt_am = :b, updated_at = :u WHERE id = :id',
                ['s' => Mitgliedsstatus::BEANTRAGT, 'b' => $jetzt, 'u' => $jetzt, 'id' => $mitgliedId],
            );
        });
        $this->antraege->markiereBestaetigt((int) $antrag['id']);
        $this->audit->protokolliere(null, 'antrag_bestaetigt', 'mitglied', $mitgliedId);

        return 'ok';
    }

    /**
     * Status der Warteseite zu einem Resend-Token.
     *
     * @return array{zustand:string,wartet_sekunden:int}
     *         zustand: 'offen' | 'bestaetigt' | 'unbekannt'
     */
    public function warteStatus(string $resendToken): array
    {
        $antrag = $this->antraege->findePerResendToken($resendToken);
        if ($antrag === null || $antrag['mitglied_id'] === null) {
            return ['zustand' => 'unbekannt', 'wartet_sekunden' => 0];
        }
        if ($antrag['bestaetigt_am'] !== null) {
            return ['zustand' => 'bestaetigt', 'wartet_sekunden' => 0];
        }

        return ['zustand' => 'offen', 'wartet_sekunden' => $this->restSperre($antrag)];
    }

    /**
     * Sendet die DOI-Mail erneut, sofern noch unbestätigt und die Sperrfrist
     * abgelaufen ist. Angesteuert über das Resend-Token der Warteseite.
     *
     * @return string 'ok' | 'gesperrt' | 'schon' | 'unbekannt'
     */
    public function erneutSenden(string $resendToken): string
    {
        $antrag = $this->antraege->findePerResendToken($resendToken);
        if ($antrag === null || $antrag['mitglied_id'] === null) {
            return 'unbekannt';
        }
        if ($antrag['bestaetigt_am'] !== null) {
            return 'schon';
        }

        $mitglied = $this->mitglieder->findePerId((int) $antrag['mitglied_id']);
        if ($mitglied === null || $mitglied['status'] !== Mitgliedsstatus::UNBESTAETIGT) {
            return 'schon';
        }
        if ($this->restSperre($antrag) > 0) {
            return 'gesperrt';
        }

        $payload = json_decode((string) $antrag['payload'], true) ?: [];
        $this->sendeDoiMail(
            (string) $mitglied['email'],
            (string) $antrag['bestaetigungs_token'],
            (string) ($payload['iban_maskiert'] ?? ''),
            (string) ($payload['kontoinhaber'] ?? $mitglied['nachname']),
            (string) $mitglied['jahresbeitrag'],
        );
        $this->antraege->markiereErneutGesendet((int) $antrag['id']);

        return 'ok';
    }

    /**
     * Verbleibende Sperrsekunden, gerechnet ab dem letzten Versand.
     *
     * @param array<string,mixed> $antrag
     */
    private function restSperre(array $antrag): int
    {
        $letzter = (string) ($antrag['erneut_gesendet_am'] ?? '') ?: (string) $antrag['eingegangen_am'];
        $zone = new \DateTimeZone('Europe/Berlin');
        $seit = (new \DateTimeImmutable('now', $zone))->getTimestamp()
            - (new \DateTimeImmutable($letzter, $zone))->getTimestamp();

        return (int) max(0, self::RESEND_SPERRE_SEKUNDEN - $seit);
    }

    /**
     * UUID-artiges Token für die Warteseite (Format wie in der Altapp).
     */
    private function resendToken(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6)),
        );
    }

    private function sendeDoiMail(
        string $empfaenger,
        string $token,
        string $ibanMaskiert,
        string $kontoinhaber,
        string $jahresbeitrag,
    ): void {
        $link = rtrim($this->appUrl, '/') . '/antrag/bestaetigen?token=' . $token;
        $betrag = number_format((float) $jahresbeitrag, 2, ',', '.') . ' € pro Jahr';
        $verein = $this->vereinName();
        $gid = $this->glaeubigerId();
        $gidText = $gid !== '' ? " (Gläubiger-Identifikationsnummer {$gid})" : '';

        $text = "Guten Tag,\n\n"
            . "vielen Dank für Ihren Aufnahmeantrag beim {$verein}\n\n"
            . "Bitte bestätigen Sie Ihre E-Mail-Adresse und Ihren Antrag, indem Sie die folgende Seite öffnen "
            . "und dort auf »Jetzt bestätigen« klicken:\n\n"
            . $link . "\n\n"
            . "Ihr Mitgliedsbeitrag: {$betrag}\n\n"
            . "SEPA-Lastschriftmandat\n"
            . "----------------------\n"
            . "Mit der Bestätigung ermächtigen Sie den {$verein}{$gidText}, den Mitgliedsbeitrag "
            . "wiederkehrend per SEPA-Lastschrift von Ihrem Konto einzuziehen (IBAN {$ibanMaskiert}, Kontoinhaber "
            . "{$kontoinhaber}). Zugleich weisen Sie Ihr Kreditinstitut an, die Lastschriften einzulösen. "
            . "Ihre Mandatsreferenz teilen wir Ihnen nach der Aufnahme mit.\n\n"
            . "Vor jedem Einzug informieren wir Sie mindestens 14 Kalendertage vorher per E-Mail über Termin und "
            . "Betrag. Sie können innerhalb von acht Wochen ab Belastung die Erstattung verlangen; es gelten die "
            . "mit Ihrer Bank vereinbarten Bedingungen.\n\n"
            . "Wenn Sie diesen Antrag nicht gestellt haben, ignorieren Sie diese E-Mail einfach.\n\n"
            . "Mit freundlichen Grüßen\nIhr Vorstand des Fördervereins\n"
            . "https://www.gymnasium-herzogenrath.de/foerderverein";

        $this->mail->einreihen(
            $empfaenger,
            'Bitte bestätigen Sie Ihren Aufnahmeantrag',
            $text,
            $this->doiMailHtml($link, $betrag, $ibanMaskiert, $kontoinhaber),
            prioritaet: MailDienst::PRIO_SOFORT,
        );
    }

    /**
     * HTML-Variante der DOI-Mail (Aufmachung aus der Altapp: Button + Klartext-Link
     * als Rückfallebene). Alle eingesetzten Werte werden escaped.
     */
    private function doiMailHtml(string $link, string $betrag, string $ibanMaskiert, string $kontoinhaber): string
    {
        $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $b = htmlspecialchars($betrag, ENT_QUOTES, 'UTF-8');
        $i = htmlspecialchars($ibanMaskiert, ENT_QUOTES, 'UTF-8');
        $k = htmlspecialchars($kontoinhaber, ENT_QUOTES, 'UTF-8');
        $v = htmlspecialchars($this->vereinName(), ENT_QUOTES, 'UTF-8');
        $gid = htmlspecialchars($this->glaeubigerId(), ENT_QUOTES, 'UTF-8');
        $gidText = $gid !== '' ? ' (Gläubiger-Identifikationsnummer ' . $gid . ')' : '';

        return '<p>Guten Tag,</p>'
            . '<p>vielen Dank für Ihren Aufnahmeantrag beim ' . $v . ' '
            . 'Bitte bestätigen Sie Ihre E-Mail-Adresse und Ihren Antrag:</p>'
            . '<p style="text-align:center;margin:30px 0;"><a href="' . $l . '" '
            . 'style="display:inline-block;background:#3391cb;color:#fff;padding:14px 28px;text-decoration:none;'
            . 'border-radius:8px;font-weight:600;font-size:16px;">Antrag jetzt bestätigen</a></p>'
            . '<p style="font-size:14px;color:#6b7280">Falls der Button nicht funktioniert, kopieren Sie bitte '
            . 'diesen Link in Ihren Browser:<br><a href="' . $l . '" style="color:#374151;word-break:break-all;">'
            . $l . '</a></p>'
            . '<p><strong>Ihr Mitgliedsbeitrag:</strong> ' . $b . '</p>'
            . '<h3 style="color:#374151;">SEPA-Lastschriftmandat</h3>'
            . '<p style="font-size:14px;color:#4b5563">Mit der Bestätigung ermächtigen Sie den ' . $v . $gidText
            . ', den Mitgliedsbeitrag wiederkehrend per SEPA-Lastschrift von Ihrem Konto einzuziehen '
            . '(IBAN ' . $i . ', Kontoinhaber ' . $k . '). Zugleich weisen Sie Ihr Kreditinstitut an, die '
            . 'Lastschriften einzulösen. Ihre Mandatsreferenz teilen wir Ihnen nach der Aufnahme mit.</p>'
            . '<p style="font-size:14px;color:#4b5563">Vor jedem Einzug informieren wir Sie mindestens 14 '
            . 'Kalendertage vorher per E-Mail über Termin und Betrag. Sie können innerhalb von acht Wochen ab '
            . 'Belastung die Erstattung verlangen; es gelten die mit Ihrer Bank vereinbarten Bedingungen.</p>'
            . '<p style="font-size:14px;color:#6b7280">Wenn Sie diesen Antrag nicht gestellt haben, ignorieren '
            . 'Sie diese E-Mail einfach.</p>'
            . '<p style="margin-top:20px;">Mit freundlichen Grüßen<br><strong>Ihr Vorstand des Fördervereins</strong></p>'
            . '<p style="font-size:14px;color:#6b7280"><a href="https://www.gymnasium-herzogenrath.de/foerderverein" '
            . 'style="color:#374151;">https://www.gymnasium-herzogenrath.de/foerderverein</a></p>';
    }
}
