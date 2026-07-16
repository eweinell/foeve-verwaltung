-- Migration 003 — SEPA-Mandate & Sollstellung (AP2)
-- Tabellen: mandat, mandat_version, forderung (KONZEPT §5).
-- IBAN wird verschlüsselt gespeichert (CLAUDE.md Regel 3), Anzeige maskiert.

CREATE TABLE mandat (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitglied_id         INT UNSIGNED NOT NULL,
    lfd_nr              INT UNSIGNED NOT NULL DEFAULT 1,
    mandatsreferenz     VARCHAR(40) NOT NULL,
    iban_verschluesselt TEXT NOT NULL,
    bic                 VARCHAR(11) NULL,
    kontoinhaber        VARCHAR(160) NOT NULL,
    erteilt_am          DATE NULL,
    status              ENUM('erteilt','aktiv','inaktiv','widerrufen') NOT NULL DEFAULT 'aktiv',
    zuletzt_genutzt_am  DATE NULL,
    sequenz_genutzt     TINYINT(1) NOT NULL DEFAULT 0,
    -- Absicherung „höchstens ein aktives Mandat je Mitglied": trägt die mitglied_id
    -- nur bei status='aktiv', sonst NULL (mehrere NULL sind erlaubt).
    aktiv_mitglied      INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mandat_referenz (mandatsreferenz),
    UNIQUE KEY uq_mandat_aktiv (aktiv_mitglied),
    KEY idx_mandat_mitglied (mitglied_id),
    CONSTRAINT fk_mandat_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mandat_version (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mandat_id          INT UNSIGNED NOT NULL,
    version_nr         INT UNSIGNED NOT NULL,
    snapshot           JSON NOT NULL,
    geaenderte_felder  JSON NOT NULL,
    geaendert_von      INT UNSIGNED NULL,
    geaendert_am       DATETIME NOT NULL,
    ist_revert_von     BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_mandatversion (mandat_id, version_nr),
    CONSTRAINT fk_mandatversion_mandat FOREIGN KEY (mandat_id) REFERENCES mandat (id) ON DELETE CASCADE,
    CONSTRAINT fk_mandatversion_benutzer FOREIGN KEY (geaendert_von) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forderung (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mitglied_id    INT UNSIGNED NOT NULL,
    jahr           SMALLINT UNSIGNED NOT NULL,
    betrag         DECIMAL(8,2) NOT NULL,
    typ            ENUM('beitrag','gebuehr') NOT NULL DEFAULT 'beitrag',
    status         ENUM('offen','im_einzug','bezahlt','ruecklastschrift','storniert') NOT NULL DEFAULT 'offen',
    einzugslauf_id INT UNSIGNED NULL,
    bezahlt_am     DATE NULL,
    zahlungsart    ENUM('lastschrift','bar','ueberweisung') NULL,
    -- Idempotenz der Sollstellung: nur für typ='beitrag' gesetzt (= jahr), sonst NULL.
    beitrag_jahr   SMALLINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forderung_beitrag (mitglied_id, beitrag_jahr),
    KEY idx_forderung_mitglied (mitglied_id),
    KEY idx_forderung_jahr_status (jahr, status),
    CONSTRAINT fk_forderung_mitglied FOREIGN KEY (mitglied_id) REFERENCES mitglied (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vereins-Stammdaten (leer vorbelegt; verein_iban wird verschlüsselt gespeichert).
INSERT INTO einstellung (schluessel, wert) VALUES
    ('glaeubiger_id', ''),
    ('verein_name', ''),
    ('verein_iban', ''),
    ('verein_bic', '');
