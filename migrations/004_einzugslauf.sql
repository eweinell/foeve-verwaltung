-- Migration 004 — SEPA-Einzugslauf (AP3)
-- Tabelle einzugslauf (KONZEPT §5); forderung.einzugslauf_id (aus Migration 003)
-- verknüpft Forderungen mit genau einem Lauf (verhindert Doppel-Einzug).

CREATE TABLE einzugslauf (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bezeichnung       VARCHAR(160) NOT NULL,
    faelligkeitsdatum DATE NOT NULL,
    status            ENUM('entwurf','angekuendigt','exportiert','abgeschlossen') NOT NULL DEFAULT 'entwurf',
    summe             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    anzahl            INT UNSIGNED NOT NULL DEFAULT 0,
    anzahl_email      INT UNSIGNED NOT NULL DEFAULT 0,
    anzahl_post       INT UNSIGNED NOT NULL DEFAULT 0,
    xml_erzeugt_am    DATETIME NULL,
    xml_pfad          VARCHAR(255) NULL,
    angekuendigt_am   DATETIME NULL,
    abgeschlossen_am  DATETIME NULL,
    erstellt_von      INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_einzugslauf_status (status),
    CONSTRAINT fk_einzugslauf_benutzer FOREIGN KEY (erstellt_von) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign key von forderung.einzugslauf_id auf einzugslauf (Tabelle existiert nun).
ALTER TABLE forderung
    ADD CONSTRAINT fk_forderung_einzugslauf FOREIGN KEY (einzugslauf_id) REFERENCES einzugslauf (id) ON DELETE SET NULL;

-- Einstellungen: Pre-Notification-Frist und Rücklastschrift-Gebühr.
INSERT INTO einstellung (schluessel, wert) VALUES
    ('prenotification_tage', '14'),
    ('ruecklastschrift_gebuehr', '0.00');
