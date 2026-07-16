<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Audit;
use App\Service\Einstellungen;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Einstellungsseite — nur Rolle admin. In AP0: Drosselrate der Mail-Queue und
 * Absenderangaben; SMTP wird nur lesend aus der .env angezeigt.
 */
final class EinstellungenController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly Einstellungen $einstellungen,
        private readonly Audit $audit,
        /** @var array<string,mixed> */
        private readonly array $mailConfig,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'admin/einstellungen.twig', [
            'aktuelle_seite'   => 'einstellungen',
            'mail_rate'        => $this->einstellungen->holeInt('mail_rate_pro_minute', 10),
            'absender_name'    => $this->einstellungen->hole('absender_name', (string) ($this->mailConfig['absender_name'] ?? '')),
            'absender_adresse' => $this->einstellungen->hole('absender_adresse', (string) ($this->mailConfig['absender_adresse'] ?? '')),
            'smtp_dsn_anzeige' => $this->smtpAnzeige((string) ($this->mailConfig['dsn'] ?? '')),
        ]);
    }

    public function speichern(Request $request, Response $response): Response
    {
        $daten = (array) $request->getParsedBody();

        $rate = (int) ($daten['mail_rate_pro_minute'] ?? 10);
        $rate = max(1, min(120, $rate));
        $this->einstellungen->setze('mail_rate_pro_minute', (string) $rate);
        $this->einstellungen->setze('absender_name', trim((string) ($daten['absender_name'] ?? '')));
        $this->einstellungen->setze('absender_adresse', trim((string) ($daten['absender_adresse'] ?? '')));

        $aktuell = $request->getAttribute('benutzer');
        $this->audit->protokolliere(is_array($aktuell) ? (int) $aktuell['id'] : null, 'einstellungen_geaendert', 'einstellung', null);
        $this->flash->erfolg('Einstellungen gespeichert.');

        return $response->withHeader('Location', '/einstellungen')->withStatus(302);
    }

    /**
     * Zeigt die SMTP-Verbindung ohne Zugangsdaten (Passwort maskiert).
     */
    private function smtpAnzeige(string $dsn): string
    {
        if ($dsn === '') {
            return '(nicht konfiguriert)';
        }
        // user:pass@host → user:•••@host
        return (string) preg_replace('#(://[^:/@]+:)[^@]*@#', '$1•••@', $dsn);
    }
}
