<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repository\BenutzerRepository;
use App\Support\Session;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Auth-Middleware (CLAUDE.md Regel 5): schützt alle Routen der internen App.
 * Öffentliche Routen (Login, später Antrag/DOI) liegen außerhalb dieser Gruppe.
 *
 * Erzwingt außerdem den Passwortwechsel bei Einmal-Passwörtern und wirft
 * deaktivierte Konten sofort aus.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const SESSION_BENUTZER = 'benutzer_id';

    /** Pfade, die auch bei Passwort-Änderungszwang erreichbar bleiben. */
    private const AUSNAHMEN_PW = ['/passwort-aendern', '/logout'];

    public function __construct(
        private readonly Session $session,
        private readonly BenutzerRepository $benutzer,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $id = $this->session->get(self::SESSION_BENUTZER);
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return $this->zumLogin();
        }

        $benutzer = $this->benutzer->findePerId((int) $id);
        if ($benutzer === null || (int) $benutzer['aktiv'] !== 1) {
            $this->session->beenden();

            return $this->zumLogin();
        }

        $pfad = $request->getUri()->getPath();
        if ((int) $benutzer['passwort_aendern_pflicht'] === 1 && !in_array($pfad, self::AUSNAHMEN_PW, true)) {
            return $this->responseFactory->createResponse(302)->withHeader('Location', '/passwort-aendern');
        }

        // Benutzer für Controller/Templates bereitstellen.
        $request = $request->withAttribute('benutzer', $benutzer);

        return $handler->handle($request);
    }

    private function zumLogin(): Response
    {
        return $this->responseFactory->createResponse(302)->withHeader('Location', '/login');
    }
}
