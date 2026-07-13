# AP0 — Projektgerüst, Auth, Versionierung, Mail-Queue-Basis

**Konzept-Referenzen:** KONZEPT.md §2 (Rollen), §6 (Technik), F9 (Login & Sicherheit),
F10 (Historisierung), F6 (E-Mail, nur Basis). Grundregeln: CLAUDE.md.

## Ziel

Lauffähiges Grundgerüst, auf dem alle weiteren APs aufbauen: Routing, Templates,
Migrationen, Login mit optionaler 2FA, Rollen, Audit-Log, Versionierungs-Service,
Krypto-Service für IBANs und ein minimaler Mail-Queue-Dienst.

## Scope

### 1. Projektstruktur & Basis

- Slim-4-App mit PSR-4 (`App\` → `src/`), PHP-DI-Container, Twig via `slim/twig-view`.
- `public/index.php` als einziger Einstieg; `.htaccess` für Apache (netcup).
- `config/` mit Container-Definitionen; `.env` via phpdotenv (`.env.example` committen).
- Fehlerbehandlung: hübsche 404/403/500-Seiten (deutsch), Fehlerdetails nur bei
  `APP_DEBUG=true`; Logging nach `var/log/` (Monolog oder minimaler PSR-3-Logger).
- Basis-Layout (Twig): Kopfnavigation (Mitglieder, Anträge, Beiträge, Einzug, E-Mail,
  Statistik, Einstellungen), Flash-Messages, schlichtes eigenes CSS oder lokal
  eingebundenes klassenloses CSS-Framework (z. B. Pico.css als lokale Datei). Deutsch,
  Sie-Form, brauchbar auf Tablet-Breite.

### 2. Migrationen

- `bin/migrate.php`: führt nummerierte SQL-Dateien aus `migrations/` in Reihenfolge aus,
  merkt sich den Stand in Tabelle `schema_migration`. Forward-only, kein Down.
- Migration 001: Tabellen `benutzer`, `audit_log`, `einstellung`, `email_queue`,
  `versandaktion` (Spalten gemäß KONZEPT.md §5; `mitglied`-Tabellen kommen in AP1).

### 3. Login & Sicherheit (F9)

- Login mit E-Mail/Benutzername + Passwort (Argon2id). Login-Throttling
  (z. B. 5 Fehlversuche ⇒ 15 Min. Sperre pro Konto, protokolliert).
- **Zweiter Faktor optional pro Benutzer**: TOTP (Einrichtung mit QR-Code) **oder**
  6-stelliger Bestätigungscode per E-Mail (10 Min. gültig, über die Mail-Queue mit
  Priorität „sofort"). Auswahl im eigenen Profil.
- **Kein Passwort-Reset per E-Mail.** Admin setzt ein Einmal-Passwort; Benutzer muss es
  beim nächsten Login ändern.
- Session: HttpOnly, Secure, SameSite=Lax, Regeneration nach Login; CSRF-Middleware für
  alle POST/PUT/DELETE; Security-Header (CSP ohne extern, X-Frame-Options DENY, …).
- Benutzerverwaltung (nur Rolle `admin`): anlegen, deaktivieren, Rolle setzen,
  Einmal-Passwort vergeben. Rollen: `admin`, `vorstand` (KONZEPT §2).
- Auth-Middleware: alles geschützt außer Login und den in CLAUDE.md genannten
  öffentlichen Routen (kommen in AP1).

### 4. Versionierungs-Service (F10, Infrastruktur)

- Wiederverwendbarer Service, z. B. `Versionierung::mitSnapshot($tabelle, $id, $benutzer,
  callable $update)`: liest aktuellen Datensatz, schreibt Snapshot (JSON) +
  `geaenderte_felder`-Diff in `{tabelle}_version`, führt dann das UPDATE aus —
  transaktional.
- Generisch genug für `mitglied` und `mandat` (Tabellen entstehen in AP1/AP2; hier mit
  einer Testtabelle in den Unit-Tests abdecken).
- Revert-Funktion: Snapshot als **neue** Version wiederherstellen (Vorwärts-Revert),
  Feld `ist_revert_von` setzen. Sensible Felder (IBAN) bleiben im Snapshot verschlüsselt.

### 5. Krypto-Service

- AES-256-GCM (libsodium bevorzugt: `sodium_crypto_secretbox` o. Ä.), Schlüssel aus
  `.env` (`APP_CRYPTO_KEY`), Helper `verschluesseln()/entschluesseln()/maskiereIban()`.
- `maskiereIban('DE89370400440532013000')` ⇒ `DE89 …… 3000`.

### 6. Mail-Queue-Basis (Minimalausbau, Vollausbau in AP4)

- Service `MailDienst::einreihen(empfaenger, betreff, textBody, htmlBody?, anhangPfad?,
  mitgliedId?, versandaktionId?, prioritaet?)` ⇒ Zeile in `email_queue`.
- `bin/mailqueue.php` (Cron, minütlich): versendet max. N Mails/Lauf (Einstellung
  `mail_rate_pro_minute`, Default 10) via `symfony/mailer` über SMTP aus `.env`;
  Status/Fehltext zurückschreiben, Mails mit Priorität „sofort" (2FA-Codes, DOI) zuerst.
- Keine Templates/Platzhalter/Versandaktions-UI — das ist AP4.

### 7. Audit-Log

- Service `Audit::protokolliere($aktion, $entitaet, $id, array $details)`;
  Aufrufe: Login ok/fehlgeschlagen, Sperre, Benutzeränderungen. Einfache Listenansicht
  (nur `admin`), neueste zuerst, Filter nach Benutzer/Aktion.

### 8. Einstellungen

- Key-Value-Tabelle `einstellung` + Einstellungsseite (nur `admin`) mit den ersten
  Werten: `mail_rate_pro_minute`, SMTP-Anzeige (readonly aus `.env`), Absendername/-adresse.

## Out of Scope

Mitglieder/Anträge (AP1), Mandate (AP2), SEPA (AP3), Templates & Versandaktionen (AP4),
Exporte/Statistik (AP5), Import/Backup/DSGVO (AP6).

## Akzeptanzkriterien

1. `composer install` + `bin/migrate.php` auf leerer DB ⇒ App läuft unter `public/`.
2. Admin-Benutzer per CLI-Skript anlegbar (`bin/benutzer-anlegen.php`).
3. Login funktioniert; nach 5 Fehlversuchen Sperre; Audit-Log zeigt beides.
4. TOTP einrichtbar und beim Login abgefragt; alternativ E-Mail-Code (landet in
   `email_queue` und wird vom Cron-Skript versendet — mit Mailhog/Mailpit testbar).
5. `vorstand` sieht keine Benutzerverwaltung/Einstellungen (403).
6. Versionierungs-Service: Unit-Tests für Snapshot, Diff, Revert, Transaktionsverhalten.
7. Krypto-Service: Roundtrip- und Maskierungs-Tests.
8. Kein externer HTTP-Request im Frontend (alle Assets lokal).
