<?php

declare(strict_types=1);

/**
 * Migrations-Runner (forward-only). Führt die nummerierten SQL-Dateien aus
 * migrations/ in Reihenfolge aus und merkt sich den Stand in schema_migration.
 * Bereits angewendete Migrationen werden übersprungen (idempotent).
 *
 * Aufruf:  php bin/migrate.php
 */

use App\Support\Db;

$basis = dirname(__DIR__);
require $basis . '/vendor/autoload.php';

if (is_file($basis . '/.env')) {
    Dotenv\Dotenv::createImmutable($basis)->safeLoad();
}

$settings = require $basis . '/config/settings.php';
date_default_timezone_set((string) $settings['app']['timezone']);

if (($settings['db']['dsn'] ?? '') === '') {
    fwrite(STDERR, "Fehler: DB_DSN ist nicht gesetzt (.env).\n");
    exit(1);
}

$db = Db::ausDsn((string) $settings['db']['dsn'], (string) $settings['db']['user'], (string) $settings['db']['pass']);

echo "Migrationen werden geprüft …\n";
sicherstelleMigrationstabelle($db);

$angewendet = [];
foreach ($db->alleZeilen('SELECT version FROM schema_migration') as $zeile) {
    $angewendet[(string) $zeile['version']] = true;
}

$dateien = glob($basis . '/migrations/*.sql') ?: [];
sort($dateien, SORT_STRING);

$anzahl = 0;
foreach ($dateien as $datei) {
    $version = basename($datei);
    if (isset($angewendet[$version])) {
        continue;
    }

    echo "  → wende an: {$version}\n";
    $sql = (string) file_get_contents($datei);

    foreach (statementsAusfuehren($sql) as $statement) {
        try {
            $db->pdo()->exec($statement);
        } catch (\PDOException $e) {
            fwrite(STDERR, "Fehler in {$version}: {$e->getMessage()}\n");
            exit(1);
        }
    }

    $db->ausfuehren(
        'INSERT INTO schema_migration (version, angewendet_am) VALUES (:v, :t)',
        ['v' => $version, 't' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')],
    );
    $anzahl++;
}

echo $anzahl === 0 ? "Alles aktuell — nichts zu tun.\n" : "Fertig: {$anzahl} Migration(en) angewendet.\n";

/**
 * Legt die Verwaltungstabelle an, falls sie fehlt (portabel für MariaDB/SQLite).
 */
function sicherstelleMigrationstabelle(Db $db): void
{
    $db->pdo()->exec(
        'CREATE TABLE IF NOT EXISTS schema_migration (
            version VARCHAR(190) NOT NULL,
            angewendet_am DATETIME NOT NULL,
            PRIMARY KEY (version)
        )'
    );
}

/**
 * Zerlegt eine SQL-Datei in einzelne Statements. Zeilenkommentare (--) werden
 * entfernt; getrennt wird am Semikolon (unsere DDL enthält keine Semikola in
 * Werten oder Routinen).
 *
 * @return array<int,string>
 */
function statementsAusfuehren(string $sql): array
{
    $zeilen = [];
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $zeile) {
        $getrimmt = ltrim($zeile);
        if (str_starts_with($getrimmt, '--')) {
            continue;
        }
        $zeilen[] = $zeile;
    }
    $ohneKommentare = implode("\n", $zeilen);

    $statements = [];
    foreach (explode(';', $ohneKommentare) as $teil) {
        if (trim($teil) !== '') {
            $statements[] = trim($teil);
        }
    }

    return $statements;
}
