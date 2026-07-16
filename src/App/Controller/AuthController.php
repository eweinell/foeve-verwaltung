<?php

declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AuthMiddleware;
use App\Repository\BenutzerRepository;
use App\Service\Audit;
use App\Service\LoginThrottle;
use App\Service\ZweiFaktor;
use App\Support\Ansicht;
use App\Support\Flash;
use App\Support\Passwoerter;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Login, optionaler zweiter Faktor, Passwortwechsel und Logout (F9).
 * Keine Benutzerregistrierung — Konten legt nur der Admin an.
 */
final class AuthController
{
    private const PENDING_ID = '_2fa_benutzer';
    private const PENDING_METHODE = '_2fa_methode';

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Session $session,
        private readonly Flash $flash,
        private readonly BenutzerRepository $benutzer,
        private readonly LoginThrottle $throttle,
        private readonly ZweiFaktor $zweiFaktor,
        private readonly Audit $audit,
    ) {
    }

    public function loginFormular(Request $request, Response $response): Response
    {
        if ($this->session->has(AuthMiddleware::SESSION_BENUTZER)) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        return $this->ansicht->render($response, 'auth/login.twig');
    }

    public function login(Request $request, Response $response): Response
    {
        $daten = (array) $request->getParsedBody();
        $email = trim((string) ($daten['email'] ?? ''));
        $passwort = (string) ($daten['passwort'] ?? '');

        $benutzer = $email !== '' ? $this->benutzer->findePerEmail($email) : null;

        // Generische Fehlermeldung — keine Auskunft, ob das Konto existiert.
        $fehler = 'Anmeldung fehlgeschlagen. Bitte prüfen Sie E-Mail und Passwort.';

        if ($benutzer === null) {
            $this->audit->protokolliere(null, 'login_fehlgeschlagen', 'benutzer', null, ['email' => $email]);

            return $this->loginFehler($response, $fehler);
        }

        if ($this->throttle->istGesperrt($benutzer)) {
            $bis = $this->throttle->gesperrtBis($benutzer);
            $this->audit->protokolliere((int) $benutzer['id'], 'login_gesperrt', 'benutzer', (int) $benutzer['id']);

            return $this->loginFehler(
                $response,
                'Das Konto ist vorübergehend gesperrt. Bitte versuchen Sie es ab '
                . ($bis?->format('H:i') ?? 'später') . ' Uhr erneut.',
            );
        }

        $passwortOk = Passwoerter::pruefe($passwort, (string) $benutzer['passwort_hash']);
        if (!$passwortOk || (int) $benutzer['aktiv'] !== 1) {
            $ergebnis = $this->throttle->registriereFehlversuch((int) $benutzer['id']);
            $this->audit->protokolliere((int) $benutzer['id'], 'login_fehlgeschlagen', 'benutzer', (int) $benutzer['id']);
            if ($ergebnis['gesperrt']) {
                $this->audit->protokolliere((int) $benutzer['id'], 'login_gesperrt', 'benutzer', (int) $benutzer['id']);
            }

            return $this->loginFehler($response, $fehler);
        }

        $methode = (string) $benutzer['zwei_faktor_methode'];
        if ($methode === ZweiFaktor::METHODE_KEINE) {
            return $this->loginAbschliessen($response, $benutzer);
        }

        // Zweiter Faktor erforderlich: Zwischenzustand in der Session halten.
        $this->session->set(self::PENDING_ID, (int) $benutzer['id']);
        $this->session->set(self::PENDING_METHODE, $methode);
        if ($methode === ZweiFaktor::METHODE_EMAIL) {
            $this->zweiFaktor->sendeEmailCode($benutzer);
        }

        return $response->withHeader('Location', '/login/2fa')->withStatus(302);
    }

    public function zweiFaktorFormular(Request $request, Response $response): Response
    {
        $methode = $this->session->get(self::PENDING_METHODE);
        if (!is_string($methode)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $this->ansicht->render($response, 'auth/zwei_faktor.twig', ['methode' => $methode]);
    }

    public function zweiFaktor(Request $request, Response $response): Response
    {
        $id = $this->session->get(self::PENDING_ID);
        $methode = $this->session->get(self::PENDING_METHODE);
        if (!is_int($id) || !is_string($methode)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $benutzer = $this->benutzer->findePerId($id);
        if ($benutzer === null) {
            $this->session->loeschen(self::PENDING_ID);

            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $code = (string) ($body['code'] ?? '');

        $ok = $methode === ZweiFaktor::METHODE_TOTP
            ? $this->zweiFaktor->pruefeTotp((string) $benutzer['totp_secret'], $code)
            : $this->zweiFaktor->pruefeEmailCode($benutzer, $code);

        if (!$ok) {
            $ergebnis = $this->throttle->registriereFehlversuch($id);
            $this->audit->protokolliere($id, 'login_2fa_fehlgeschlagen', 'benutzer', $id);
            if ($ergebnis['gesperrt']) {
                $this->audit->protokolliere($id, 'login_gesperrt', 'benutzer', $id);
                $this->session->loeschen(self::PENDING_ID);
                $this->session->loeschen(self::PENDING_METHODE);
                $this->flash->fehler('Zu viele Fehlversuche. Das Konto ist vorübergehend gesperrt.');

                return $response->withHeader('Location', '/login')->withStatus(302);
            }
            $this->flash->fehler('Der Code war nicht korrekt. Bitte versuchen Sie es erneut.');

            return $response->withHeader('Location', '/login/2fa')->withStatus(302);
        }

        $this->session->loeschen(self::PENDING_ID);
        $this->session->loeschen(self::PENDING_METHODE);

        return $this->loginAbschliessen($response, $benutzer);
    }

    public function passwortAendernFormular(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        $pflicht = is_array($benutzer) && (int) $benutzer['passwort_aendern_pflicht'] === 1;

        return $this->ansicht->render($response, 'auth/passwort_aendern.twig', ['pflicht' => $pflicht]);
    }

    public function passwortAendern(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        if (!is_array($benutzer)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $daten = (array) $request->getParsedBody();
        $alt = (string) ($daten['aktuell'] ?? '');
        $neu = (string) ($daten['neu'] ?? '');
        $neu2 = (string) ($daten['neu_wdh'] ?? '');

        if (!Passwoerter::pruefe($alt, (string) $benutzer['passwort_hash'])) {
            $this->flash->fehler('Das aktuelle Passwort ist nicht korrekt.');

            return $response->withHeader('Location', '/passwort-aendern')->withStatus(302);
        }
        if (strlen($neu) < 10) {
            $this->flash->fehler('Das neue Passwort muss mindestens 10 Zeichen lang sein.');

            return $response->withHeader('Location', '/passwort-aendern')->withStatus(302);
        }
        if ($neu !== $neu2) {
            $this->flash->fehler('Die beiden neuen Passwörter stimmen nicht überein.');

            return $response->withHeader('Location', '/passwort-aendern')->withStatus(302);
        }

        $this->benutzer->setzePasswort((int) $benutzer['id'], Passwoerter::hash($neu), false);
        $this->audit->protokolliere((int) $benutzer['id'], 'passwort_geaendert', 'benutzer', (int) $benutzer['id']);
        $this->flash->erfolg('Ihr Passwort wurde geändert.');

        return $response->withHeader('Location', '/')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        if (is_array($benutzer)) {
            $this->audit->protokolliere((int) $benutzer['id'], 'logout', 'benutzer', (int) $benutzer['id']);
        }
        $this->session->beenden();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /**
     * @param array<string,mixed> $benutzer
     */
    private function loginAbschliessen(Response $response, array $benutzer): Response
    {
        $this->session->regenerieren();
        $this->session->set(AuthMiddleware::SESSION_BENUTZER, (int) $benutzer['id']);
        $this->throttle->registriereErfolg((int) $benutzer['id']);
        $this->audit->protokolliere((int) $benutzer['id'], 'login_ok', 'benutzer', (int) $benutzer['id']);

        $ziel = (int) $benutzer['passwort_aendern_pflicht'] === 1 ? '/passwort-aendern' : '/';

        return $response->withHeader('Location', $ziel)->withStatus(302);
    }

    private function loginFehler(Response $response, string $text): Response
    {
        $this->flash->fehler($text);

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
