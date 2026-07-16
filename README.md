# foeve-verwaltung

Vereinsverwaltung für den Förderverein Gymnasium Herzogenrath (~500 Mitglieder).
Fachkonzept: [`KONZEPT.md`](KONZEPT.md), Grundregeln: [`CLAUDE.md`](CLAUDE.md),
Arbeitspakete: [`briefings/`](briefings/).

Stack: PHP 8.3+, MariaDB 10.6+, Slim 4 + Twig, PDO (kein ORM). Serverseitig
gerendert, keine CDN-Einbindungen. Stand: **AP2 (Mandate & Sollstellung)**.

## Was in AP2 enthalten ist

- Datenmodell `mandat`, `mandat_version`, `forderung` (Migration 003) mit
  DB-seitiger Absicherung: höchstens ein aktives Mandat je Mitglied,
  idempotente Beitrags-Sollstellung (UNIQUE über `beitrag_jahr`).
- SEPA-Mandate (F3): automatische Anlage bei Aktivierung (aus dem Antrag,
  Referenz `FGH-{Nr}-{lfd}`), manuelle Anlage, **Bankwechsel** (neues Mandat,
  altes deaktiviert), Widerruf (⇒ Selbstzahler), Verfall-Hinweis nach 36 Monaten.
  IBAN verschlüsselt, Anzeige maskiert; Änderungen über die Versionierung.
- Sollstellung (F4): Jahres-Vorschau + idempotente Ausführung, Einzelsollstellung
  bei Aktivierung, Beitragsänderung nach §3.5 (offene Forderung optional anpassen),
  Storno statt Löschen, Selbstzahler auf „bezahlt" setzen, Gebühren-Forderung.
- Übersicht „Offene Posten" mit Filtern und Summe; Mitglied-Reiter „Mandate" und
  „Beiträge". Vereins-Stammdaten (Gläubiger-ID, Name, IBAN verschlüsselt, BIC)
  in den Einstellungen.

## Was in AP1 enthalten ist

- Datenmodell `mitglied`, `mitglied_version`, `antrag_rohdaten` (Migration 002),
  Status-Lebenszyklus mit erzwungener Statusmaschine (§3.1).
- Zentrale Anrede-/Adress-Logik (Briefanrede, Adresszeile, Postanschrift mit
  Länderzeile) und Validierung (PLZ je Land DE/BE/NL, IBAN MOD-97 SEPA-weit).
- Mitglieder-UI: Liste mit Suche/Filter/Pagination, Detailansicht mit Reitern,
  **versionierte** Stammdaten-/Beitragsänderung, Änderungshistorie mit Revert,
  Statusaktionen (Aktivieren mit Nummernvergabe ab 2000, Ablehnen, Kündigen,
  Widerruf, Austritt), Flag „kein E-Mail-Kontakt".
- Öffentliches Antragsformular `/antrag` mit Double-Opt-In (Bestätigung per
  **POST**, GET zeigt nur den Button), IBAN verschlüsselt/maskiert, DOI-Mail mit
  SEPA-Mandatstext über die Queue, Rate-Limit pro IP, optionaler TrustCaptcha.
- Dashboard-Kachel „Offene Anträge"; `bin/wartung.php` verwirft unbestätigte
  Anträge > 30 Tage.

## Was in AP0 enthalten ist

- Slim-4-Grundgerüst (Front-Controller `public/index.php`, PHP-DI, Twig-Layout
  auf Basis von `design/styleguide.html`), deutsche Fehlerseiten, Datei-Logger.
- Migrations-Runner (`bin/migrate.php`, forward-only) + Migration 001
  (`benutzer`, `audit_log`, `einstellung`, `versandaktion`, `email_queue`).
- Login mit Argon2id, Login-Throttling (5 Fehlversuche ⇒ 15 Min. Sperre),
  **optionalem zweiten Faktor** (TOTP per App **oder** Code per E-Mail),
  Einmal-Passwort mit Änderungszwang, kein Passwort-Reset per E-Mail.
- Rollen `admin`/`vorstand`, Auth-/CSRF-/Security-Header-Middleware.
- Versionierungs-Service (F10), Krypto-Service (IBAN, libsodium),
  Audit-Log, Mail-Queue-Basis (`bin/mailqueue.php`), Einstellungen.

## Einrichtung (lokal / netcup)

```bash
composer install
cp .env.example .env          # Werte setzen (DB, APP_CRYPTO_KEY, MAIL_DSN …)

# 32-Byte-Krypto-Schlüssel erzeugen und in .env als APP_CRYPTO_KEY eintragen:
php -r "echo base64_encode(sodium_crypto_secretbox_keygen()), PHP_EOL;"

php bin/migrate.php                                   # Schema anlegen
php bin/benutzer-anlegen.php "Admin" admin@verein.de admin   # ersten Admin
```

Der Webroot ist **ausschließlich** `public/`. `.env` liegt außerhalb und wird
nie committet.

### Cron (netcup)

```
* * * * * /usr/bin/php /pfad/zu/bin/mailqueue.php >> /pfad/zu/var/log/mailqueue.log 2>&1
```

## Tests

```bash
composer test        # PHPUnit: Krypto, Versionierung, Login-Throttling, Mail-Queue
```

Die Unit-Tests laufen gegen SQLite (In-Memory); produktiv läuft dieselbe
PDO-generische Logik auf MariaDB (Schema in `migrations/001_basis.sql`).
