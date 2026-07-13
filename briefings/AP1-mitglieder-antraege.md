# AP1 — Mitglieder & Anträge

**Voraussetzung:** AP0 abgeschlossen (Auth, Versionierung, Krypto, Mail-Queue-Basis).
**Konzept-Referenzen:** KONZEPT.md §3.1 (Lebenszyklus), §3.5 (Beitragsänderung),
F1 (Mitgliederverwaltung inkl. Anrede-Logik und Grenzgänger), F2 (Antragseingang),
F10 (Historisierungs-UI), §5 (Datenmodell), §7 Q1/Q3/Q7/Q8/Q15.

## Ziel

Vollständige Mitgliederverwaltung mit Status-Lebenszyklus plus öffentliches
Antragsformular mit Double-Opt-In und Aktivierungs-Workflow.

## Scope

### 1. Datenmodell (Migrationen)

- `mitglied`, `mitglied_version`, `antrag_rohdaten` gemäß KONZEPT.md §5.
  `plz` als VARCHAR, `land CHAR(2) DEFAULT 'DE'`, `anrede ENUM(herr, frau, familie)`,
  `vorname` NULLABLE, `briefanrede_manuell`, `adresszeile_manuell`.
- Status-Enum: `unbestaetigt, beantragt, abgelehnt, verworfen, aktiv, gekuendigt,
  ausgeschieden, anonymisiert`.

### 2. Anrede- und Adress-Logik (zentraler Service, testgetrieben)

- `briefanrede(Mitglied)`: manuelle Überschreibung, sonst
  herr ⇒ „Sehr geehrter Herr {Nachname}", frau ⇒ „Sehr geehrte Frau {Nachname}",
  familie ⇒ „Sehr geehrte Familie {Nachname}".
- `adresszeile(Mitglied)`: Überschreibung, sonst familie ⇒ „Familie {Nachname}",
  sonst „{Vorname} {Nachname}" (ohne Vorname nur Nachname).
- `postanschrift(Mitglied)`: mehrzeilig; bei `land != DE` Länderzeile („Belgien",
  „Niederlande", sonst Ländername zu ISO-Code).
- PLZ-Validierung je Land: DE `\d{5}`, BE `\d{4}`, NL `\d{4}\s?[A-Z]{2}`; andere
  SEPA-Länder: nur nicht-leer.

### 3. Mitglieder-UI

- Liste: Suche (Name, Nummer, Ort, E-Mail), Filter (Status, Zahlweise, Land,
  E-Mail ja/nein, Beitragshöhe), Pagination, Sortierung. (Export-Buttons kommen in AP5 —
  Filterlogik so bauen, dass AP5 sie wiederverwenden kann.)
- Detailansicht mit Reitern: Stammdaten (bearbeiten), Mandate (Platzhalter bis AP2),
  Beiträge (Platzhalter bis AP2), E-Mail-Historie (Queue-Einträge des Mitglieds),
  Änderungshistorie.
- **Jede Änderung läuft über den Versionierungs-Service** (CLAUDE.md Regel 2).
- Änderungshistorie-UI: chronologisch, wer/wann/Felder alt → neu; „Auf diesen Stand
  zurücksetzen" (Vorwärts-Revert, Bestätigungsdialog); einzelne Felder aus einer
  Version übernehmbar. Revert löst keine Mails/Sollstellungen aus.
- Statusaktionen mit Bestätigungsdialog:
  - **Aktivieren** (aus `beantragt`): vergibt nächste Mitgliedsnummer (fortlaufend,
    Vergabe ab **2000**, vierstellig angezeigt; kollisionssicher bei parallelen
    Aktivierungen), setzt Eintrittsdatum (Default heute), reiht Begrüßungsmail ein
    (Template-Schlüssel `begruessung`; bis AP4 einfacher Fixtext), legt später das
    Mandat an (Hook für AP2 vorsehen). Sollstellung fürs laufende Jahr: Hook für AP2.
  - **Ablehnen** (aus `beantragt`), **Kündigung erfassen** (aus `aktiv`:
    Kündigungsdatum + Wirksamkeitsdatum, Default 31.12. lfd. Jahr; reiht
    Kündigungsbestätigung ein, Schlüssel `kuendigungsbestaetigung`),
    **Kündigung widerrufen**, **Austritt vollziehen** (⇒ `ausgeschieden`).
- Beitragsänderung (§3.5): eigenes Formular am Mitglied, validiert gegen
  Beitragsgrenzen (Einstellung `beitrag_min`, Default 12,00 €). Wenn AP2 schon da ist
  und eine offene Forderung des laufenden Jahres existiert: Rückfrage, ob sie angepasst
  wird; sonst nur künftige Sollstellungen.
- Flag „kein E-Mail-Kontakt": manuell; Warnhinweis, wenn gesetzt und E-Mail vorhanden
  bzw. leer und nicht gesetzt.

### 4. Öffentliches Antragsformular (F2) — Portierung der bestehenden Anmelde-App

**Quelle: `c:\users\erhard\dev\foeve-signupphp`** — eine produktive Standalone-App
(PHP ohne Framework) mit eigener Tabelle `mitglieder`. Der Flow ist erprobt und wird
**portiert, nicht neu erfunden**. Vorher lesen: `index.php` (Formular + Insert),
`bestaetigen.php` (DOI), `warten.php` (Warteseite/Resend), `mail_helper.php`,
`cleanup.php`, `styles.css`.

**Übernehmen (Verhalten beibehalten):**
- Formularfelder, Texte, Optik (`styles.css`, Logo) und clientseitige Validierung.
- **DOI-Mechanik inkl. der POST-Bestätigung**: Link aus der Mail zeigt eine Seite mit
  „Jetzt bestätigen"-Button; erst der POST bestätigt — Schutz gegen Mail-Scanner
  (Outlook Safe Links), die GET-Links automatisch abrufen. Nicht wegoptimieren.
- TrustCaptcha inkl. Score-Prüfung (CDN-Ausnahme nur für diese öffentliche Seite;
  Secret in `.env`).
- Warteseite mit Resend-Möglichkeit (`resend_token`-Konzept).
- Beitragswahl 12/30/60/120 €/Wunschbetrag (Bereich 12–500 € wie im Bestand).

**Ändern bei der Portierung:**
- Ziel-Schema: Verwaltungs-DB — `mitglied` (Status `unbestaetigt` ⇒ `beantragt`,
  `bestaetigt_am`) + `antrag_rohdaten` (Payload JSON, IP-Hash, Token) statt eigener
  `mitglieder`-Tabelle.
- Neues Feld **Land** (DE/BE/NL prominent) + PLZ-Validierung je Land (Abschnitt 2).
- IBAN-Prüfziffer (MOD-97) für **alle SEPA-Länder** — der Bestand prüft die Prüfsumme
  nur für DE-IBANs (`utils.php`); Logik in den zentralen Validierungs-Service heben.
- IBAN **verschlüsselt** ablegen (Bestand: Klartext), im Payload maskiert.
- Mails über die zentrale Queue (Priorität „sofort") statt PHPMailer-Direktversand;
  DOI-Mail enthält zusätzlich den vollständigen SEPA-Mandatstext.
- Konfiguration aus `.env` — **keine Zugangsdaten im Code** (der Bestand hat
  DB-/SMTP-Passwörter in `config.php`; nicht übernehmen).
- Rate-Limit pro IP ergänzen (z. B. 5 Anträge/Stunde).

**Entfällt (ersetzt durch die Verwaltung):**
- Admin-Benachrichtigungsmail mit redigierten Daten + `download.php`-CSV-Export und
  die 90-Tage-Löschung bestätigter Datensätze — bestätigte Anträge erscheinen
  stattdessen im Dashboard (Kachel „Offene Anträge") und bleiben als Mitglieder.
- `registrierung_statistik` (die Statistik kommt umfassender in AP5).
- Cleanup für Unbestätigte bleibt fachlich: > 30 Tage ⇒ Status `verworfen`
  (in `bin/wartung.php`, täglich), Token danach ungültig.

## Out of Scope

Mandats-CRUD (AP2), Sollstellung (AP2), Mail-Templates-UI (AP4), Exporte (AP5).

## Akzeptanzkriterien

1. Antrag über `/antrag` mit NL-Adresse (PLZ „6291 AB") und BE-IBAN durchläuft DOI und
   erscheint als `beantragt`; DE-Antrag mit falscher IBAN-Prüfziffer wird abgelehnt.
2. Aktivierung vergibt Nummern ab 2000 lückenlos aufsteigend; Begrüßungsmail liegt in
   der Queue; Audit-Log-Eintrag vorhanden.
3. Kündigung erfassen ⇒ Status `gekuendigt`, Wirksamkeitsdatum 31.12., Bestätigungsmail
   in Queue; Widerruf stellt `aktiv` wieder her; alles in der Änderungshistorie sichtbar.
4. Stammdatenänderung erzeugt Version mit korrektem Diff; Revert stellt alte Werte als
   neue Version her; IBAN taucht nirgends im Klartext auf (DB, Snapshots, Logs prüfen).
5. Anrede-Service: Unit-Tests für alle drei Anreden, Überschreibung, Adresszeile,
   Postanschrift mit Länderzeile, PLZ-Validierung DE/BE/NL.
6. Unbestätigter Antrag ist nach Ablauf (Wartungslauf) `verworfen`; Token danach ungültig.
7. Statusmaschine erlaubt nur die in §3.1 definierten Übergänge (Tests).
8. DOI: GET auf den Bestätigungslink bestätigt **nicht** (zeigt nur den Button);
   erst der POST bestätigt (Test) — Verhalten wie in der bestehenden App.
9. Keine Zugangsdaten/Secrets im Code oder Repo (Review); TrustCaptcha-Secret,
   DB und SMTP ausschließlich in `.env`.
