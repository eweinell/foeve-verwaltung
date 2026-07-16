<?php

declare(strict_types=1);

namespace App\Support;

use App\Repository\BenutzerRepository;
use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;

/**
 * Rendert Twig-Templates und stellt dabei die überall benötigten Variablen bereit:
 * CSRF-Token, Flash-Messages und den angemeldeten Benutzer.
 */
final class Ansicht
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Session $session,
        private readonly BenutzerRepository $benutzer,
    ) {
    }

    /**
     * @param array<string,mixed> $daten
     */
    public function render(Response $response, string $template, array $daten = []): Response
    {
        $basis = [
            'csrf_feld'          => Csrf::FELDNAME,
            'csrf_token'         => $this->csrf->token(),
            'flashes'            => $this->flash->abholen(),
            'aktueller_benutzer' => $this->aktuellerBenutzer(),
            'aktuelle_seite'     => $daten['aktuelle_seite'] ?? null,
        ];

        return $this->twig->render($response, $template, $basis + $daten);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function aktuellerBenutzer(): ?array
    {
        $id = $this->session->get(AuthMiddleware::SESSION_BENUTZER);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $this->benutzer->findePerId((int) $id);
    }
}
