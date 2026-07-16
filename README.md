# foeve-verwaltung

Vereinsverwaltung für den Förderverein Gymnasium Herzogenrath (~500 Mitglieder).
Fachkonzept: [`KONZEPT.md`](KONZEPT.md), Grundregeln: [`CLAUDE.md`](CLAUDE.md),
Arbeitspakete: [`briefings/`](briefings/).

Stack: PHP 8.3+, MariaDB 10.6+, Slim 4 + Twig, PDO (kein ORM). Serverseitig
gerendert, keine CDN-Einbindungen. Stand: **AP0 (Projektgerüst)**.

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
