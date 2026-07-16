-- Migration 001 — Basisschema (AP0)
-- Tabellen: benutzer, audit_log, einstellung, versandaktion, email_queue.
-- Personen-/Mandats-Tabellen (mitglied, mandat, forderung …) folgen ab AP1.
-- Datenmodell: KONZEPT.md §5. Geldbeträge stets DECIMAL, nie Float (CLAUDE.md Regel 1).
--
-- Hinweis zu Erweiterungen gegenüber §5 (durch AP0-Briefing gefordert):
--   benutzer:    passwort_aendern_pflicht, zwei_faktor_methode, email_code_*,
--                fehlversuche, gesperrt_bis (Login-Throttling + optionale 2FA, F9).
--   email_queue: body_html, prioritaet, versuche (Priorität „sofort" für 2FA/DOI, F6).

CREATE TABLE benutzer (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                      VARCHAR(120) NOT NULL,
    email                     VARCHAR(190) NOT NULL,
    passwort_hash             VARCHAR(255) NOT NULL,
    rolle                     ENUM('admin','vorstand') NOT NULL DEFAULT 'vorstand',
    aktiv                     TINYINT(1) NOT NULL DEFAULT 1,
    passwort_aendern_pflicht  TINYINT(1) NOT NULL DEFAULT 0,
    zwei_faktor_methode       ENUM('keine','totp','email') NOT NULL DEFAULT 'keine',
    totp_secret               VARCHAR(64) NULL,
    email_code_hash           VARCHAR(255) NULL,
    email_code_bis            DATETIME NULL,
    fehlversuche              INT UNSIGNED NOT NULL DEFAULT 0,
    gesperrt_bis              DATETIME NULL,
    letzter_login             DATETIME NULL,
    created_at                DATETIME NOT NULL,
    updated_at                DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_benutzer_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id  INT UNSIGNED NULL,
    zeitpunkt    DATETIME NOT NULL,
    aktion       VARCHAR(80) NOT NULL,
    entitaet     VARCHAR(60) NULL,
    entitaet_id  VARCHAR(60) NULL,
    details      JSON NULL,
    PRIMARY KEY (id),
    KEY idx_audit_benutzer (benutzer_id),
    KEY idx_audit_aktion (aktion),
    KEY idx_audit_zeit (zeitpunkt),
    CONSTRAINT fk_audit_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE einstellung (
    schluessel  VARCHAR(80) NOT NULL,
    wert        TEXT NOT NULL,
    PRIMARY KEY (schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE versandaktion (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    typ              VARCHAR(60) NOT NULL,
    betreff          VARCHAR(255) NULL,
    erstellt_von     INT UNSIGNED NULL,
    erstellt_am      DATETIME NOT NULL,
    anzahl_gesamt    INT UNSIGNED NOT NULL DEFAULT 0,
    anzahl_gesendet  INT UNSIGNED NOT NULL DEFAULT 0,
    anzahl_fehler    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_versandaktion_benutzer FOREIGN KEY (erstellt_von) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_queue (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitglied_id       INT UNSIGNED NULL,
    versandaktion_id  INT UNSIGNED NULL,
    empfaenger        VARCHAR(190) NOT NULL,
    betreff           VARCHAR(255) NOT NULL,
    body              MEDIUMTEXT NOT NULL,
    body_html         MEDIUMTEXT NULL,
    anhang_pfad       VARCHAR(255) NULL,
    prioritaet        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status            ENUM('wartend','gesendet','fehler') NOT NULL DEFAULT 'wartend',
    fehltext          VARCHAR(500) NULL,
    versuche          INT UNSIGNED NOT NULL DEFAULT 0,
    geplant_ab        DATETIME NOT NULL,
    gesendet_am       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_queue_versand (status, prioritaet, geplant_ab),
    CONSTRAINT fk_queue_versandaktion FOREIGN KEY (versandaktion_id) REFERENCES versandaktion (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
