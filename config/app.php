<?php

declare(strict_types=1);

use App\Kernel\FehlerHandler;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Support\Session;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Baut die Slim-App auf: Middleware-Stack, Fehlerbehandlung und Routen.
 * Von public/index.php (Produktion) und vom Smoke-Test gemeinsam genutzt,
 * damit beide exakt dieselbe Konfiguration erhalten.
 *
 * @param array<string,mixed> $settings
 */
return static function (ContainerInterface $container, array $settings): App {
    AppFactory::setContainer($container);
    $app = AppFactory::create();

    // Session früh starten (gehärtete Cookie-Parameter).
    $container->get(Session::class)->starten();

    // Reihenfolge: CSRF wird VOR dem Body-Parsing hinzugefügt, damit es NACH dem
    // Parsen ausgeführt wird und den geparsten Body prüfen kann.
    $app->add($container->get(CsrfMiddleware::class));
    $app->add($container->get(SecurityHeadersMiddleware::class));
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();

    $debug = (bool) $settings['app']['debug'];
    $errorMiddleware = $app->addErrorMiddleware($debug, true, true, $container->get(LoggerInterface::class));
    $errorMiddleware->setDefaultErrorHandler($container->get(FehlerHandler::class));

    (require dirname(__DIR__) . '/config/routes.php')($app);

    return $app;
};
