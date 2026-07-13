# CLAUDE.md — Grundregeln foeve-verwaltung

Vereinsverwaltung für einen Förderverein (~500 Mitglieder), bedient von 2–5
Vorstandsmitgliedern. Fachkonzept: `KONZEPT.md` (maßgeblich bei Widersprüchen).
Arbeitspakete: `briefings/AP0…AP6`. Hosting: netcup Shared Hosting.

## Stack (fest entschieden — nicht ersetzen)

- PHP 8.3+, MariaDB 10.6+, Slim 4 + Twig (`slim/twig-view`), PDO (kein ORM),
  `vlucas/phpdotenv`, `symfony/mailer`, `abcaeffchen/sephpa`, `openspout/openspout`,
  `robthree/twofactorauth`, `endroid/qr-code`, PHPUnit.
- Serverseitig gerendert, wenig JS. Keine SPA, kein Build-Toolchain-Zwang,
  **keine CDN-Einbindungen** — alle Assets (CSS, JS, Chart.js) liegen lokal.
  Einzige Ausnahme: das TrustCaptcha-Skript auf der öffentlichen Antragsseite
  (aus der bestehenden Anmelde-App übernommen).
- Verzeichnisse: `public/` (einziger Webroot), `src/` (PSR-4 `App\`), `templates/`,
  `migrations/` (nummerierte SQL-Dateien, forward-only), `bin/` (Cron-/CLI-Skripte),
  `config/`, `tests/`.

## Sprache

- UI, Twig-Templates, E-Mails, Fehlermeldungen: **Deutsch, Sie-Form**.
- Code: PSR-12; Domänenbegriffe deutsch (`Mitglied`, `Forderung`, `Einzugslauf`,
  `Sollstellung`), technische Helfer/Framework-Code englisch ist ok.
- DB-Tabellen/Spalten deutsch, wie im Datenmodell in `KONZEPT.md` §5.

## Unverhandelbare Regeln

1. **Geldbeträge nie als Float.** DB: `DECIMAL(8,2)`; PHP: Integer-Cents oder Strings.
2. **Schreibzugriffe auf `mitglied` und `mandat` nur über den Versionierungs-Service**
   (F10): erst Snapshot in `*_version`, dann UPDATE — nie ein nacktes UPDATE.
   Forderungen/Einzugsläufe sind unveränderlich; Korrektur nur per Storno-Datensatz.
3. **IBAN nie im Klartext** — nicht in DB (AES-256-GCM, Schlüssel in `.env`), nicht in
   Logs, nicht in Versions-Snapshots, nicht in Exporten (maskiert: `DE12 …… 3456`),
   Ausnahme: SEPA-XML.
4. **E-Mails nur über die Queue** (`email_queue` + Cron), nie direkt per SMTP aus dem
   Request senden. Drosselung konfigurierbar (Default 10/min).
5. **Auth überall:** Jede Route läuft hinter Auth-Middleware, außer: Login,
   Antragsformular (`/antrag`), DOI-Bestätigungslink. Rollen: `admin`, `vorstand`.
   Kein Passwort-Reset per E-Mail — Reset nur durch Admin.
6. **CSRF-Token auf jedem POST**, Session-Cookies HttpOnly/Secure/SameSite=Lax,
   Passwörter Argon2id, Login-Throttling.
7. **Audit-Log** für: Logins, Exporte, Versandaktionen, Einzugslauf-Statuswechsel,
   Aktivierung/Ablehnung von Anträgen.
8. Zeitzone **Europe/Berlin** (PHP + DB-Verbindung), Datumsformat in der UI `TT.MM.JJJJ`.
9. `.env` niemals committen (`.env.example` pflegen); keine Secrets in Code oder Logs.
10. Anrede/Adressen: Es gibt Familien als Mitglieder und Grenzgänger (BE/NL) —
    Briefanreden nur über die zentrale Anrede-Logik (F1), PLZ-Validierung je Land,
    IBAN-Validierung für alle SEPA-Länder.

## Tests & Qualität

- PHPUnit-Tests verpflichtend für Domänenlogik: Anrede-Erzeugung, IBAN-/PLZ-Validierung,
  FRST/RCUR-Bestimmung, Sollstellungs-Idempotenz, Beitragsänderungs-Regeln (§3.5),
  Statusmaschinen (Mitglied, Forderung, Einzugslauf).
- Migrationen müssen auf leerer DB von 0 an durchlaufen (`bin/migrate.php`).
- Keine toten Konfigurationsschalter, keine spekulativen Abstraktionen — das System
  soll jahrelang mit minimaler Pflege laufen.

## Arbeitsweise

- Vor jedem Arbeitspaket das zugehörige Briefing in `briefings/` und die referenzierten
  KONZEPT-Abschnitte lesen.
- Bestehende Konventionen (Namen, Struktur, Fehlerbehandlung) aus AP0 übernehmen,
  nicht neu erfinden.
- Bei fachlichen Widersprüchen oder Lücken: nicht raten — Frage im PR/Ergebnis
  dokumentieren und die konservativste Variante umsetzen.
