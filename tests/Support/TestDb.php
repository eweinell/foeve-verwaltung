<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Db;
use PDO;

/**
 * Erzeugt eine In-Memory-SQLite-Datenbank mit portablem Schema für die Tests.
 * Die Fachlogik (Versionierung, Repositories) ist PDO-generisch; produktiv läuft
 * dieselbe Logik auf MariaDB (Schema in migrations/001_basis.sql).
 */
final class TestDb
{
    public static function erstellen(): Db
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach (self::schema() as $ddl) {
            $pdo->exec($ddl);
        }

        return new Db($pdo);
    }

    /**
     * @return array<int,string>
     */
    private static function schema(): array
    {
        return [
            'CREATE TABLE benutzer (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                passwort_hash TEXT NOT NULL,
                rolle TEXT NOT NULL DEFAULT "vorstand",
                aktiv INTEGER NOT NULL DEFAULT 1,
                passwort_aendern_pflicht INTEGER NOT NULL DEFAULT 0,
                zwei_faktor_methode TEXT NOT NULL DEFAULT "keine",
                totp_secret TEXT NULL,
                email_code_hash TEXT NULL,
                email_code_bis TEXT NULL,
                fehlversuche INTEGER NOT NULL DEFAULT 0,
                gesperrt_bis TEXT NULL,
                letzter_login TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                benutzer_id INTEGER NULL,
                zeitpunkt TEXT NOT NULL,
                aktion TEXT NOT NULL,
                entitaet TEXT NULL,
                entitaet_id TEXT NULL,
                details TEXT NULL
            )',
            'CREATE TABLE einstellung (
                schluessel TEXT PRIMARY KEY,
                wert TEXT NOT NULL
            )',
            'CREATE TABLE email_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitglied_id INTEGER NULL,
                versandaktion_id INTEGER NULL,
                empfaenger TEXT NOT NULL,
                betreff TEXT NOT NULL,
                body TEXT NOT NULL,
                body_html TEXT NULL,
                anhang_pfad TEXT NULL,
                prioritaet INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT "wartend",
                fehltext TEXT NULL,
                versuche INTEGER NOT NULL DEFAULT 0,
                geplant_ab TEXT NOT NULL,
                gesendet_am TEXT NULL
            )',
            // Test-Zieltabelle + Versionstabelle für den Versionierungs-Service (F10).
            'CREATE TABLE testperson (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                iban_verschluesselt TEXT NULL,
                jahresbeitrag TEXT NOT NULL DEFAULT "0.00",
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
            'CREATE TABLE testperson_version (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                testperson_id INTEGER NOT NULL,
                version_nr INTEGER NOT NULL,
                snapshot TEXT NOT NULL,
                geaenderte_felder TEXT NOT NULL,
                geaendert_von INTEGER NULL,
                geaendert_am TEXT NOT NULL,
                ist_revert_von INTEGER NULL
            )',
        ];
    }
}
