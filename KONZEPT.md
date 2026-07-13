# Konzept: Vereinsverwaltung Förderverein Gymnasium Herzogenrath

Stand: 2026-07-14 (alle Grundsatzfragen entschieden) · Zielplattform: PHP / MariaDB
(netcup) · Arbeitstitel: `foeve-verwaltung`

## 1. Ziel und Rahmen

Webbasierte Verwaltungssoftware für einen Förderverein mit ca. 500 Mitgliedern. Bedient wird
sie von wenigen Vorstandsmitgliedern (2–5 Personen), keine Selbstregistrierung, kein
Mitglieder-Login. Fachlich bewusst einfach:

- Genau **eine Mitgliedschaftsart**, Mitgliedschaft immer **jahresweise**.
- Mitglieder wählen ihren **Jahresbeitrag** innerhalb vorgegebener Grenzen
  (aktuell: 12 / 30 / 60 / 120 € oder Wunschbetrag, mindestens 12 €).
- Zahlweise: **SEPA-Lastschrift** (Standard) oder **Barzahlung/Überweisung**
  (Fallback, insbesondere nach fehlgeschlagenem Einzug).

### Kernfunktionen (Übersicht)

| Nr. | Modul | Kurzbeschreibung |
|-----|-------|------------------|
| F1  | Mitgliederverwaltung | Stammdaten, Status-Lebenszyklus, Mitgliedsnummer |
| F2  | Antragseingang | Integration des Online-Antragsformulars, manuelle Aktivierung durch Vorstand |
| F3  | SEPA-Mandate | Mandatsverwaltung mit Referenz, Status, Historie |
| F4  | Sollstellung | Jährliche Beitragsforderungen erzeugen, offene Posten führen |
| F5  | Lastschrift-Export | pain.008-XML (Erst-/Folgelastschrift), Rücklastschrift-Behandlung |
| F6  | E-Mail-Versand | Massenversand mit Drosselung, Templates, PDF-Anhang, Versandprotokoll |
| F7  | Briefe / Nicht-E-Mail-Mitglieder | Kennzeichnung, Export für Serienbrief |
| F8  | Exporte | CSV/Excel mit Filterung |
| F9  | Login & Sicherheit | Sicheres Login für Vorstand, Rollen, Audit-Log |
| F10 | Historisierung | Versionierung aller Personendaten-Änderungen, nachvollziehbar und rücknehmbar |
| F11 | Statistik | Mitgliederentwicklung, Ein-/Austritte, Beitragsentwicklung und -verteilung |

## 2. Nutzer und Rollen

- **Admin** (1–2 Personen): alles, inkl. Benutzerverwaltung und Einstellungen.
- **Vorstand** (weitere Nutzer): Mitglieder, Anträge, Sollstellung, E-Mails, Exporte.

Zwei Rollen genügen; feinere Rechte wären für 5 Nutzer Overhead. Benutzer werden nur vom
Admin angelegt (keine Selbstregistrierung).

## 3. Domänenmodell

### 3.1 Mitglied — Status-Lebenszyklus

```
unbestätigt ──(E-Mail-Link)──► beantragt ──(Vorstand aktiviert)──► aktiv ──(Kündigung)──► gekündigt
    │                              │                                 ▲                        │
    └─(30 Tage)► verworfen         └─(Ablehnung/Spam)► abgelehnt     └─(Widerruf Kündigung)   ▼
                                                                ausgeschieden ──(Frist)──► anonymisiert
```

- **unbestätigt**: Formular abgesendet, Double-Opt-In-Mail mit Bestätigungslink verschickt
  (Q8); ohne Bestätigung wird der Antrag nach 30 Tagen automatisch verworfen.
- **beantragt**: E-Mail-Adresse bestätigt, wartet auf Prüfung durch den Vorstand;
  noch keine Mitgliedsnummer.
- **aktiv**: vom Vorstand aktiviert; Mitgliedsnummer wird bei Aktivierung vergeben;
  Begrüßungsmail/-schreiben wird ausgelöst.
- **gekündigt**: Kündigung erfasst, Mitgliedschaft läuft bis Jahresende;
  Kündigungsbestätigung wird ausgelöst. Keine Sollstellung mehr für Folgejahre.
- **ausgeschieden**: nach Wirksamkeit der Kündigung (oder Tod/Ausschluss).
- **anonymisiert**: personenbezogene Daten nach Aufbewahrungsfrist entfernt (siehe Q13).

### 3.2 Zahlweise

Pro Mitglied: `lastschrift` oder `selbstzahler` (Bar/Überweisung). Umstellung jederzeit
möglich; bei Rücklastschrift kann der Vorstand mit einem Klick auf `selbstzahler` umstellen
(Mandat wird dabei auf „inaktiv" gesetzt, bleibt aber historisiert).

### 3.3 SEPA-Mandat

- Eigene Entität (nicht nur Felder am Mitglied), da ein Mitglied im Lauf der Zeit mehrere
  Mandate haben kann (Bankwechsel ⇒ neues Mandat bzw. Mandatsänderung).
- Felder: Mandatsreferenz, IBAN, (BIC optional, seit der SEPA-Verordnung 2016 auch
  grenzüberschreitend entbehrlich — „IBAN only"), Kontoinhaber, Datum der Erteilung,
  Status (`erteilt`, `aktiv`, `inaktiv`, `widerrufen`), Sequenz-Merker:
  „bereits verwendet?" ⇒ steuert FRST vs. RCUR.
- **IBANs aus dem gesamten SEPA-Raum** werden akzeptiert (Grenzgänger mit BE-/NL-Konten);
  Validierung per Prüfziffer + länderspezifischer Länge. Der Einzug per CORE-Lastschrift
  funktioniert SEPA-weit unverändert.
- Mandatsreferenz-Schema (Vorschlag): `FGH-{Mitgliedsnr}-{lfdNr}`, z. B. `FGH-0421-01`.
- Ein Mandat verfällt nach 36 Monaten ohne Nutzung (SEPA-Regel) — bei jährlichem Einzug
  praktisch nicht relevant, wird aber geprüft und angezeigt.

### 3.4 Beitragsjahr und Sollstellung

- Beitragsjahr = **Kalenderjahr** (entschieden, Q1).
- **Sollstellung** = pro aktivem Mitglied und Jahr eine Forderung (offener Posten) über den
  individuellen Jahresbeitrag. Wird als Batch-Lauf erzeugt („Sollstellung 2027 erzeugen").
- Offener Posten hat Status: `offen` → `im Einzug` → `bezahlt` | `rücklastschrift` → wieder
  `offen` (mit Zahlweise-Umstellung). Selbstzahler werden manuell auf `bezahlt` gesetzt.
- Eintritt unterjährig: voller Jahresbeitrag für das Eintrittsjahr, Sollstellung sofort bei
  Aktivierung (Q1).

### 3.5 Beitragsänderungen

Mitglieder können ihren Jahresbeitrag (innerhalb der Grenzen, mind. 12 €) ändern; die
Erfassung erfolgt durch den Vorstand am Mitgliedsdatensatz.

- Die Änderung ist durch die Historisierung (F10) vollständig nachvollziehbar
  (alter Wert, neuer Wert, wer, wann).
- **Wirksamkeit** (entschieden, Q15): Der neue Beitrag gilt ab der **nächsten
  Sollstellung** (i. d. R. Folgejahr). Ist die Forderung des laufenden Jahres noch
  `offen` (nicht im Einzug/bezahlt), fragt die Software beim Speichern, ob sie ebenfalls
  angepasst werden soll.
- Bereits erzeugte Forderungen speichern ihren Betrag selbst — eine Beitragsänderung
  verändert also niemals rückwirkend abgeschlossene Jahre.

## 4. Module im Detail

### F1 Mitgliederverwaltung

- Stammdaten: Anrede (Herr/Frau/Familie), Vorname (bei Familien optional), Nachname,
  Straße/Nr., PLZ, Ort, **Land** (Default DE), E-Mail, Telefon (optional),
  Eintrittsdatum, Austrittsdatum, Status, Zahlweise, Jahresbeitrag, Freitext-Notizen.
- **Grenzgänger** (insbes. Wohnsitz Belgien/Niederlande, Herzogenrath liegt im
  Dreiländereck): Land als Auswahlfeld (DE/BE/NL prominent, übrige SEPA-Länder wählbar);
  PLZ ist ein Textfeld mit **länderspezifischer Validierung** (DE: 5 Ziffern,
  BE: 4 Ziffern, NL: „1234 AB"); Adress-Exporte und die Adresszeile führen bei
  Land ≠ DE die Länderzeile mit.
- **Anrede-Logik**: Mitglieder sind teils natürliche Personen, teils Familien. Die
  Briefanrede für Mails und Serienbriefe wird automatisch aus der Anrede erzeugt —
  Herr ⇒ „Sehr geehrter Herr {Nachname}", Frau ⇒ „Sehr geehrte Frau {Nachname}",
  Familie ⇒ „Sehr geehrte Familie {Nachname}" — und kann pro Mitglied durch eine
  **manuelle Briefanrede** überschrieben werden (Titel wie „Dr.", Paare mit
  unterschiedlichen Nachnamen: „Sehr geehrte Frau Meier, sehr geehrter Herr Schulz").
  Analog eine **Adresszeile** für den Postversand (automatisch „Familie {Nachname}" bzw.
  „{Vorname} {Nachname}", manuell überschreibbar). Beide stehen als Platzhalter in allen
  Templates und Exporten zur Verfügung.
- Flag **„kein E-Mail-Kontakt"** (keine/ungültige E-Mail-Adresse ⇒ Postversand): manuell
  setzbar, wird automatisch vorgeschlagen wenn E-Mail leer; in allen Versand- und
  Exportfunktionen als Filter verfügbar.
- **Mitgliedsnummer**: fortlaufend, vierstellig, von der Software bei Aktivierung
  vergeben, niemals wiederverwendet. Importierte Altmitglieder behalten ihre bisherigen
  Nummern; der Nummernkreis für Neuaufnahmen beginnt bei **2000** (Q3).
- Beitragsänderung direkt am Mitglied erfassbar (Regeln siehe 3.5).
- Listenansicht mit Suche und Filtern (Status, Zahlweise, E-Mail ja/nein, Beitragshöhe,
  offene Posten); Detailansicht mit Reitern: Stammdaten / Mandate / Beiträge /
  E-Mail-Historie / Änderungshistorie (F10).

### F2 Antragseingang (Integration der bestehenden Anmelde-App)

- Es existiert bereits eine **produktive, selbst entwickelte Anmelde-App**
  (`c:\users\erhard\dev\foeve-signupphp`, PHP ohne Framework, PHPMailer,
  TrustCaptcha) mit eigener Tabelle `mitglieder` und vollständigem
  **Double-Opt-In**: Formular ⇒ Datensatz `unbestätigt` + Bestätigungsmail;
  Bestätigung per Link **plus Button-Klick (POST)** — bewusst, damit Mail-Scanner
  (Outlook Safe Links) nicht ungewollt bestätigen. Dazu: Warteseite mit Resend,
  Admin-Benachrichtigung (redigierte Daten) mit geschütztem CSV-Download,
  Registrierungs-Statistik, Cleanup-Cron (unbestätigt > 30 Tage, bestätigt > 90 Tage).
- **Integrationsstrategie (Q7/Q8):** Der Antragsflow (Formular, DOI-Mechanik inkl.
  POST-Bestätigung, Texte, Optik, Captcha) wird aus der bestehenden App übernommen
  und schreibt künftig in die Verwaltungs-DB (`mitglied` mit Status `unbestaetigt` ⇒
  `beantragt` + `antrag_rohdaten`). Der bisherige Übergabe-Weg (Admin-Mail +
  CSV-Download + 90-Tage-Löschung) **entfällt** — bestätigte Anträge erscheinen
  direkt im Dashboard der Verwaltung. Der Cleanup für Unbestätigte bleibt
  (⇒ `verworfen`).
- Anpassungen bei der Übernahme: neues Feld **Land** (Default DE; BE/NL für
  Grenzgänger) mit PLZ-Validierung je Land; IBAN-Prüfziffernvalidierung für **alle
  SEPA-Länder** (bisher nur für DE-IBANs); IBAN verschlüsselt statt Klartext;
  Mailversand über die zentrale Queue statt PHPMailer-Direktversand; Konfiguration
  aus `.env` statt `config.php` mit eingecheckten Zugangsdaten.
- TrustCaptcha bleibt als Bot-Schutz (bewährt), ergänzt um Rate-Limit pro IP.
  Hinweis: lädt ein CDN-Skript — akzeptierte Ausnahme nur für die öffentliche
  Formularseite, nicht für den internen Bereich.
- Vorstand sieht bestätigte Anträge im Dashboard, prüft, **aktiviert** (⇒ Mitgliedsnummer,
  Mandat anlegen, Begrüßungsmail) oder lehnt ab (Spam/Duplikat).

### F3 SEPA-Mandate

- Anlegen automatisch aus dem Antrag; manuell erfassbar (Papierantrag).
- Bankwechsel eines Mitglieds ⇒ **neues Mandat mit neuer Referenz** (beginnt mit FRST);
  kein Amendment-Handling (entschieden, Q4).
- Bestehende Mandate aus dem Altbestand werden **mit ihren bisherigen Referenzen
  importiert**, damit Folgelastschriften RCUR bleiben (Q4).
- Vereins-Stammdaten in den Einstellungen: Gläubiger-Identifikationsnummer (CI, vorhanden),
  Vereins-IBAN/BIC, Name des Zahlungsempfängers.

### F4 Sollstellung

- Jahreslauf: Button „Sollstellung {Jahr} erzeugen" ⇒ erzeugt für alle zum Stichtag aktiven
  Mitglieder je einen offenen Posten. Idempotent (pro Mitglied+Jahr max. eine Forderung),
  Vorschau vor Ausführung.
- Einzelsollstellung bei unterjähriger Aktivierung automatisch.
- Übersicht offene Posten mit Summen, Filter nach Status/Jahr/Zahlweise.

### F5 Lastschrift-Export (pain.008)

- Erzeugt aus offenen Posten mit Zahlweise `lastschrift` und aktivem Mandat eine
  **SEPA-XML-Datei (pain.008)** zum Upload ins Online-Banking.
- Sequenztyp pro Position automatisch: **FRST** (Mandat noch nie genutzt) oder **RCUR**;
  getrennte Sammler je Sequenztyp in einer Datei (mehrere PaymentInfo-Blöcke). Hinweis:
  seit dem EPC-Rulebook 2016 akzeptieren Banken auch RCUR für Erstlastschriften — die
  Software führt den Merker trotzdem korrekt.
- Ablauf eines **Einzugslaufs**:
  1. Lauf anlegen (Fälligkeitsdatum wählen; Vorlauf ≥ 1–2 Bankarbeitstage wird geprüft).
  2. Vorschau: enthaltene Mitglieder, Beträge, Summe, FRST/RCUR-Aufteilung.
  3. **Pre-Notification** per E-Mail an alle enthaltenen Mitglieder (Betrag, Mandatsreferenz,
     CI, Fälligkeitsdatum) — Pflicht vor Einzug; Frist siehe Q5. Mitglieder ohne E-Mail
     erscheinen in einer Export-Liste für postalische Ankündigung.
  4. XML erzeugen und herunterladen; Posten ⇒ `im Einzug`; Mandats-Merker „genutzt" setzen.
  5. Nach Bankeinreichung: Lauf abschließen ⇒ Posten `bezahlt`.
  6. **Rücklastschriften** manuell erfassen (Mitglied auswählen): Posten wieder `offen`,
     Rücklastschrift-Gebühr optional als Zusatzforderung, ein Klick zur Umstellung auf
     `selbstzahler` + Mandat inaktiv.
- Verwendungszweck: z. B. `Mitgliedsbeitrag {Jahr} Foerderverein Gymnasium Herzogenrath,
  Mitglied {Nr}`.
- Bibliothek: `abcaeffchen/sephpa` oder vergleichbar (erzeugt validiertes pain.008.001.02/.08).

### F6 E-Mail-Versand

- **Anlässe**: Begrüßung (automatisch bei Aktivierung), Kündigungsbestätigung (automatisch
  bei Kündigungserfassung), Lastschrift-Ankündigung/Pre-Notification (aus Einzugslauf),
  Vereinspost (frei verfasst, z. B. JHV-Einladung, mit **PDF-Anhang**, Empfänger per Filter).
- **Architektur: Warteschlange statt Direktversand.** Jede Mail landet in einer
  `email_queue`-Tabelle; ein Cron-Job (jede Minute) versendet gedrosselt, z. B.
  **10 Mails/Minute** (konfigurierbar) über SMTP des Hosters. 500 Mails ≈ 50 Minuten —
  unkritisch, überlastet nichts und bleibt unter typischen Hoster-Limits (oft 200–500/h).
- Templates mit Platzhaltern (`{{briefanrede}}`, `{{adresszeile}}`, `{{mitgliedsnummer}}`,
  `{{beitrag}}`, `{{iban_maskiert}}`, `{{faelligkeitsdatum}}` …). `{{briefanrede}}` nutzt
  die Anrede-Logik aus F1 (Herr/Frau/Familie, manuelle Überschreibung hat Vorrang) —
  Familien werden also korrekt als „Sehr geehrte Familie …" angeschrieben.
- Versandprotokoll pro Mail: geplant / gesendet / fehlgeschlagen (mit SMTP-Fehler); Bounces
  werden nicht automatisch verarbeitet (Postfach wird vom Vorstand gesichtet, siehe Q6);
  fehlgeschlagene Mails einzeln neu einstellbar.
- Jeder Massenversand hat eine Vorschau + Testmail an sich selbst vor Freigabe.

### F7 Nicht-E-Mail-Mitglieder / Briefversand

- Flag am Mitglied (F1); jede Versandaktion zeigt an: „X per E-Mail, Y per Post".
- Für die Y Post-Mitglieder: CSV-Export für Serienbrief in Word/LibreOffice — enthält
  Adressfelder inkl. Land (Länderzeile bei Auslandsadressen BE/NL), `adresszeile` und
  die fertige `briefanrede` (Anrede-Logik aus F1, inkl. Familien), damit im Serienbrief
  keine eigene Anrede-Weiche nötig ist. Kein eigener PDF-Briefgenerator in Stufe 1 (Q10).

### F8 Exporte

- Überall wo Listen sind: Export der **aktuell gefilterten** Ansicht als **CSV**
  (UTF-8 mit BOM, Semikolon — öffnet sauber in deutschem Excel) und **XLSX**
  (Bibliothek: `openspout/openspout`, leichtgewichtig).
- Vordefinierte Exporte: Mitgliederliste, offene Posten, Einzugslauf-Positionen,
  Post-Empfängerliste, Jahresstatistik (Ein-/Austritte, Beitragssumme) für die JHV.

### F9 Login & Sicherheit

- Login mit Benutzername/E-Mail + Passwort (Argon2id-Hash). **Zweiter Faktor optional
  pro Benutzer** (Q11): TOTP per App **oder** Bestätigungscode per E-Mail beim Login.
- **Kein Passwort-Reset per E-Mail** (Q11): Passwörter setzt ausschließlich ein Admin
  zurück (Einmal-Passwort mit Änderungszwang beim nächsten Login).
- Brute-Force-Schutz (Lockout/Throttling), Session-Härtung (HttpOnly, Secure, SameSite),
  CSRF-Token, ausschließlich HTTPS, Security-Header.
- IBANs verschlüsselt in der DB (AES, Schlüssel in `.env` außerhalb des Webroots);
  Anzeige standardmäßig maskiert (`DE12 …… 3456`).
- Audit-Log: Login-Ereignisse, Exporte, Versandaktionen, Statusänderungen von
  Einzugsläufen (Datenänderungen an Personendaten laufen über F10).
- Tägliches DB-Backup (mysqldump per Cron, verschlüsselt, Rotation).

### F10 Historisierung der Personendaten

Alle Änderungen an Mitglieds-Stammdaten (und Mandaten) werden **versioniert**, nicht nur
protokolliert — Ziel: nachvollziehen *und bei Bedarf zurücknehmen*.

- **Mechanik**: Vor jedem Speichern wird der komplette bisherige Datensatz als
  **Snapshot** (JSON) in `mitglied_version` geschrieben, zusammen mit Benutzer, Zeitpunkt
  und der Liste der geänderten Felder (Diff für die Anzeige). Gleiches Prinzip für
  `mandat_version`. Snapshots statt Feld-Deltas: einfacher korrekt zu implementieren,
  Wiederherstellung ist trivial, Speicherbedarf bei 500 Mitgliedern irrelevant.
- **Anzeige**: Reiter „Änderungshistorie" am Mitglied — chronologisch, pro Eintrag:
  wer, wann, welche Felder (alt → neu). Statuswechsel (Aktivierung, Kündigung) und
  Beitragsänderungen erscheinen dort ebenfalls, da sie normale Feldänderungen sind.
- **Rücknahme**: „Auf diesen Stand zurücksetzen" stellt den Snapshot wieder her — als
  *neue* Version (Vorwärts-Revert, die Historie bleibt lückenlos; nichts wird gelöscht).
  Einzelne Felder lassen sich aus der Detailansicht einer Version gezielt übernehmen.
- **Grenzen**: Forderungen und Einzugsläufe werden *nicht* revertiert (buchhalterische
  Daten sind unveränderlich; Korrekturen dort per Storno). Ein Revert ändert nur
  Stammdaten/Mandatsdaten und löst keine Mails oder Sollstellungen aus.
- IBAN-Werte erscheinen in Snapshots nur verschlüsselt/maskiert (gleiche Schutzstufe wie
  im Hauptdatensatz).
- Bei der DSGVO-Anonymisierung (Q13) werden die Versionen des Mitglieds mit anonymisiert.

### F11 Statistik

Einfache, fest eingebaute Auswertungen (keine konfigurierbare Report-Engine) auf einer
Statistik-Seite, jeweils als Diagramm + Tabelle, exportierbar (F8):

- **Mitgliederentwicklung über die Jahre**: Bestand aktiver Mitglieder zum 31.12. je Jahr
  (berechnet aus Ein-/Austrittsdaten — funktioniert damit auch rückwirkend für
  importierte Altdaten).
- **Ein- und Austritte pro Jahr**: Balken nebeneinander, wahlweise Detailliste.
- **Entwicklung der Mitgliedsbeiträge**: Beitrags-Soll und -Ist je Jahr (Summe der
  Forderungen bzw. der bezahlten Forderungen), zusätzlich Durchschnittsbeitrag.
- **Verteilung der aktuellen Beitragshöhen**: Histogramm über die Standardstufen
  (12/30/60/120 €) plus Sammelgruppe „Sonstige/Wunschbetrag", mit Anzahl und Summe.
- Stichtags-Kennzahlen im Kopf: aktive Mitglieder, davon Lastschrift/Selbstzahler,
  Beitragssumme laufendes Jahr, offene Posten.
- Technik: serverseitig berechnete Aggregate (SQL), Darstellung mit einer kleinen, lokal
  eingebundenen Chart-Bibliothek (z. B. Chart.js, ohne CDN). Für die JHV als
  Export/Druckansicht nutzbar.

## 5. Datenmodell (Kerntabellen)

```
benutzer        (id, name, email, passwort_hash, totp_secret, rolle, aktiv, letzter_login)
mitglied        (id, mitgliedsnummer NULLABLE UNIQUE, status, anrede ENUM(herr, frau,
                 familie), vorname NULLABLE, nachname, briefanrede_manuell NULLABLE,
                 adresszeile_manuell NULLABLE, strasse, plz VARCHAR, ort,
                 land CHAR(2) DEFAULT 'DE', email NULLABLE,
                 kein_email_kontakt BOOL, telefon, jahresbeitrag DECIMAL, zahlweise ENUM,
                 eintrittsdatum, austrittsdatum, notizen, created_at, updated_at)
mandat          (id, mitglied_id FK, mandatsreferenz UNIQUE, iban_verschluesselt, bic,
                 kontoinhaber, erteilt_am, status, zuletzt_genutzt_am, sequenz_genutzt BOOL)
forderung       (id, mitglied_id FK, jahr, betrag, typ ENUM(beitrag, gebuehr),
                 status ENUM(offen, im_einzug, bezahlt, ruecklastschrift, storniert),
                 einzugslauf_id FK NULLABLE, bezahlt_am, zahlungsart)
einzugslauf     (id, bezeichnung, faelligkeitsdatum, status ENUM(entwurf, angekuendigt,
                 exportiert, abgeschlossen), xml_erzeugt_am, summe, anzahl)
email_vorlage   (id, schluessel, betreff, body_html, body_text)
email_queue     (id, mitglied_id FK NULLABLE, empfaenger, betreff, body, anhang_pfad,
                 status ENUM(wartend, gesendet, fehler), fehltext, geplant_ab, gesendet_am,
                 versandaktion_id FK)
versandaktion   (id, typ, betreff, erstellt_von FK, anzahl_gesamt/gesendet/fehler)
mitglied_version(id, mitglied_id FK, version_nr, snapshot JSON, geaenderte_felder JSON,
                 geaendert_von FK, geaendert_am, ist_revert_von FK NULLABLE)
mandat_version  (id, mandat_id FK, version_nr, snapshot JSON, geaenderte_felder JSON,
                 geaendert_von FK, geaendert_am)
audit_log       (id, benutzer_id FK, zeitpunkt, aktion, entitaet, entitaet_id, details JSON)
einstellung     (schluessel, wert)   -- CI, Vereins-IBAN, Absender, Drosselrate, Beitragsgrenzen …
antrag_rohdaten (id, mitglied_id FK, eingegangen_am, ip_hash, payload JSON,
                 bestaetigungs_token, bestaetigt_am NULLABLE)  -- Mandats-/DOI-Nachweis
```

## 6. Technik & Architektur

- **PHP 8.3+**, **MariaDB 10.6+**, Hosting: **netcup** (Q12) — Cron-Jobs, SSH/Composer,
  SPF/DKIM und TLS sind dort verfügbar.
- **Stack (entschieden, Q14 — minimal)**: schlankes Framework **Slim 4**
  (Routing/Middleware) + **Twig** (Templates) + PDO/kleines Query-Layer; Composer-Pakete:
  `symfony/mailer` (SMTP), `abcaeffchen/sephpa` (pain.008), `openspout/openspout` (XLSX),
  `endroid/qr-code` (TOTP-QR), `robthree/twofactorauth`.
- Klassische serverseitig gerenderte Anwendung, wenig JavaScript (etwas Alpine.js/HTMX für
  Filter und Vorschauen). Kein SPA — geringere Komplexität, bessere Wartbarkeit im Ehrenamt.
- Konfiguration über `.env` außerhalb des Webroots; öffentliches Verzeichnis nur `public/`.
- Zeit-/Geldwerte: Beträge als DECIMAL(8,2), niemals Float.

## 7. Entscheidungen (ehemals offene Fragen)

Alle Fragen wurden vom Vorstand beantwortet (Juli 2026); die Entscheidungen sind in die
Abschnitte oben eingearbeitet.

- **Q1 Beitragsjahr:** Kalenderjahr. Voller Jahresbeitrag im Eintrittsjahr, Einzug zeitnah
  nach Aktivierung.
- **Q2 Kündigung:** Software erfasst Kündigungsdatum + Wirksamkeitsdatum (Default 31.12.,
  überschreibbar); Fristprüfung bleibt beim Vorstand.
- **Q3 Mitgliedsnummer & Altdaten:** Es gibt einen Altbestand, CSV-Import erforderlich
  (AP6). Vierstelliges Schema; Altmitglieder behalten ihre Nummern, Nummernkreis für
  Neuaufnahmen ab **2000**.
- **Q4 SEPA-Altbestand:** Gläubiger-ID vorhanden (steht auf der Registrierungsseite).
  Bestehende Mandate werden mit ihren Referenzen übernommen (Folgelastschriften bleiben
  RCUR). Bankwechsel ⇒ neues Mandat mit neuer Referenz, kein Amendment.
- **Q5 Pre-Notification:** konfigurierbare Frist, Default 14 Tage, Warnung bei
  Unterschreitung.
- **Q6 E-Mail-Versand:** Hoster-SMTP (netcup) mit Queue/Drosselung, SPF/DKIM korrekt
  einrichten; Bounces werden manuell gesichtet.
- **Q7 Antragsformular:** Die bestehende, selbst entwickelte Anmelde-App
  (`foeve-signupphp`, PHP ohne Framework) wird in die Codebasis integriert; die
  Website verlinkt darauf.
- **Q8 Online-Mandat:** Double-Opt-In ist in der bestehenden Anmelde-App **bereits
  umgesetzt** (Bestätigungslink + POST-Button gegen Mail-Scanner) und wird übernommen;
  Payload als Mandatsnachweis; verbleibendes Widerspruchsrisiko trägt der Verein bewusst.
- **Q9 Selbstzahler-Abgleich:** manuell (Stufe 1); CAMT-Import ggf. Stufe 2.
- **Q10 Briefe:** Stufe 1 CSV-Export für Serienbriefe; PDF-Generator ggf. Stufe 2.
- **Q11 Login-Sicherheit:** 2FA wird angeboten, ist aber **optional**: TOTP per App oder
  Bestätigungscode per E-Mail. **Kein Passwort-Reset per E-Mail** — Zurücksetzen nur
  durch einen Admin.
- **Q12 Hosting:** netcup; alle Anforderungen (PHP 8.3, MariaDB, Cron, SSH/Composer,
  SPF/DKIM, TLS) erfüllt.
- **Q13 DSGVO:** Anonymisierung 2 Jahre nach Austritt; Forderungs-/Einzugsdaten 10 Jahre;
  Vorschlagslauf mit Admin-Bestätigung.
- **Q14 Stack:** minimales Framework — Slim 4 + Twig + gezielte Composer-Pakete.
- **Q15 Beitragsänderung:** wirksam ab nächster Sollstellung; Nachfrage bei noch offener
  Forderung des laufenden Jahres; angekündigte/eingezogene Beträge werden nie geändert.

## 8. Ausbaustufen

- **Stufe 1 (MVP):** F1–F5, F8–F10 + Begrüßungs-/Kündigungsmail, Pre-Notification,
  einfache Vereinspost mit Anhang, CSV/XLSX-Export, Altdatenimport. Historisierung (F10)
  gehört in Stufe 1, da sie technisch von Anfang an im Speicherpfad verankert sein muss.
- **Stufe 1b:** F11 Statistik (baut nur lesend auf den Daten auf, jederzeit nachrüstbar,
  aber früh nützlich für die JHV).
- **Stufe 2 (optional):** CAMT-Kontoabgleich, Brief-PDF-Generator, Bounce-Automatik,
  Spendenquittungen (falls je gewünscht — bewusst nicht im Scope?).

## 9. Zuschnitt für Agent-Briefings

Die ausformulierten Briefings liegen in `briefings/` (AP0–AP6 + README mit
Reihenfolge/Definition of Done); Grundregeln für alle Agents in `CLAUDE.md`.

1. **AP0 Projektgerüst**: Slim-4-Skeleton, DB-Migrationen, `.env`, Login + 2FA, Rollen,
   Audit-Log, **Versionierungs-Mechanik (F10)** als wiederverwendbare Infrastruktur,
   **Mail-Queue-Basis** (F6 minimal — wird für DOI/Login-Codes ab AP1 gebraucht;
   Vollausbau in AP4), Layout (Basis für alle weiteren APs).
2. **AP1 Mitglieder & Anträge**: F1 + F2 inkl. Integration des bestehenden Formulars,
   Double-Opt-In, Aktivierungs-Workflow, Beitragsänderung (3.5) und
   Änderungshistorie-UI (F10).
3. **AP2 Mandate & Sollstellung**: F3 + F4.
4. **AP3 SEPA-Export & Einzugslauf**: F5 inkl. Pre-Notification-Anbindung.
5. **AP4 E-Mail-System**: F6 (Queue, Cron, Templates, Versandaktionen, Protokoll).
6. **AP5 Exporte, Statistik & Post-Workflow**: F7 + F8 + F11.
7. **AP6 Altdatenimport & Betrieb**: CSV-Import (Mitglieder mit bestehenden Nummern,
   Mandate mit bestehenden Referenzen und korrektem FRST/RCUR-Merker), Backup-Cron,
   Deployment-Doku (netcup), DSGVO-Läufe.

Reihenfolge: AP0 → AP1 → AP2 → AP3; AP4/AP5 parallelisierbar ab AP1; AP6 zum Schluss.
