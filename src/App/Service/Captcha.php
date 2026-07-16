<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * TrustCaptcha-Bot-Schutz für die öffentliche Antragsseite (F2). Optional:
 * ohne konfiguriertes Secret ist die Prüfung deaktiviert (Entwicklung/Test).
 *
 * Hinweis: Das genaue Verifikations-API-Kontrakt sollte gegen die bestehende
 * Anmelde-App (`foeve-signupphp`) bzw. die TrustCaptcha-Doku abgeglichen werden,
 * sobald verfügbar. Bei aktiver Konfiguration wird „fail closed" verfahren:
 * ist die Verifikation nicht möglich, gilt der Antrag als nicht bestätigt.
 */
final class Captcha
{
    public function __construct(
        private readonly string $siteKey,
        private readonly string $secret,
        private readonly LoggerInterface $logger,
        private readonly string $verifyUrl = 'https://api.trustcaptcha.com/api/v1/verification/verifytoken',
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
            $antwort = $this->anfrage($token);
            $daten = json_decode($antwort, true);

            // TrustCaptcha liefert u. a. „success"/„verified" und einen Score.
            $erfolg = ($daten['success'] ?? $daten['verified'] ?? false) === true;
            $score = (float) ($daten['score'] ?? 0);

            return $erfolg && $score < 0.5; // niedriger Score = menschlich
        } catch (\Throwable $e) {
            $this->logger->warning('Captcha-Verifikation fehlgeschlagen: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    private function anfrage(string $token): string
    {
        $payload = json_encode(['secretKey' => $this->secret, 'verificationToken' => $token]);
        $ch = curl_init($this->verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $antwort = curl_exec($ch);
        $fehler = curl_error($ch);
        curl_close($ch);

        if ($antwort === false) {
            throw new \RuntimeException($fehler !== '' ? $fehler : 'Keine Antwort vom Captcha-Dienst.');
        }

        return (string) $antwort;
    }
}
