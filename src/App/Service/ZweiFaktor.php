<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BenutzerRepository;
use App\Support\EndroidQrProvider;
use RobThree\Auth\TwoFactorAuth;

/**
 * Optionaler zweiter Faktor (F9, Q11): TOTP per Authenticator-App ODER
 * 6-stelliger Bestätigungscode per E-Mail (10 Minuten gültig, über die Queue
 * mit Priorität „sofort"). Auswahl pro Benutzer im Profil.
 */
final class ZweiFaktor
{
    public const METHODE_KEINE = 'keine';
    public const METHODE_TOTP = 'totp';
    public const METHODE_EMAIL = 'email';

    private const CODE_GUELTIG_MINUTEN = 10;

    private readonly TwoFactorAuth $totp;

    public function __construct(
        private readonly BenutzerRepository $benutzer,
        private readonly MailDienst $mail,
        private readonly Einstellungen $einstellungen,
        string $ausstellername = 'Förderverein Gymnasium Herzogenrath',
    ) {
        // v3: QR-Provider zuerst, dann Aussteller. QR-Erzeugung via endroid (lokal).
        $this->totp = new TwoFactorAuth(new EndroidQrProvider(), $ausstellername);
    }

    public function erzeugeTotpSecret(): string
    {
        return $this->totp->createSecret();
    }

    /**
     * TOTP-Einrichtungs-QR als data:-URI (PNG, lokal erzeugt — kein CDN).
     */
    public function totpQrDatenUri(string $email, string $secret): string
    {
        return $this->totp->getQRCodeImageAsDataUri($email, $secret, 220);
    }

    public function pruefeTotp(string $secret, string $code): bool
    {
        return $this->totp->verifyCode($secret, trim($code));
    }

    /**
     * Erzeugt einen E-Mail-Code, speichert den Hash am Benutzer und reiht die
     * Zustellung mit Priorität „sofort" in die Queue ein.
     *
     * @param array<string,mixed> $benutzer
     */
    public function sendeEmailCode(array $benutzer): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $bis = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify('+' . self::CODE_GUELTIG_MINUTEN . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->benutzer->setzeEmailCode((int) $benutzer['id'], password_hash($code, PASSWORD_DEFAULT), $bis);

        $text = "Ihr Anmeldecode für die Vereinsverwaltung lautet: {$code}\n\n"
            . "Der Code ist " . self::CODE_GUELTIG_MINUTEN . " Minuten gültig.\n"
            . "Wenn Sie sich nicht anmelden wollten, ignorieren Sie diese E-Mail.";

        $this->mail->einreihen(
            (string) $benutzer['email'],
            'Ihr Anmeldecode',
            $text,
            prioritaet: MailDienst::PRIO_SOFORT,
        );
    }

    /**
     * @param array<string,mixed> $benutzer
     */
    public function pruefeEmailCode(array $benutzer, string $eingabe): bool
    {
        $hash = $benutzer['email_code_hash'] ?? null;
        $bis = $benutzer['email_code_bis'] ?? null;
        if (!is_string($hash) || $hash === '' || $bis === null) {
            return false;
        }

        $abgelaufen = new \DateTimeImmutable((string) $bis, new \DateTimeZone('Europe/Berlin'))
            < new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        if ($abgelaufen) {
            return false;
        }

        if (!password_verify(trim($eingabe), $hash)) {
            return false;
        }

        // Einmalcode nach Erfolg entwerten.
        $this->benutzer->setzeEmailCode((int) $benutzer['id'], null, null);

        return true;
    }
}
