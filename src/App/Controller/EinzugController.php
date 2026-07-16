<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Einzugslaufstatus;
use App\Repository\EinzugslaufRepository;
use App\Service\EinzugslaufService;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Geführter SEPA-Einzugslauf (F5): anlegen, ankündigen, pain.008 exportieren,
 * abschließen, Rücklastschriften erfassen. Zugänglich für admin und vorstand.
 */
final class EinzugController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly EinzugslaufService $service,
        private readonly EinzugslaufRepository $laeufe,
    ) {
    }

    public function liste(Request $request, Response $response): Response
    {
        $laeufe = [];
        foreach ($this->laeufe->alle() as $lauf) {
            $lauf['status_label'] = Einzugslaufstatus::label((string) $lauf['status']);
            $lauf['status_badge'] = Einzugslaufstatus::badge((string) $lauf['status']);
            $laeufe[] = $lauf;
        }

        return $this->ansicht->render($response, 'einzug/liste.twig', [
            'aktuelle_seite' => 'einzug',
            'laeufe'         => $laeufe,
        ]);
    }

    public function anlegen(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();
        try {
            $ergebnis = $this->service->anlegen(
                (string) ($d['bezeichnung'] ?? ''),
                trim((string) ($d['faelligkeitsdatum'] ?? '')),
                $this->benutzerId($request),
            );
            foreach ($ergebnis['warnungen'] as $warnung) {
                $this->flash->warnung($warnung);
            }
            $this->flash->erfolg('Der Einzugslauf wurde angelegt.');

            return $response->withHeader('Location', '/einzug/' . $ergebnis['id'])->withStatus(302);
        } catch (\Throwable $e) {
            $this->flash->fehler($e->getMessage());

            return $response->withHeader('Location', '/einzug')->withStatus(302);
        }
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);

        return $this->ansicht->render($response, 'einzug/detail.twig', [
            'aktuelle_seite' => 'einzug',
            'lauf'           => $lauf,
            'lauf_label'     => Einzugslaufstatus::label((string) $lauf['status']),
            'lauf_badge'     => Einzugslaufstatus::badge((string) $lauf['status']),
            'vorschau'       => $this->service->vorschau((int) $lauf['id']),
            'briefliste'     => $this->service->briefListe((int) $lauf['id']),
            'darf_loeschen'  => Einzugslaufstatus::darfGeloeschtWerden((string) $lauf['status']),
        ]);
    }

    public function positionAbwaehlen(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        try {
            $this->service->positionAbwaehlen((int) $lauf['id'], (int) $args['forderungId'], $this->benutzerId($request));
            $this->flash->erfolg('Die Position wurde entfernt.');
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());
        }

        return $this->zu($response, (int) $lauf['id']);
    }

    public function ankuendigen(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        try {
            $this->service->ankuendigen((int) $lauf['id'], $this->benutzerId($request));
            $this->flash->erfolg('Die Pre-Notifications wurden eingereiht.');
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());
        }

        return $this->zu($response, (int) $lauf['id']);
    }

    public function exportieren(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        try {
            $ergebnis = $this->service->xmlErzeugen((int) $lauf['id'], $this->benutzerId($request));

            return $this->dateiAntwort($response, $ergebnis['dateiname'], $ergebnis['xml']);
        } catch (\Throwable $e) {
            $this->flash->fehler('XML konnte nicht erzeugt werden: ' . $e->getMessage());

            return $this->zu($response, (int) $lauf['id']);
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        if ((string) $lauf['status'] !== Einzugslaufstatus::EXPORTIERT && (string) $lauf['status'] !== Einzugslaufstatus::ABGESCHLOSSEN) {
            throw new HttpNotFoundException($request, 'Für diesen Lauf liegt noch keine XML-Datei vor.');
        }
        $pfad = (string) $lauf['xml_pfad'];
        if ($pfad === '' || !is_file($pfad)) {
            throw new HttpNotFoundException($request, 'XML-Datei nicht gefunden.');
        }

        return $this->dateiAntwort($response, basename($pfad), (string) file_get_contents($pfad));
    }

    public function abschliessen(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        try {
            $this->service->abschliessen((int) $lauf['id'], $this->benutzerId($request));
            $this->flash->erfolg('Der Einzugslauf wurde abgeschlossen; die Forderungen sind als bezahlt markiert.');
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());
        }

        return $this->zu($response, (int) $lauf['id']);
    }

    public function loeschen(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        try {
            $this->service->loeschen((int) $lauf['id'], $this->benutzerId($request));
            $this->flash->erfolg('Der Einzugslauf wurde gelöscht; die Forderungen sind wieder offen.');

            return $response->withHeader('Location', '/einzug')->withStatus(302);
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());

            return $this->zu($response, (int) $lauf['id']);
        }
    }

    public function ruecklastschrift(Request $request, Response $response, array $args): Response
    {
        $lauf = $this->laden((int) $args['id'], $request);
        $d = (array) $request->getParsedBody();
        $forderungId = ctype_digit((string) ($d['forderung_id'] ?? '')) ? (int) $d['forderung_id'] : 0;
        if ($forderungId === 0) {
            $this->flash->fehler('Bitte eine Position wählen.');

            return $this->zu($response, (int) $lauf['id']);
        }
        $this->service->ruecklastschriftErfassen(
            $forderungId,
            isset($d['gebuehr']),
            isset($d['selbstzahler']),
            $this->benutzerId($request),
        );
        $this->flash->warnung('Die Rücklastschrift wurde erfasst; die Forderung ist wieder offen.');

        return $this->zu($response, (int) $lauf['id']);
    }

    // ---- intern ----------------------------------------------------------

    private function dateiAntwort(Response $response, string $dateiname, string $inhalt): Response
    {
        $response->getBody()->write($inhalt);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $dateiname . '"');
    }

    /**
     * @return array<string,mixed>
     */
    private function laden(int $id, Request $request): array
    {
        $lauf = $this->laeufe->findePerId($id);
        if ($lauf === null) {
            throw new HttpNotFoundException($request, 'Einzugslauf nicht gefunden.');
        }

        return $lauf;
    }

    private function benutzerId(Request $request): ?int
    {
        $benutzer = $request->getAttribute('benutzer');

        return is_array($benutzer) ? (int) $benutzer['id'] : null;
    }

    private function zu(Response $response, int $id): Response
    {
        return $response->withHeader('Location', '/einzug/' . $id)->withStatus(302);
    }
}
