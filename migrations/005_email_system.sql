-- Migration 005 — E-Mail-System (AP4)
-- email_vorlage speichert Overrides/eigene Vorlagen; die Default-Texte der
-- Systemvorlagen liegen im Code (App\Domain\SystemVorlagen) und sind über die
-- UI überschreibbar. versandaktion und email_queue werden für Massenversand
-- und Wiederholung ergänzt.

CREATE TABLE email_vorlage (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schluessel  VARCHAR(60) NOT NULL,
    betreff     VARCHAR(255) NOT NULL,
    body_text   MEDIUMTEXT NOT NULL,
    body_html   MEDIUMTEXT NULL,
    system      TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vorlage_schluessel (schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versandaktion: Vorlage und ein gemeinsamer PDF-Anhang je Aktion.
ALTER TABLE versandaktion
    ADD COLUMN vorlage_schluessel VARCHAR(60) NULL AFTER typ,
    ADD COLUMN anhang_pfad VARCHAR(255) NULL AFTER betreff;

-- E-Mail-Queue: Zeitpunkt des nächsten Versuchs (für Retry nach temporärem Fehler).
ALTER TABLE email_queue
    ADD COLUMN naechster_versuch DATETIME NULL AFTER geplant_ab;

-- Reply-To (From/Absender liegen bereits als absender_* vor).
INSERT INTO einstellung (schluessel, wert) VALUES ('mail_reply_to', '');
