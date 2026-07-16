<?php

declare(strict_types=1);

/**
 * Wartungslauf (Cron, täglich). Verwirft unbestätigte Anträge, die älter als
 * 30 Tage sind (Status ⇒ verworfen), und entwertet deren Bestätigungstoken (F2).
 *
 * Crontab (netcup), täglich z. B. 03:15:
 *   15 3 * * * /usr/bin/php /pfad/zu/bin/wartung.php >> /pfad/zu/var/log/wartung.log 2>&1
 */

use App\Service\MitgliedService;

$basis = dirname(__DIR__);
require $basis . '/vendor/autoload.php';

if (is_file($basis . '/.env')) {
    Dotenv\Dotenv::createImmutable($basis)->safeLoad();
}

$settings = require $basis . '/config/settings.php';
date_default_timezone_set((string) $settings['app']['timezone']);

$container = (require $basis . '/config/container.php')($settings);

/** @var MitgliedService $service */
$service = $container->get(MitgliedService::class);
$anzahl = $service->verwerfeUnbestaetigte(30);

fwrite(STDOUT, sprintf("[%s] verworfene unbestätigte Anträge: %d\n", (new DateTimeImmutable('now'))->format('Y-m-d H:i'), $anzahl));
