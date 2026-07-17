<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\SystemVorlagen;
use App\Service\VorlagenService;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * Verwaltung der E-Mail-Vorlagen (F6). Alle dürfen lesen; ändern nur admin.
 */
final class VorlageController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly VorlagenService $vorlagen,
    ) {
    }

    public function liste(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'email/vorlagen.twig', [
            'aktuelle_seite' => 'email',
            'vorlagen'       => $this->vorlagen->alle(),
            'ist_admin'      => $this->istAdmin($request),
        ]);
    }

    public function bearbeiten(Request $request, Response $response, array $args): Response
    {
        $schluessel = (string) $args['schluessel'];
        try {
            $vorlage = $this->vorlagen->hole($schluessel);
        } catch (\RuntimeException $e) {
            $this->flash->fehler($e->getMessage());

            return $response->withHeader('Location', '/email/vorlagen')->withStatus(302);
        }

        return $this->ansicht->render($response, 'email/vorlage_bearbeiten.twig', [
            'aktuelle_seite' => 'email',
            'vorlage'        => $vorlage,
            'platzhalter'    => SystemVorlagen::PLATZHALTER,
            'ist_admin'      => $this->istAdmin($request),
        ]);
    }

    public function speichern(Request $request, Response $response, array $args): Response
    {
        $this->nurAdmin($request);
        $schluessel = (string) $args['schluessel'];
        $d = (array) $request->getParsedBody();

        try {
            $this->vorlagen->speichern($schluessel, trim((string) ($d['betreff'] ?? '')), (string) ($d['body_text'] ?? ''), (string) ($d['body_html'] ?? '') ?: null);
            $this->flash->erfolg('Die Vorlage wurde gespeichert.');
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());

            return $response->withHeader('Location', '/email/vorlagen/' . $schluessel)->withStatus(302);
        }

        return $response->withHeader('Location', '/email/vorlagen')->withStatus(302);
    }

    public function zuruecksetzen(Request $request, Response $response, array $args): Response
    {
        $this->nurAdmin($request);
        $schluessel = (string) $args['schluessel'];
        $this->vorlagen->loeschen($schluessel);
        $this->flash->info(SystemVorlagen::istSystem($schluessel)
            ? 'Die Systemvorlage wurde auf den Standardtext zurückgesetzt.'
            : 'Die Vorlage wurde gelöscht.');

        return $response->withHeader('Location', '/email/vorlagen')->withStatus(302);
    }

    private function istAdmin(Request $request): bool
    {
        $benutzer = $request->getAttribute('benutzer');

        return is_array($benutzer) && ($benutzer['rolle'] ?? '') === 'admin';
    }

    private function nurAdmin(Request $request): void
    {
        if (!$this->istAdmin($request)) {
            throw new HttpForbiddenException($request, 'Vorlagen dürfen nur Administratoren ändern.');
        }
    }
}
