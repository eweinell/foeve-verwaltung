-- 005_antrag_warteseite.sql
-- Warteseite des Antragsdialogs (F2), übernommen aus der bestehenden Anmelde-App.
--
-- Die Altanwendung trennt bewusst zwei Tokens: `token` bestätigt den Antrag,
-- `resend_token` identifiziert die Warteseite. Ohne diese Trennung stünde das
-- Bestätigungstoken in der Warteseiten-URL und damit in Browser-Historie,
-- Lesezeichen und Referrer-Headern — jeder mit dieser URL könnte den Antrag
-- bestätigen. `erneut_gesendet_am` trägt die Sperrfrist fürs Nachsenden
-- (CLAUDE.md Regel 4: Mailversand drosseln).

ALTER TABLE antrag_rohdaten
    ADD COLUMN resend_token       CHAR(36) NULL AFTER bestaetigungs_token,
    ADD COLUMN erneut_gesendet_am DATETIME NULL AFTER bestaetigt_am,
    ADD UNIQUE KEY uq_antrag_resend (resend_token);

-- Vereins-Stammdaten aus der Anmelde-App übernehmen (003 legt sie leer an).
-- Antragsformular und DOI-Mail müssen die Gläubiger-ID im Mandatstext nennen;
-- ohne sie wäre das erteilte Mandat unvollständig. Nur setzen, solange die
-- Einstellung unberührt ist — ein Wert aus der Verwaltung gewinnt.
UPDATE einstellung SET wert = 'DE45ZZZ00000227982'
    WHERE schluessel = 'glaeubiger_id' AND wert = '';
UPDATE einstellung SET wert = 'Verein der Freunde und Förderer des Städtischen Gymnasiums Herzogenrath e.V.'
    WHERE schluessel = 'verein_name' AND wert = '';
