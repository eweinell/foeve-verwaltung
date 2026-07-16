<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$basis = dirname(__DIR__);

// --- Konfiguration aus .env laden (liegt außerhalb des Webroots) ---
if (is_file($basis . '/.env')) {
    Dotenv\Dotenv::createImmutable($basis)->safeLoad();
}

$settings = require $basis . '/config/settings.php';
date_default_timezone_set((string) $settings['app']['timezone']);

// --- Container & App ---
$container = (require $basis . '/config/container.php')($settings);
$app = (require $basis . '/config/app.php')($container, $settings);

$app->run();
