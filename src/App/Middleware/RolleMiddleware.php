<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpForbiddenException;

/**
 * Rollenschutz (KONZEPT §2): beschränkt eine Routengruppe auf bestimmte Rollen.
 * Läuft NACH der AuthMiddleware, nutzt das benutzer-Attribut. Verstoß ⇒ 403.
 */
final class RolleMiddleware implements MiddlewareInterface
{
    /** @var array<int,string> */
    private array $erlaubt;

    public function __construct(string ...$erlaubteRollen)
    {
        $this->erlaubt = $erlaubteRollen;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $benutzer = $request->getAttribute('benutzer');
        $rolle = is_array($benutzer) ? ($benutzer['rolle'] ?? null) : null;

        if (!is_string($rolle) || !in_array($rolle, $this->erlaubt, true)) {
            throw new HttpForbiddenException($request, 'Für diesen Bereich fehlt Ihnen die Berechtigung.');
        }

        return $handler->handle($request);
    }
}
