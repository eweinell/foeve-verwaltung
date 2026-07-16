<?php

declare(strict_types=1);

namespace App\Kernel;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use Throwable;

/**
 * Einheitliche Fehlerseiten (deutsch): 403, 404, 405, 419, 500. Details nur bei
 * APP_DEBUG=true; ansonsten wird der Fehler geloggt und eine neutrale Seite gezeigt.
 */
final class FehlerHandler
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ResponseFactory $responseFactory,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): Response {
        [$status, $template, $titel] = match (true) {
            $exception instanceof HttpNotFoundException          => [404, 'fehler/404.twig', 'Seite nicht gefunden'],
            $exception instanceof HttpForbiddenException         => [403, 'fehler/403.twig', 'Kein Zugriff'],
            $exception instanceof HttpMethodNotAllowedException  => [405, 'fehler/500.twig', 'Methode nicht erlaubt'],
            default                                              => [500, 'fehler/500.twig', 'Ein Fehler ist aufgetreten'],
        };

        if ($status >= 500) {
            $this->logger->error('Unbehandelter Fehler: {msg}', [
                'msg'  => $exception->getMessage(),
                'typ'  => $exception::class,
                'pfad' => $request->getUri()->getPath(),
            ]);
        }

        $response = $this->responseFactory->createResponse($status);

        try {
            return $this->twig->render($response, $template, [
                'titel'        => $titel,
                'status'       => $status,
                'debug'        => $this->debug,
                'meldung'      => $this->debug ? $exception->getMessage() : null,
                'spur'         => $this->debug ? $exception->getTraceAsString() : null,
            ]);
        } catch (Throwable) {
            // Fallback, falls das Template selbst scheitert.
            $response->getBody()->write('<!doctype html><meta charset="utf-8"><title>' . $titel . '</title><h1>' . $status . ' — ' . $titel . '</h1>');

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
    }
}
