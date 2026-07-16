<?php

declare(strict_types=1);

/**
 * Legt einen Benutzer an (Bootstrap, z. B. den ersten Admin).
 *
 * Aufruf:
 *   php bin/benutzer-anlegen.php "Vorname Nachname" email@verein.de admin
 *
 * Das Passwort wird interaktiv abgefragt. Wird keins eingegeben, erzeugt das
 * Skript ein Einmal-Passwort und markiert das Konto für den Passwortwechsel.
 */

use App\Repository\BenutzerRepository;
use App\Support\Db;
use App\Support\Passwoerter;

$basis = dirname(__DIR__);
require $basis . '/vendor/autoload.php';

if (is_file($basis . '/.env')) {
    Dotenv\Dotenv::createImmutable($basis)->safeLoad();
}

$settings = require $basis . '/config/settings.php';
date_default_timezone_set((string) $settings['app']['timezone']);

$name  = $argv[1] ?? '';
$email = $argv[2] ?? '';
$rolle = $argv[3] ?? 'admin';

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($rolle, ['admin', 'vorstand'], true)) {
    fwrite(STDERR, "Aufruf: php bin/benutzer-anlegen.php \"Name\" email@verein.de [admin|vorstand]\n");
    exit(1);
}

$db = Db::ausDsn((string) $settings['db']['dsn'], (string) $settings['db']['user'], (string) $settings['db']['pass']);
$repo = new BenutzerRepository($db);

if ($repo->findePerEmail($email) !== null) {
    fwrite(STDERR, "Fehler: Es gibt bereits einen Benutzer mit dieser E-Mail.\n");
    exit(1);
}

fwrite(STDOUT, "Passwort (leer lassen für automatisches Einmal-Passwort): ");
$passwort = trim((string) fgets(STDIN));

if ($passwort === '') {
    $passwort = einmalPasswort();
    $pflicht = true;
    fwrite(STDOUT, "Erzeugtes Einmal-Passwort: {$passwort}\n");
} else {
    if (strlen($passwort) < 10) {
        fwrite(STDERR, "Fehler: Das Passwort muss mindestens 10 Zeichen lang sein.\n");
        exit(1);
    }
    $pflicht = false;
}

$id = $repo->anlegen($name, $email, Passwoerter::hash($passwort), $rolle, $pflicht);

fwrite(STDOUT, "Benutzer angelegt (ID {$id}, Rolle {$rolle}).\n");
if ($pflicht) {
    fwrite(STDOUT, "Beim ersten Login muss das Passwort geändert werden.\n");
}

function einmalPasswort(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}
