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

        foreach (self::schemaStatements() as $ddl) {
            $pdo->exec($ddl);
        }

        return new Db($pdo);
    }

    /**
     * Portables Schema (SQLite) — öffentlich, damit auch der Smoke-Test es nutzen kann.
     *
     * @return array<int,string>
     */
    public static function schemaStatements(): array
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
            // Mitglieder & Anträge (AP1) — portabel zu migrations/002_mitglied.sql.
            'CREATE TABLE mitglied (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitgliedsnummer INTEGER NULL UNIQUE,
                status TEXT NOT NULL DEFAULT "beantragt",
                anrede TEXT NOT NULL DEFAULT "familie",
                vorname TEXT NULL,
                nachname TEXT NOT NULL,
                briefanrede_manuell TEXT NULL,
                adresszeile_manuell TEXT NULL,
                strasse TEXT NULL,
                plz TEXT NULL,
                ort TEXT NULL,
                land TEXT NOT NULL DEFAULT "DE",
                email TEXT NULL,
                kein_email_kontakt INTEGER NOT NULL DEFAULT 0,
                telefon TEXT NULL,
                jahresbeitrag TEXT NOT NULL DEFAULT "0.00",
                zahlweise TEXT NOT NULL DEFAULT "lastschrift",
                eintrittsdatum TEXT NULL,
                austrittsdatum TEXT NULL,
                kuendigung_am TEXT NULL,
                wirksam_zum TEXT NULL,
                bestaetigt_am TEXT NULL,
                notizen TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE mitglied_version (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitglied_id INTEGER NOT NULL,
                version_nr INTEGER NOT NULL,
                snapshot TEXT NOT NULL,
                geaenderte_felder TEXT NOT NULL,
                geaendert_von INTEGER NULL,
                geaendert_am TEXT NOT NULL,
                ist_revert_von INTEGER NULL
            )',
            'CREATE TABLE antrag_rohdaten (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitglied_id INTEGER NULL,
                eingegangen_am TEXT NOT NULL,
                ip_hash TEXT NULL,
                payload TEXT NOT NULL,
                bestaetigungs_token TEXT NOT NULL UNIQUE,
                bestaetigt_am TEXT NULL
            )',
            // SEPA-Mandate & Sollstellung (AP2) — portabel zu migrations/003.
            'CREATE TABLE mandat (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitglied_id INTEGER NOT NULL,
                lfd_nr INTEGER NOT NULL DEFAULT 1,
                mandatsreferenz TEXT NOT NULL UNIQUE,
                iban_verschluesselt TEXT NOT NULL,
                bic TEXT NULL,
                kontoinhaber TEXT NOT NULL,
                erteilt_am TEXT NULL,
                status TEXT NOT NULL DEFAULT "aktiv",
                zuletzt_genutzt_am TEXT NULL,
                sequenz_genutzt INTEGER NOT NULL DEFAULT 0,
                aktiv_mitglied INTEGER NULL UNIQUE,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
            'CREATE TABLE mandat_version (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mandat_id INTEGER NOT NULL,
                version_nr INTEGER NOT NULL,
                snapshot TEXT NOT NULL,
                geaenderte_felder TEXT NOT NULL,
                geaendert_von INTEGER NULL,
                geaendert_am TEXT NOT NULL,
                ist_revert_von INTEGER NULL
            )',
            'CREATE TABLE forderung (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mitglied_id INTEGER NOT NULL,
                jahr INTEGER NOT NULL,
                betrag TEXT NOT NULL,
                typ TEXT NOT NULL DEFAULT "beitrag",
                status TEXT NOT NULL DEFAULT "offen",
                einzugslauf_id INTEGER NULL,
                bezahlt_am TEXT NULL,
                zahlungsart TEXT NULL,
                beitrag_jahr INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (mitglied_id, beitrag_jahr)
            )',
            // Einzugslauf (AP3) — portabel zu migrations/004.
            'CREATE TABLE einzugslauf (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bezeichnung TEXT NOT NULL,
                faelligkeitsdatum TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "entwurf",
                summe TEXT NOT NULL DEFAULT "0.00",
                anzahl INTEGER NOT NULL DEFAULT 0,
                anzahl_email INTEGER NOT NULL DEFAULT 0,
                anzahl_post INTEGER NOT NULL DEFAULT 0,
                xml_erzeugt_am TEXT NULL,
                xml_pfad TEXT NULL,
                angekuendigt_am TEXT NULL,
                abgeschlossen_am TEXT NULL,
                erstellt_von INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        ];
    }
}
