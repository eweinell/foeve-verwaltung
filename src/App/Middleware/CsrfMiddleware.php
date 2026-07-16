<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * CSRF-Schutz für alle zustandsändernden Methoden (CLAUDE.md Regel 6).
 * Token kommt aus dem Formularfeld _csrf und wird gegen die Session geprüft.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const GESCHUETZT = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (in_array(strtoupper($request->getMethod()), self::GESCHUETZT, true)) {
            $daten = (array) $request->getParsedBody();
            $token = $daten[Csrf::FELDNAME] ?? null;

            if (!$this->csrf->pruefe(is_string($token) ? $token : null)) {
                $response = $this->responseFactory->createResponse(419);
                $response->getBody()->write(
                    '<!doctype html><meta charset="utf-8"><title>Sitzung abgelaufen</title>'
                    . '<p>Das Sicherheits-Token war ungültig oder Ihre Sitzung ist abgelaufen. '
                    . 'Bitte laden Sie die Seite neu und versuchen Sie es erneut.</p>'
                );

                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        }

        return $handler->handle($request);
    }
}
