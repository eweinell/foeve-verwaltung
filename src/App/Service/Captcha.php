<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use TrustComponent\TrustCaptcha\CaptchaManager;

/**
 * TrustCaptcha-Bot-Schutz für die öffentliche Antragsseite (F2), übernommen aus
 * der bestehenden Anmelde-App (`foeve-signupphp/index.php`).
 *
 * Verifiziert wird über das offizielle SDK: der API-Endpunkt steckt im Token
 * selbst, es gibt also keine feste Verify-URL. Ein Score über SCORE_GRENZE gilt
 * als Bot — dieselbe Schwelle wie in der Altanwendung.
 *
 * Optional: ohne konfiguriertes Sitekey/Secret ist die Prüfung deaktiviert
 * (Entwicklung/Test). Bei aktiver Konfiguration gilt „fail closed": ist die
 * Verifikation nicht möglich, wird der Antrag abgewiesen.
 */
final class Captcha
{
    /** Score über diesem Wert = Bot (Altanwendung: `$result->score > 0.6`). */
    private const SCORE_GRENZE = 0.6;

    /** Widget-Skript. Einzige erlaubte CDN-Einbindung (CLAUDE.md, KONZEPT §F2). */
    public const SKRIPT_URL = 'https://cdn.trustcomponent.com/trustcaptcha/2.0.x/trustcaptcha.umd.min.js';

    /** Name des Formularfelds, in das das Widget sein Token schreibt. */
    public const TOKEN_FELD = 'tc-verification-token';

    public function __construct(
        private readonly string $siteKey,
        private readonly string $secret,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aktiv(): bool
    {
        return $this->siteKey !== '' && $this->secret !== '';
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function skriptUrl(): string
    {
        return self::SKRIPT_URL;
    }

    /**
     * Prüft das Verifikations-Token. Ohne aktive Konfiguration immer true.
     */
    public function pruefe(?string $token): bool
    {
        if (!$this->aktiv()) {
            return true;
        }
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $ergebnis = CaptchaManager::getVerificationResult($this->secret, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Captcha-Verifikation fehlgeschlagen: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }

        return $ergebnis->verificationPassed === true
            && (float) $ergebnis->score <= self::SCORE_GRENZE;
    }
}
