<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BenutzerRepository;
use App\Service\Audit;
use App\Service\ZweiFaktor;
use App\Support\Ansicht;
use App\Support\Flash;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Eigenes Profil: Auswahl und Einrichtung des zweiten Faktors (TOTP oder
 * E-Mail-Code), jeweils vom Benutzer selbst gesteuert (F9, Q11).
 */
final class ProfilController
{
    private const SESSION_TOTP_SETUP = '_totp_setup_secret';

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Session $session,
        private readonly Flash $flash,
        private readonly BenutzerRepository $benutzer,
        private readonly ZweiFaktor $zweiFaktor,
        private readonly Audit $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');

        return $this->ansicht->render($response, 'profil/index.twig', [
            'aktuelle_seite' => 'profil',
            'benutzer'       => $benutzer,
        ]);
    }

    /**
     * Startet die TOTP-Einrichtung: Secret erzeugen, in der Session vorhalten
     * und QR-Code anzeigen. Aktiv wird es erst nach erfolgreicher Code-Prüfung.
     */
    public function totpEinrichten(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        if (!is_array($benutzer)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $secret = $this->zweiFaktor->erzeugeTotpSecret();
        $this->session->set(self::SESSION_TOTP_SETUP, $secret);

        return $this->ansicht->render($response, 'profil/totp_einrichten.twig', [
            'aktuelle_seite' => 'profil',
            'secret'         => $secret,
            'qr'             => $this->zweiFaktor->totpQrDatenUri((string) $benutzer['email'], $secret),
        ]);
    }

    public function totpBestaetigen(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        $secret = $this->session->get(self::SESSION_TOTP_SETUP);
        if (!is_array($benutzer) || !is_string($secret)) {
            return $response->withHeader('Location', '/profil')->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $code = (string) ($body['code'] ?? '');
        if (!$this->zweiFaktor->pruefeTotp($secret, $code)) {
            $this->flash->fehler('Der Code war nicht korrekt. Bitte scannen Sie den QR-Code erneut ein und versuchen Sie es nochmal.');

            return $response->withHeader('Location', '/profil/totp')->withStatus(302);
        }

        $this->benutzer->setzeZweiFaktor((int) $benutzer['id'], ZweiFaktor::METHODE_TOTP, $secret);
        $this->session->loeschen(self::SESSION_TOTP_SETUP);
        $this->audit->protokolliere((int) $benutzer['id'], '2fa_totp_aktiviert', 'benutzer', (int) $benutzer['id']);
        $this->flash->erfolg('Die Zwei-Faktor-Anmeldung per App ist jetzt aktiv.');

        return $response->withHeader('Location', '/profil')->withStatus(302);
    }

    public function emailAktivieren(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        if (!is_array($benutzer)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->benutzer->setzeZweiFaktor((int) $benutzer['id'], ZweiFaktor::METHODE_EMAIL, null);
        $this->audit->protokolliere((int) $benutzer['id'], '2fa_email_aktiviert', 'benutzer', (int) $benutzer['id']);
        $this->flash->erfolg('Die Zwei-Faktor-Anmeldung per E-Mail-Code ist jetzt aktiv.');

        return $response->withHeader('Location', '/profil')->withStatus(302);
    }

    public function deaktivieren(Request $request, Response $response): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        if (!is_array($benutzer)) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $this->benutzer->setzeZweiFaktor((int) $benutzer['id'], ZweiFaktor::METHODE_KEINE, null);
        $this->audit->protokolliere((int) $benutzer['id'], '2fa_deaktiviert', 'benutzer', (int) $benutzer['id']);
        $this->flash->warnung('Die Zwei-Faktor-Anmeldung wurde deaktiviert.');

        return $response->withHeader('Location', '/profil')->withStatus(302);
    }
}
