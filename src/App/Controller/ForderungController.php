<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Forderungsstatus;
use App\Repository\ForderungRepository;
use App\Service\SollstellungService;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sollstellung (F4) und Übersicht der offenen Posten. Zugänglich für admin und
 * vorstand. Forderungen werden nie gelöscht — Korrektur nur per Storno.
 */
final class ForderungController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly SollstellungService $sollstellung,
        private readonly ForderungRepository $forderungen,
    ) {
    }

    public function sollstellung(Request $request, Response $response): Response
    {
        $jahr = $this->jahr($request->getQueryParams()['jahr'] ?? null);
        $vorschau = $this->sollstellung->vorschau($jahr);

        return $this->ansicht->render($response, 'forderung/sollstellung.twig', [
            'aktuelle_seite' => 'beitraege',
            'jahr'           => $jahr,
            'vorschau'       => $vorschau,
        ]);
    }

    public function sollstellungAusfuehren(Request $request, Response $response): Response
    {
        $jahr = $this->jahr(((array) $request->getParsedBody())['jahr'] ?? null);
        $anzahl = $this->sollstellung->ausfuehren($jahr, $this->benutzerId($request));
        $this->flash->erfolg(sprintf('Sollstellung %d ausgeführt: %d neue Forderung(en) erzeugt.', $jahr, $anzahl));

        return $response->withHeader('Location', '/sollstellung?jahr=' . $jahr)->withStatus(302);
    }

    public function offenePosten(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();
        $filter = [
            'jahr'      => $p['jahr'] ?? '',
            'status'    => $p['status'] ?? '',
            'zahlweise' => $p['zahlweise'] ?? '',
        ];
        $ergebnis = $this->forderungen->offenePosten($filter);

        return $this->ansicht->render($response, 'forderung/offene_posten.twig', [
            'aktuelle_seite' => 'beitraege',
            'filter'         => $filter,
            'ergebnis'       => $ergebnis,
            'statusliste'    => Forderungsstatus::alle(),
            'jahre'          => $this->forderungen->jahre(),
        ]);
    }

    private function jahr(mixed $roh): int
    {
        return is_string($roh) && ctype_digit($roh) ? (int) $roh : (int) date('Y');
    }

    private function benutzerId(Request $request): ?int
    {
        $benutzer = $request->getAttribute('benutzer');

        return is_array($benutzer) ? (int) $benutzer['id'] : null;
    }
}
