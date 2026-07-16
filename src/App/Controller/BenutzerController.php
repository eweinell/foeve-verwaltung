<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BenutzerRepository;
use App\Service\Audit;
use App\Support\Ansicht;
use App\Support\Flash;
use App\Support\Passwoerter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Benutzerverwaltung — nur Rolle admin (KONZEPT §2). Anlegen, deaktivieren,
 * Rolle setzen, Einmal-Passwort vergeben. Keine Registrierung, kein Mail-Reset.
 */
final class BenutzerController
{
    private const ROLLEN = ['admin', 'vorstand'];

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly BenutzerRepository $benutzer,
        private readonly Audit $audit,
    ) {
    }

    public function liste(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'admin/benutzer/liste.twig', [
            'aktuelle_seite' => 'einstellungen',
            'benutzerliste'  => $this->benutzer->alle(),
        ]);
    }

    public function neuFormular(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'admin/benutzer/neu.twig', [
            'aktuelle_seite' => 'einstellungen',
            'rollen'         => self::ROLLEN,
        ]);
    }

    public function anlegen(Request $request, Response $response): Response
    {
        $daten = (array) $request->getParsedBody();
        $name = trim((string) ($daten['name'] ?? ''));
        $email = trim((string) ($daten['email'] ?? ''));
        $rolle = (string) ($daten['rolle'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($rolle, self::ROLLEN, true)) {
            $this->flash->fehler('Bitte Name, gültige E-Mail und Rolle angeben.');

            return $response->withHeader('Location', '/einstellungen/benutzer/neu')->withStatus(302);
        }
        if ($this->benutzer->findePerEmail($email) !== null) {
            $this->flash->fehler('Diese E-Mail-Adresse ist bereits vergeben.');

            return $response->withHeader('Location', '/einstellungen/benutzer/neu')->withStatus(302);
        }

        $einmal = $this->einmalPasswort();
        $id = (int) $this->benutzer->anlegen($name, $email, Passwoerter::hash($einmal), $rolle, true);
        $aktuell = $request->getAttribute('benutzer');
        $this->audit->protokolliere(
            is_array($aktuell) ? (int) $aktuell['id'] : null,
            'benutzer_angelegt',
            'benutzer',
            $id,
            ['email' => $email, 'rolle' => $rolle],
        );

        // Einmal-Passwort wird EINMALIG angezeigt (kein Mailversand von Passwörtern).
        $this->flash->erfolg("Benutzer angelegt. Einmal-Passwort für {$email}: {$einmal} — bitte sicher übermitteln. Beim ersten Login muss es geändert werden.");

        return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
    }

    public function aktualisieren(Request $request, Response $response, array $args): Response
    {
        $ziel = $this->benutzer->findePerId((int) $args['id']);
        if ($ziel === null) {
            $this->flash->fehler('Benutzer nicht gefunden.');

            return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
        }

        $daten = (array) $request->getParsedBody();
        $name = trim((string) ($daten['name'] ?? $ziel['name']));
        $email = trim((string) ($daten['email'] ?? $ziel['email']));
        $rolle = (string) ($daten['rolle'] ?? $ziel['rolle']);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($rolle, self::ROLLEN, true)) {
            $this->flash->fehler('Bitte Name, gültige E-Mail und Rolle angeben.');

            return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
        }

        $this->benutzer->aktualisiereStammdaten((int) $ziel['id'], $name, $email, $rolle);
        $this->protokolliere($request, 'benutzer_geaendert', (int) $ziel['id']);
        $this->flash->erfolg('Benutzer aktualisiert.');

        return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
    }

    public function aktivSetzen(Request $request, Response $response, array $args): Response
    {
        $ziel = $this->benutzer->findePerId((int) $args['id']);
        $aktuell = $request->getAttribute('benutzer');
        if ($ziel === null) {
            $this->flash->fehler('Benutzer nicht gefunden.');

            return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
        }
        if (is_array($aktuell) && (int) $aktuell['id'] === (int) $ziel['id']) {
            $this->flash->fehler('Sie können Ihr eigenes Konto nicht deaktivieren.');

            return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
        }

        $neu = (int) $ziel['aktiv'] === 1 ? false : true;
        $this->benutzer->setzeAktiv((int) $ziel['id'], $neu);
        $this->protokolliere($request, $neu ? 'benutzer_aktiviert' : 'benutzer_deaktiviert', (int) $ziel['id']);
        $this->flash->erfolg($neu ? 'Benutzer aktiviert.' : 'Benutzer deaktiviert.');

        return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
    }

    public function passwortZuruecksetzen(Request $request, Response $response, array $args): Response
    {
        $ziel = $this->benutzer->findePerId((int) $args['id']);
        if ($ziel === null) {
            $this->flash->fehler('Benutzer nicht gefunden.');

            return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
        }

        $einmal = $this->einmalPasswort();
        $this->benutzer->setzePasswort((int) $ziel['id'], Passwoerter::hash($einmal), true);
        $this->protokolliere($request, 'passwort_zurueckgesetzt', (int) $ziel['id']);
        $this->flash->erfolg("Neues Einmal-Passwort für {$ziel['email']}: {$einmal} — bitte sicher übermitteln. Muss beim nächsten Login geändert werden.");

        return $response->withHeader('Location', '/einstellungen/benutzer')->withStatus(302);
    }

    private function protokolliere(Request $request, string $aktion, int $zielId): void
    {
        $aktuell = $request->getAttribute('benutzer');
        $this->audit->protokolliere(
            is_array($aktuell) ? (int) $aktuell['id'] : null,
            $aktion,
            'benutzer',
            $zielId,
        );
    }

    /**
     * Zufälliges, gut übermittelbares Einmal-Passwort (keine mehrdeutigen Zeichen).
     */
    private function einmalPasswort(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $laenge = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $laenge - 1)];
        }

        return $out;
    }
}
