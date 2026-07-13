# AP6 — Altdatenimport & Betrieb

**Voraussetzung:** AP1–AP3 (Import braucht Mitglieder-, Mandats- und
Forderungs-Strukturen). Letztes Arbeitspaket vor Go-Live.
**Konzept-Referenzen:** KONZEPT.md §7 Q3/Q4/Q12/Q13, F10, §5.

## Ziel

Einmaliger, sicherer Altdatenimport (Mitglieder + Mandate), Betriebsautomatisierung
(Backup, Wartung, DSGVO) und Deployment-Dokumentation für netcup.

## Scope

### 1. Altdatenimport (CSV)

- CLI-Skript `bin/import.php <datei.csv>` + Admin-UI-Seite mit Upload; beide nutzen
  denselben Import-Service.
- **Zwei Phasen, immer:** (1) **Probelauf** (Dry-Run, Default): vollständiger
  Validierungsbericht ohne Schreiben — pro Zeile ok/Warnung/Fehler mit Begründung;
  (2) **Import** nur, wenn der Probelauf fehlerfrei ist (Warnungen erlaubt), in einer
  Transaktion, wiederholbar erst nach Rollback/Leerung.
- Spaltenformat dokumentieren (`briefings/import-format.md` beim Umsetzen anlegen und
  mit dem Vorstand abstimmen — die konkrete Quellstruktur des Altbestands ist noch
  offen): Stammdaten inkl. Anrede (herr/frau/familie), Land, bestehende
  **Mitgliedsnummer** (bleibt erhalten; alle < 2000 erwartet, Kollisionen = Fehler),
  Eintrittsdatum, ggf. Austrittsdatum/Status, Jahresbeitrag, Zahlweise, E-Mail,
  „kein E-Mail-Kontakt", IBAN, Kontoinhaber, **bestehende Mandatsreferenz**,
  Mandats-Erteilungsdatum, **Merker „Mandat bereits genutzt"** (⇒ `sequenz_genutzt`,
  steuert FRST/RCUR — Default: genutzt = true, damit importierte Mandate als RCUR
  laufen, Q4).
- Validierung: IBAN-Prüfziffer (alle SEPA-Länder), PLZ je Land, Pflichtfelder,
  doppelte Nummern/Referenzen (in Datei und DB), Beitragsgrenze.
- Importierte Datensätze erhalten Version 1 im Versionierungs-System
  (Quelle „Import {Datum}") und einen Audit-Log-Eintrag (Zeilenzahl, Datei-Hash).
- Nummernkreis-Automatik prüfen: nach Import startet die Vergabe bei
  max(2000, höchste Bestandsnummer + 1).

### 2. Betriebs-Crons

- `bin/backup.php` (täglich): mysqldump, symmetrisch verschlüsselt (Key aus `.env`),
  Rotation (z. B. 14 täglich, 12 monatlich), Ablage außerhalb des Webroots;
  Erfolg/Fehlschlag in `einstellung` (`letztes_backup_am`) ⇒ Dashboard-Warnung, wenn
  älter als 48 h. Restore-Anleitung dokumentieren **und einmal durchspielen**.
- `bin/wartung.php` (täglich, aus AP1 erweitern): verworfene Anträge, 36-Monats-Check
  Mandate, DSGVO-Vorschlagslauf (s. u.).
- Cron-Einrichtung für netcup dokumentieren (Kommandos, Zeitpläne, PHP-CLI-Pfad).

### 3. DSGVO-Läufe (Q13)

- Vorschlagslauf: Mitglieder `ausgeschieden` seit > 2 Jahren ⇒ Liste „zur
  Anonymisierung vorgeschlagen" (Admin-Seite).
- Anonymisierung **nur nach Admin-Bestätigung** (einzeln oder gesammelt):
  Stammdaten reduziert auf „Mitglied {Nr}, anonymisiert", E-Mail/Adresse/Telefon/
  Notizen geleert, Mandats-IBAN/Kontoinhaber gelöscht, **Versions-Snapshots des
  Mitglieds und Queue-Einträge mit anonymisiert**, `antrag_rohdaten` gelöscht.
  Forderungen/Einzugslauf-Positionen bleiben (10 Jahre, nur noch mit Nummer verknüpft);
  Status ⇒ `anonymisiert`, irreversibel (doppelter Bestätigungsdialog).
- Löschlauf für Forderungsdaten > 10 Jahre (Vorschlag + Bestätigung, analog).

### 4. Deployment & Betriebsdoku (`docs/betrieb.md`)

- netcup-Deployment: Composer via SSH, Webroot auf `public/` legen (Subdomain-Doc-Root),
  `.env`-Anlage, Migrationslauf, Cron-Einrichtung, SPF/DKIM-Checkliste für die
  Absenderdomain, TLS.
- Checkliste Go-Live: Import-Probelauf, Import, Stichprobe (10 Mitglieder gegen
  Altliste), Testeinzug mit Kleinbetrag-Testlauf/XML-Prüfung durch die Bank,
  Backup-Restore-Test, Benutzeranlage Vorstand.
- Update-Prozess: `composer update`-Strategie, Migrationen, Backup davor.

## Out of Scope

CAMT-Import, automatische Bounce-Verarbeitung, Mehrmandantenfähigkeit.

## Akzeptanzkriterien

1. Probelauf einer Beispieldatei mit absichtlichen Fehlern (kaputte IBAN, doppelte
   Nummer, NL-PLZ falsch) listet alle Fehler mit Zeilennummer; es wird nichts geschrieben.
2. Fehlerfreier Import: Mitglieder mit Alt-Nummern und Mandate mit Alt-Referenzen
   (`sequenz_genutzt = true`) vorhanden; nächster Einzugslauf stuft sie als RCUR ein;
   nächste Aktivierung vergibt Nummer ≥ 2000.
3. Import bricht bei Fehler in Zeile n atomar ab (Transaktionstest).
4. Backup-Skript erzeugt verschlüsseltes Dump-Archiv; dokumentierter Restore in leere
   DB ergibt lauffähiges System.
5. DSGVO-Anonymisierung: keine personenbezogenen Daten mehr in `mitglied`,
   `mitglied_version`, `mandat`, `email_queue`, `antrag_rohdaten` (Test); Forderungen
   bleiben zählbar für die Statistik.
6. `docs/betrieb.md` reicht aus, um das System auf netcup ohne weiteres Wissen zu
   installieren (Review durch zweite Person/Agent).
