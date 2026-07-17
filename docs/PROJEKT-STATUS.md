# Projekt-Status & Handoff — foeve-verwaltung

Stand: 2026-07-17. Dieses Dokument fasst den Umsetzungsstand zusammen, damit eine
neue Sitzung ohne Kontextverlust weiterarbeiten kann. Maßgeblich bleiben
`KONZEPT.md` (Fachkonzept), `CLAUDE.md` (Grundregeln) und `briefings/AP0…AP6`.

## Fortschritt der Arbeitspakete

| AP | Titel | Status | PR |
|----|-------|--------|-----|
| AP0 | Projektgerüst, Auth, Versionierung, Mail-Queue-Basis | ✅ gemergt | #1 |
| AP1 | Mitglieder & Anträge (F1/F2, DOI) | ✅ gemergt | #2 |
| AP2 | SEPA-Mandate & Sollstellung (F3/F4) | ✅ gemergt | #3 |
| AP3 | SEPA-Export & Einzugslauf (F5, pain.008) | ✅ gemergt | #4 |
| AP4 | E-Mail-System (Templates, Versandaktionen, Protokoll) | ⬜ offen | — |
| AP5 | Exporte, Statistik & Post-Workflow (F7/F8/F11) | ⬜ offen | — |
| AP6 | Altdatenimport & Betrieb (CSV-Import, Backup, DSGVO) | ⬜ offen | — |

Reihenfolge laut `briefings/README.md`: AP0 → AP1 → AP2 → AP3 fertig.
**AP4 und AP5 sind ab jetzt parallelisierbar** (gut für zwei separate Agenten in
eigenen Worktrees/Branches). AP6 zum Schluss.

## Was funktioniert (Kern-Pipeline F1–F5)

Antrag (`/antrag`, Double-Opt-In per POST) → Mitglied → Aktivierung (Mitgliedsnummer
ab 2000, Mandat aus Antrag, Einzelsollstellung, Begrüßungsmail) → Sollstellung
(idempotent) → Einzugslauf (Pre-Notification → **pain.008-XML**, XSD-validiert →
Abschluss → Rücklastschrift). Login mit optionaler 2FA, Rollen admin/vorstand,
Versionierung/Historie für Mitglied & Mandat, Audit-Log.

## Architektur & Konventionen (für die Fortsetzung wichtig)

- **Stack:** Slim 4 + Twig + PHP-DI, PDO (kein ORM). Einstieg `public/index.php` →
  `config/app.php` (Middleware + Routen) → `config/routes.php`. DI in
  `config/container.php` (Autowiring an; nur Dienste mit Config-Bedarf explizit).
- **Schichten:** `src/App/Controller` (dünn), `Service` (Fachlogik), `Repository`
  (SQL, portabel), `Domain` (Statusmaschinen/Wertlogik), `Support` (Db, Session,
  Csrf, Flash, Ansicht, Passwoerter, Logger, EndroidQrProvider).
- **Grundregeln (CLAUDE.md) sind eingehalten und müssen es bleiben:**
  - Geldbeträge als DECIMAL/String bzw. Integer-Cents, nie Float.
  - Schreibzugriffe auf `mitglied`/`mandat` **nur** über `Versionierung::mitSnapshot()`
    (Snapshot + Diff + Revert). Forderungen/Einzugsläufe unveränderlich → Storno,
    kein DELETE.
  - IBAN nie im Klartext: verschlüsselt (libsodium `secretbox`, `Krypto`),
    angezeigt maskiert (`DE12 …… 3456`); Klartext nur im SEPA-XML.
  - E-Mails nur über `MailDienst::einreihen()` (Queue) + `bin/mailqueue.php`.
  - CSRF auf jedem POST, Argon2id, Security-Header (CSP ohne extern, Ausnahme nur
    `/antrag` für TrustCaptcha), Audit-Log für sicherheitsrelevante Aktionen.
  - UI/E-Mails deutsch, Sie-Form; Zeitzone Europe/Berlin; Datumsformat TT.MM.JJJJ.
- **Neue Fachlogik immer testgetrieben** und über die bestehenden Services/Hooks
  einhängen (z. B. Aktivierungs-Hook in `MitgliedService::aktivieren`).

## Tests & lokaler Betrieb

- **Tests:** `composer test` bzw. `vendor/bin/phpunit` — laufen gegen **SQLite
  in-memory** (ein Prozess). Portables Schema: `tests/Support/TestDb.php`
  (`schemaStatements()`), gespiegelt zu `migrations/00X_*.sql` (MariaDB, produktiv).
  Aktueller Stand: **74 Tests grün**. Zusätzlich existierte ein End-to-End-Smoke-Test
  über den echten HTTP-Stack (49 Checks) — er lag im Scratchpad, nicht im Repo.
- **Lokaler Dev-Server gegen SQLite-Datei** (echtes `:memory:` überlebt keine
  HTTP-Requests, da pro Request eine neue Verbindung entsteht — daher Datei):
  1. `.env` mit `DB_DSN="sqlite:$(pwd)/var/dev.sqlite"`, generiertem
     `APP_CRYPTO_KEY` (`php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"`)
     und `SESSION_SECURE=false`.
  2. Schema einspielen (SQLite): portables Schema aus
     `App\Tests\Support\TestDb::schemaStatements()` gegen die DB ausführen
     (`bin/migrate.php` ist MariaDB-DDL und läuft **nicht** auf SQLite).
  3. Admin anlegen: `php bin/benutzer-anlegen.php "Dev Admin" admin@example.de admin`.
  4. `php -S 127.0.0.1:8080 -t public`.
  - Produktiv: MariaDB + `php bin/migrate.php`.

## Bewusste Entscheidungen / Abweichungen (dokumentiert)

- **Krypto:** libsodium `secretbox` (XSalsa20-Poly1305) statt AES-256-GCM — als
  authentifizierte Verschlüsselung gewählt (CLAUDE.md-Regel 3 nennt AES-GCM; die
  Wahl wurde bewusst getroffen).
- **Schema-Erweiterungen ggü. KONZEPT §5** (durch Briefings gefordert, in den
  Migrations-Headern dokumentiert): `benutzer` (Throttling/2FA/Einmalpasswort),
  `email_queue` (`body_html`, `prioritaet`, `versuche`), Idempotenz-/Aktiv-Marker
  in `forderung`/`mandat`.
- **DB-Sicherungen:** höchstens ein aktives Mandat je Mitglied
  (`mandat.aktiv_mitglied` UNIQUE), Sollstellungs-Idempotenz
  (`forderung.beitrag_jahr` UNIQUE), eine Forderung in nur einem Lauf
  (`forderung.einzugslauf_id` FK).

## Offene Punkte / Empfehlungen für die nächste Sitzung

1. **AP4 (E-Mail-System)** ersetzt die aktuellen Fixtexte durch Templates mit
   Platzhaltern: Template-Schlüssel sind bereits vorgemerkt — `begruessung`,
   `kuendigungsbestaetigung` (`MitgliedService`), `prenotification`
   (`EinzugslaufService`). Versandaktionen + Protokoll ausbauen.
2. **AP5 (Exporte/Statistik/Post):** die Filterlogik in `MitgliedRepository`
   (`filterKlausel`) und die Brief-Liste des Einzugslaufs sind auf Wiederverwendung
   ausgelegt.
3. **TrustCaptcha (F2):** ist optional/konfigurierbar und aktuell aus. Das genaue
   Verifikations-API sollte gegen die bestehende Anmelde-App `foeve-signupphp`
   bzw. die TrustCaptcha-Doku abgeglichen werden (im Code als Hinweis markiert).
   Die App `foeve-signupphp` lag bisher nicht vor — Antrags-/DOI-Flow wurde aus der
   Spezifikation umgesetzt; bei Verfügbarkeit Optik/Texte angleichen.
4. **Dev-Komfort (optional):** ein committetes `bin/dev-init.php` + `.env.dev.example`
   würde den lokalen SQLite-Start zum Einzeiler machen (statt Schema über die
   Test-Klasse zu ziehen).

## Branch-/PR-Workflow (etabliert)

- Je AP ein Branch `claude/apN-<thema>`, von aktuellem `main` abgezweigt, mit
  vollständigen Tests grün, dann PR gegen `main`. Nach dem Merge das Repo auf
  `main` aktualisieren und den nächsten AP-Branch frisch abzweigen.
- Kein CI im Repo konfiguriert; Qualität wird über PHPUnit + (bisher) den
  Scratchpad-Smoke-Test gesichert.
