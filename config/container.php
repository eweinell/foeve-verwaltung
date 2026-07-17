<?php

declare(strict_types=1);

use App\Repository\BenutzerRepository;
use App\Service\Audit;
use App\Service\Einstellungen;
use App\Service\Krypto;
use App\Service\LoginThrottle;
use App\Service\MailDienst;
use App\Service\Versionierung;
use App\Service\ZweiFaktor;
use App\Support\Ansicht;
use App\Support\Csrf;
use App\Support\DateiLogger;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Session;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

/**
 * PHP-DI-Container. Autowiring ist aktiv; hier werden nur die Dienste definiert,
 * die Konfigurationswerte oder eine spezielle Konstruktion brauchen.
 *
 * @param array<string,mixed> $settings
 */
return static function (array $settings): ContainerInterface {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $builder->addDefinitions([
        'settings' => $settings,

        Db::class => static fn () => Db::ausDsn(
            (string) $settings['db']['dsn'],
            (string) $settings['db']['user'],
            (string) $settings['db']['pass'],
        ),

        LoggerInterface::class => static fn () => new DateiLogger((string) $settings['pfade']['log']),

        Session::class => static fn () => new Session((bool) $settings['session']['secure']),

        Csrf::class => static fn (ContainerInterface $c) => new Csrf($c->get(Session::class)),

        Flash::class => static fn (ContainerInterface $c) => new Flash($c->get(Session::class)),

        Krypto::class => static fn () => new Krypto((string) $settings['crypto']['key']),

        Versionierung::class => static fn (ContainerInterface $c) => new Versionierung($c->get(Db::class)),

        Audit::class => static fn (ContainerInterface $c) => new Audit($c->get(Db::class)),

        Einstellungen::class => static fn (ContainerInterface $c) => new Einstellungen($c->get(Db::class)),

        MailDienst::class => static fn (ContainerInterface $c) => new MailDienst($c->get(Db::class)),

        BenutzerRepository::class => static fn (ContainerInterface $c) => new BenutzerRepository($c->get(Db::class)),

        LoginThrottle::class => static fn (ContainerInterface $c) => new LoginThrottle($c->get(BenutzerRepository::class)),

        ZweiFaktor::class => static fn (ContainerInterface $c) => new ZweiFaktor(
            $c->get(BenutzerRepository::class),
            $c->get(MailDienst::class),
            $c->get(Einstellungen::class),
            $c->get(App\Service\VorlagenService::class),
            (string) ($settings['mail']['absender_name'] ?: 'Vereinsverwaltung'),
        ),

        Twig::class => static fn () => Twig::create((string) $settings['pfade']['templates'], [
            'cache'      => false,
            'autoescape' => 'html',
        ]),

        Ansicht::class => static fn (ContainerInterface $c) => new Ansicht(
            $c->get(Twig::class),
            $c->get(Csrf::class),
            $c->get(Flash::class),
            $c->get(Session::class),
            $c->get(BenutzerRepository::class),
        ),

        ResponseFactory::class => static fn () => new ResponseFactory(),

        App\Kernel\FehlerHandler::class => static fn (ContainerInterface $c) => new App\Kernel\FehlerHandler(
            $c->get(Twig::class),
            $c->get(ResponseFactory::class),
            $c->get(LoggerInterface::class),
            (bool) $settings['app']['debug'],
        ),

        App\Service\Captcha::class => static fn (ContainerInterface $c) => new App\Service\Captcha(
            (string) $settings['captcha']['sitekey'],
            (string) $settings['captcha']['secret'],
            $c->get(LoggerInterface::class),
        ),

        App\Service\EinzugslaufService::class => static fn (ContainerInterface $c) => new App\Service\EinzugslaufService(
            $c->get(Db::class),
            $c->get(App\Repository\EinzugslaufRepository::class),
            $c->get(App\Repository\ForderungRepository::class),
            $c->get(App\Service\MandatService::class),
            $c->get(App\Service\SollstellungService::class),
            $c->get(Krypto::class),
            $c->get(MailDienst::class),
            $c->get(App\Service\AnredeDienst::class),
            $c->get(App\Service\VorlagenService::class),
            $c->get(Einstellungen::class),
            $c->get(App\Service\SepaXmlValidator::class),
            $c->get(Audit::class),
            (string) $settings['pfade']['basis'],
        ),

        App\Service\AntragService::class => static fn (ContainerInterface $c) => new App\Service\AntragService(
            $c->get(Db::class),
            $c->get(App\Repository\MitgliedRepository::class),
            $c->get(App\Repository\AntragRepository::class),
            $c->get(Krypto::class),
            $c->get(MailDienst::class),
            $c->get(Versionierung::class),
            $c->get(Audit::class),
            $c->get(Einstellungen::class),
            $c->get(App\Service\VorlagenService::class),
            (string) $settings['app']['url'],
            (string) $settings['crypto']['key'],
        ),

        // Controller mit Konfigurationsbedarf.
        App\Controller\EmailController::class => static fn (ContainerInterface $c) => new App\Controller\EmailController(
            $c->get(Ansicht::class),
            $c->get(Flash::class),
            $c->get(App\Service\VersandaktionService::class),
            $c->get(App\Service\VorlagenService::class),
            $c->get(App\Repository\MitgliedRepository::class),
            $c->get(MailDienst::class),
            (string) $settings['pfade']['basis'],
        ),

        App\Controller\EinstellungenController::class => static fn (ContainerInterface $c) => new App\Controller\EinstellungenController(
            $c->get(Ansicht::class),
            $c->get(Flash::class),
            $c->get(Einstellungen::class),
            $c->get(Audit::class),
            $c->get(Krypto::class),
            (array) $settings['mail'],
        ),
    ]);

    return $builder->build();
};
