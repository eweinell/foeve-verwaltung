<?php

declare(strict_types=1);

/**
 * Mail-Queue-Versand (Cron, minütlich, AP4). Versendet gedrosselt bis zu
 * mail_rate_pro_minute Mails je Lauf über SMTP (MAIL_DSN aus .env), Priorität
 * „sofort" zuerst, dann FIFO. Temporäre SMTP-Fehler (4xx) werden einmal nach
 * 15 Minuten wiederholt, permanente (5xx) als Fehler markiert. Ein Datei-Lock
 * (flock) verhindert, dass sich parallele Cron-Läufe überschneiden.
 *
 * Crontab (netcup), jede Minute:
 *   * * * * * /usr/bin/php /pfad/zu/bin/mailqueue.php >> /pfad/zu/var/log/mailqueue.log 2>&1
 */

use App\Service\Einstellungen;
use App\Service\MailDienst;
use App\Support\Db;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
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

// Lock gegen parallele Läufe.
$lockVerzeichnis = $basis . '/var';
if (!is_dir($lockVerzeichnis)) {
    @mkdir($lockVerzeichnis, 0770, true);
}
$lock = fopen($lockVerzeichnis . '/mailqueue.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Ein anderer Lauf ist aktiv — übersprungen.\n");
    exit(0);
}

$db = Db::ausDsn((string) $settings['db']['dsn'], (string) $settings['db']['user'], (string) $settings['db']['pass']);
$mailDienst = new MailDienst($db);
$einstellungen = new Einstellungen($db);

$rate = $einstellungen->holeInt('mail_rate_pro_minute', 10);
$absenderAdresse = $einstellungen->hole('absender_adresse', (string) $settings['mail']['absender_adresse']);
$absenderName = $einstellungen->hole('absender_name', (string) $settings['mail']['absender_name']);
$replyTo = $einstellungen->hole('mail_reply_to', '');

$mailer = new Mailer(Transport::fromDsn((string) $settings['mail']['dsn']));

$sender = static function (array $mail) use ($mailer, $absenderAdresse, $absenderName, $replyTo): array {
    try {
        $nachricht = (new Email())
            ->from(new Address($absenderAdresse, $absenderName))
            ->to((string) $mail['empfaenger'])
            ->subject((string) $mail['betreff'])
            ->text((string) $mail['body']);

        if ($replyTo !== '') {
            $nachricht->replyTo($replyTo);
        }
        if (!empty($mail['body_html'])) {
            $nachricht->html((string) $mail['body_html']);
        }
        if (!empty($mail['anhang_pfad']) && is_file((string) $mail['anhang_pfad'])) {
            $nachricht->attachFromPath((string) $mail['anhang_pfad']);
        }

        $mailer->send($nachricht);

        return ['status' => 'ok'];
    } catch (TransportExceptionInterface $e) {
        $code = $e->getCode();
        // 4xx (oder Verbindungsfehler, Code 0) = temporär ⇒ ein Retry; 5xx = permanent.
        $temporaer = $code === 0 || ($code >= 400 && $code < 500);

        return ['status' => $temporaer ? 'temp' : 'perm', 'fehltext' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['status' => 'perm', 'fehltext' => $e->getMessage()];
    }
};

$ergebnis = $mailDienst->verarbeite($rate, $sender);

// Zeitstempel des letzten Laufs (Dashboard-Überwachung).
$einstellungen->setze('mail_letzter_lauf', (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));

flock($lock, LOCK_UN);
fclose($lock);

fwrite(STDOUT, sprintf(
    "[%s] versendet: %d, Wiederholung: %d, Fehler: %d\n",
    (new DateTimeImmutable('now'))->format('H:i'),
    $ergebnis['gesendet'],
    $ergebnis['wiederholung'],
    $ergebnis['fehler'],
));
