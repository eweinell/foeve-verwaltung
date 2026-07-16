<?php

declare(strict_types=1);

/**
 * Mail-Queue-Versand (Cron, minütlich). Versendet gedrosselt bis zu
 * mail_rate_pro_minute Mails je Lauf über SMTP (MAIL_DSN aus .env), Priorität
 * „sofort" zuerst. Status/Fehltext werden zurückgeschrieben.
 *
 * Crontab (netcup), jede Minute:
 *   * * * * * /usr/bin/php /pfad/zu/bin/mailqueue.php >> /pfad/zu/var/log/mailqueue.log 2>&1
 */

use App\Service\Einstellungen;
use App\Service\MailDienst;
use App\Support\Db;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

$basis = dirname(__DIR__);
require $basis . '/vendor/autoload.php';

if (is_file($basis . '/.env')) {
    Dotenv\Dotenv::createImmutable($basis)->safeLoad();
}

$settings = require $basis . '/config/settings.php';
date_default_timezone_set((string) $settings['app']['timezone']);

if (($settings['mail']['dsn'] ?? '') === '') {
    fwrite(STDERR, "Fehler: MAIL_DSN ist nicht gesetzt (.env).\n");
    exit(1);
}

$db = Db::ausDsn((string) $settings['db']['dsn'], (string) $settings['db']['user'], (string) $settings['db']['pass']);
$mailDienst = new MailDienst($db);
$einstellungen = new Einstellungen($db);

$rate = $einstellungen->holeInt('mail_rate_pro_minute', 10);
$absenderAdresse = $einstellungen->hole('absender_adresse', (string) $settings['mail']['absender_adresse']);
$absenderName = $einstellungen->hole('absender_name', (string) $settings['mail']['absender_name']);

$mailer = new Mailer(Transport::fromDsn((string) $settings['mail']['dsn']));

$wartende = $mailDienst->naechsteWartende($rate);
if ($wartende === []) {
    exit(0);
}

$ok = 0;
$fehler = 0;
foreach ($wartende as $mail) {
    try {
        $nachricht = (new Email())
            ->from(new Symfony\Component\Mime\Address($absenderAdresse, $absenderName))
            ->to((string) $mail['empfaenger'])
            ->subject((string) $mail['betreff'])
            ->text((string) $mail['body']);

        if (!empty($mail['body_html'])) {
            $nachricht->html((string) $mail['body_html']);
        }
        if (!empty($mail['anhang_pfad']) && is_file((string) $mail['anhang_pfad'])) {
            $nachricht->attachFromPath((string) $mail['anhang_pfad']);
        }

        $mailer->send($nachricht);
        $mailDienst->alsGesendet((int) $mail['id']);
        $ok++;
    } catch (\Throwable $e) {
        $mailDienst->alsFehler((int) $mail['id'], $e->getMessage());
        $fehler++;
    }
}

fwrite(STDOUT, sprintf("[%s] versendet: %d, Fehler: %d\n", (new DateTimeImmutable('now'))->format('H:i'), $ok, $fehler));
