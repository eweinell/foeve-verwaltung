-- Migration 002 — Mitglieder & Anträge (AP1)
-- Tabellen: mitglied, mitglied_version, antrag_rohdaten (KONZEPT §5).
-- IBAN wird nicht am Mitglied gespeichert (eigene Entität mandat ab AP2);
-- der Antrag legt die IBAN verschlüsselt in antrag_rohdaten.payload ab.

CREATE TABLE mitglied (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitgliedsnummer       INT UNSIGNED NULL,
    status                ENUM('unbestaetigt','beantragt','abgelehnt','verworfen',
                               'aktiv','gekuendigt','ausgeschieden','anonymisiert')
                          NOT NULL DEFAULT 'beantragt',
    anrede                ENUM('herr','frau','familie') NOT NULL DEFAULT 'familie',
    vorname               VARCHAR(120) NULL,
    nachname              VARCHAR(120) NOT NULL,
    briefanrede_manuell   VARCHAR(255) NULL,
    adresszeile_manuell   VARCHAR(255) NULL,
    strasse               VARCHAR(180) NULL,
    plz                   VARCHAR(12) NULL,
    ort                   VARCHAR(120) NULL,
    land                  CHAR(2) NOT NULL DEFAULT 'DE',
    email                 VARCHAR(190) NULL,
    kein_email_kontakt    TINYINT(1) NOT NULL DEFAULT 0,
    telefon               VARCHAR(60) NULL,
    jahresbeitrag         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    zahlweise             ENUM('lastschrift','selbstzahler') NOT NULL DEFAULT 'lastschrift',
    eintrittsdatum        DATE NULL,
    austrittsdatum        DATE NULL,
    kuendigung_am         DATE NULL,
    wirksam_zum           DATE NULL,
    bestaetigt_am         DATETIME NULL,
    notizen               TEXT NULL,
    created_at            DATETIME NOT NULL,
    updated_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mitglied_nummer (mitgliedsnummer),
    KEY idx_mitglied_status (status),
    KEY idx_mitglied_nachname (nachname),
    KEY idx_mitglied_ort (ort),
    KEY idx_mitglied_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mitglied_version (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitglied_id        INT UNSIGNED NOT NULL,
    version_nr         INT UNSIGNED NOT NULL,
    snapshot           JSON NOT NULL,
    geaenderte_felder  JSON NOT NULL,
    geaendert_von      INT UNSIGNED NULL,
    geaendert_am       DATETIME NOT NULL,
    ist_revert_von     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_mversion_mitglied (mitglied_id, version_nr),
    CONSTRAINT fk_mversion_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE,
    CONSTRAINT fk_mversion_benutzer FOREIGN KEY (geaendert_von) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE antrag_rohdaten (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitglied_id          INT UNSIGNED NULL,
    eingegangen_am       DATETIME NOT NULL,
    ip_hash              CHAR(64) NULL,
    payload              JSON NOT NULL,
    bestaetigungs_token  CHAR(64) NOT NULL,
    bestaetigt_am        DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_antrag_token (bestaetigungs_token),
    KEY idx_antrag_mitglied (mitglied_id),
    KEY idx_antrag_ip (ip_hash, eingegangen_am),
    CONSTRAINT fk_antrag_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Einstellungen für Beitragsgrenzen/-stufen (KONZEPT §1).
INSERT INTO einstellung (schluessel, wert) VALUES
    ('beitrag_min', '12.00'),
    ('beitrag_max', '500.00'),
    ('beitrag_stufen', '12,30,60,120');
