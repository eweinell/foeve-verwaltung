<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mitgliedsstatus;
use App\Domain\SystemVorlagen;
use App\Repository\MitgliedRepository;
use App\Service\Laender;
use App\Service\MailDienst;
use App\Service\VersandaktionService;
use App\Service\VorlagenService;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Versandaktionen / Vereinspost (F6): Assistent (Filter → Vorlage/Freitext →
 * PDF-Anhang → Vorschau/Testmail → Freigabe) und Protokoll. Für admin und vorstand.
 */
final class EmailController
{
    private const ANHANG_MAX = 5 * 1024 * 1024; // 5 MB

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly VersandaktionService $versand,
        private readonly VorlagenService $vorlagen,
        private readonly MitgliedRepository $mitglieder,
        private readonly MailDienst $mail,
        private readonly string $basisPfad,
    ) {
    }

    public function uebersicht(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'email/uebersicht.twig', [
            'aktuelle_seite' => 'email',
            'aktionen'       => $this->versand->alle(),
        ]);
    }

    public function assistent(Request $request, Response $response, array $eingabe = [], ?array $vorschau = null): Response
    {
        $filter = $this->filterAus($eingabe);

        return $this->ansicht->render($response, 'email/neu.twig', [
            'aktuelle_seite' => 'email',
            'statusliste'    => Mitgliedsstatus::alle(),
            'laender'        => Laender::NAMEN,
            'vorlagen'       => $this->vorlagen->alle(),
            'platzhalter'    => SystemVorlagen::PLATZHALTER_ALLGEMEIN,
            'alt'            => $eingabe,
            'filter'         => $filter,
            'empfaenger'     => $this->versand->empfaenger($filter),
            'vorschau'       => $vorschau,
        ]);
    }

    public function vorschau(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();
        try {
            $beispiel = $this->beispielMitglied($d);
            $vorschau = $beispiel !== null
                ? $this->versand->vorschau($this->vorlage($d), $this->betreff($d), $this->text($d), $beispiel)
                : null;
            if ($beispiel === null) {
                $this->flash->info('Kein E-Mail-Empfänger im Filter — keine Vorschau möglich.');
            }

            return $this->assistent($request, $response, $d, $vorschau);
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());

            return $this->assistent($request, $response, $d);
        }
    }

    public function testmail(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();
        $benutzer = $request->getAttribute('benutzer');
        $email = is_array($benutzer) ? trim((string) ($benutzer['email'] ?? '')) : '';
        if ($email === '') {
            $this->flash->fehler('Ihr Konto hat keine E-Mail-Adresse hinterlegt.');

            return $this->assistent($request, $response, $d);
        }

        try {
            $anhang = $this->anhangSpeichern($request);
            $this->versand->testmail($email, $this->vorlage($d), $this->betreff($d), $this->text($d), $this->beispielMitglied($d), $anhang);
            $this->flash->erfolg('Testmail an ' . $email . ' wurde eingereiht (Priorität sofort).');
        } catch (\DomainException | \RuntimeException $e) {
            $this->flash->fehler($e->getMessage());
        }

        return $this->assistent($request, $response, $d);
    }

    public function starten(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();
        try {
            $anhang = $this->anhangSpeichern($request);
            $ergebnis = $this->versand->starten(
                $this->filterAus($d),
                'vereinspost',
                $this->vorlage($d),
                $this->betreff($d),
                $this->text($d),
                $anhang,
                $this->benutzerId($request),
            );
            $this->flash->erfolg(sprintf(
                'Versandaktion gestartet: %d Mails eingereiht, %d Mitglieder für den Postversand (siehe Brief-Liste).',
                $ergebnis['anzahl_email'],
                $ergebnis['anzahl_post'],
            ));

            return $response->withHeader('Location', '/email/' . $ergebnis['versandaktion_id'])->withStatus(302);
        } catch (\DomainException | \RuntimeException $e) {
            $this->flash->fehler($e->getMessage());

            return $this->assistent($request, $response, $d);
        }
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $aktion = $this->versand->findePerId((int) $args['id']);
        if ($aktion === null) {
            throw new HttpNotFoundException($request, 'Versandaktion nicht gefunden.');
        }

        return $this->ansicht->render($response, 'email/detail.twig', [
            'aktuelle_seite' => 'email',
            'aktion'         => $aktion,
            'mails'          => $this->versand->mails((int) $aktion['id']),
        ]);
    }

    public function neuEinreihen(Request $request, Response $response, array $args): Response
    {
        $aktion = $this->versand->findePerId((int) $args['id']);
        if ($aktion === null) {
            throw new HttpNotFoundException($request, 'Versandaktion nicht gefunden.');
        }
        $d = (array) $request->getParsedBody();
        $mailId = ctype_digit((string) ($d['mail_id'] ?? '')) ? (int) $d['mail_id'] : null;

        $anzahl = $this->mail->fehlerNeuEinreihen($mailId === null ? (int) $aktion['id'] : null, $mailId);
        $this->flash->erfolg($anzahl . ' Mail(s) wurden erneut eingereiht.');

        return $response->withHeader('Location', '/email/' . $aktion['id'])->withStatus(302);
    }

    // ---- intern ----------------------------------------------------------

    /**
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function filterAus(array $d): array
    {
        return [
            'status'    => (string) ($d['status'] ?? ''),
            'zahlweise' => (string) ($d['zahlweise'] ?? ''),
            'land'      => (string) ($d['land'] ?? ''),
            'email'     => (string) ($d['email'] ?? ''),
            'q'         => (string) ($d['q'] ?? ''),
        ];
    }

    private function vorlage(array $d): ?string
    {
        $v = trim((string) ($d['vorlage'] ?? ''));

        return $v !== '' ? $v : null;
    }

    private function betreff(array $d): string
    {
        return trim((string) ($d['betreff'] ?? ''));
    }

    private function text(array $d): string
    {
        return (string) ($d['text'] ?? '');
    }

    /**
     * @param array<string,mixed> $d
     * @return array<string,mixed>|null
     */
    private function beispielMitglied(array $d): ?array
    {
        if (ctype_digit((string) ($d['beispiel_id'] ?? ''))) {
            $m = $this->mitglieder->findePerId((int) $d['beispiel_id']);
            if ($m !== null) {
                return $m;
            }
        }
        $empfaenger = $this->versand->empfaenger($this->filterAus($d));

        return $empfaenger['email'][0] ?? null;
    }

    private function anhangSpeichern(Request $request): ?string
    {
        $dateien = $request->getUploadedFiles();
        $datei = $dateien['anhang'] ?? null;
        if (!$datei instanceof UploadedFileInterface || $datei->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($datei->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Der Anhang konnte nicht hochgeladen werden.');
        }
        if ($datei->getSize() > self::ANHANG_MAX) {
            throw new \RuntimeException('Der Anhang ist größer als 5 MB.');
        }
        $typ = $datei->getClientMediaType();
        $name = (string) $datei->getClientFilename();
        if ($typ !== 'application/pdf' && !str_ends_with(strtolower($name), '.pdf')) {
            throw new \RuntimeException('Es sind nur PDF-Anhänge erlaubt.');
        }

        $verzeichnis = $this->basisPfad . '/var/anhaenge';
        if (!is_dir($verzeichnis)) {
            @mkdir($verzeichnis, 0770, true);
        }
        $pfad = $verzeichnis . '/' . bin2hex(random_bytes(8)) . '.pdf';
        $datei->moveTo($pfad);

        return $pfad;
    }

    private function benutzerId(Request $request): ?int
    {
        $benutzer = $request->getAttribute('benutzer');

        return is_array($benutzer) ? (int) $benutzer['id'] : null;
    }
}
