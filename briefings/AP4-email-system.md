# AP4 — E-Mail-System (Vollausbau)

**Voraussetzung:** AP0 (Mail-Queue-Basis), AP1. Parallel zu AP2/AP3 möglich; das
Template `prenotification` wird von AP3 genutzt.
**Konzept-Referenzen:** KONZEPT.md F6, F1 (Anrede-Logik), §7 Q6.

## Ziel

Ausbau der Mail-Queue-Basis aus AP0 zum vollständigen Versandsystem: Templates mit
Platzhaltern, Versandaktionen (Massenversand mit PDF-Anhang), Protokoll, Wiederholung.

## Scope

### 1. Template-Verwaltung

- CRUD für `email_vorlage` (nur `admin` ändert; `vorstand` liest): Schlüssel, Betreff,
  Text- und HTML-Body. Systemvorlagen (nicht löschbar, Schlüssel fest):
  `begruessung`, `kuendigungsbestaetigung`, `prenotification`, `doi_bestaetigung`,
  `login_code`. Sinnvolle deutsche Default-Texte (Sie-Form) als Migration/Seed;
  die Fixtexte aus AP0/AP1 auf diese Vorlagen umstellen.
- Platzhalter-Engine (einfaches `{{name}}`-Replacement, kein Twig für Nutzereingaben):
  `{{briefanrede}}`, `{{adresszeile}}`, `{{vorname}}`, `{{nachname}}`,
  `{{mitgliedsnummer}}`, `{{beitrag}}`, `{{iban_maskiert}}`, `{{mandatsreferenz}}`,
  `{{glaeubiger_id}}`, `{{faelligkeitsdatum}}`, `{{jahr}}`. Unbekannte Platzhalter
  ⇒ Fehler beim Speichern/Testversand, nicht erst beim Massenversand.
  `{{briefanrede}}`/`{{adresszeile}}` nutzen den Anrede-Service aus AP1 (Familien!).

### 2. Versandaktionen (Massenversand, „Vereinspost")

- Assistent: (1) Empfänger über Mitglieder-Filter wählen (Status, Zahlweise, …;
  Filterlogik aus AP1 wiederverwenden) — Anzeige „X per E-Mail, Y per Post (Flag
  ‚kein E-Mail-Kontakt' oder ohne Adresse)"; (2) Vorlage wählen oder frei verfassen,
  optional **PDF-Anhang** hochladen (nur PDF, Größenlimit ~5 MB, Ablage
  `var/anhaenge/`, ein Anhang für alle Empfänger); (3) Vorschau mit echten Daten
  eines wählbaren Mitglieds + **Testmail an die eigene Adresse**; (4) Freigabe ⇒
  Mails einreihen (`versandaktion` verknüpft), Post-Liste an AP5-Export übergeben.
- Pre-Notification aus AP3 läuft als Versandaktion (Typ `prenotification`).

### 3. Queue-Ausbau (`bin/mailqueue.php`)

- Drosselung aus Einstellung `mail_rate_pro_minute` (Default 10); Priorität „sofort"
  (Login-Code, DOI) vor Massenversand; FIFO innerhalb gleicher Priorität.
- Fehlerbehandlung: SMTP-Fehler ⇒ Status `fehler` + Fehltext; automatisch 1 Wiederholung
  nach 15 Min. bei temporären Fehlern (4xx), danach manuell. Lock gegen parallele
  Cron-Läufe (z. B. `GET_LOCK`).
- Header: List-Unsubscribe entfällt (Vereinskommunikation), aber sauberer From/Reply-To
  aus Einstellungen, Message-ID, Text+HTML-Multipart.

### 4. Protokoll & Monitoring

- Versandaktions-Übersicht: Fortschritt (gesendet/wartend/fehler), Detailliste je Mail,
  fehlgeschlagene einzeln oder gesammelt neu einreihen.
- Mitglied-Reiter „E-Mail-Historie" (AP1-Platzhalter füllen): alle Mails des Mitglieds.
- Dashboard-Kachel: Queue-Stand + letzter Cron-Lauf (Warnung, wenn > 10 Min. her —
  erkennt hängende Crons).
- Bounces: kein automatisches Handling (Q6); Hinweistext in der Doku, dass das
  Absenderpostfach gesichtet und ggf. „kein E-Mail-Kontakt" gesetzt wird.

## Out of Scope

Newsletter-Funktionen, Tracking/Öffnungsraten, Bounce-Automatik (Stufe 2), Brief-PDFs.

## Akzeptanzkriterien

1. Versandaktion an gefilterte ~500 Test-Mitglieder: Queue füllt sich, Cron versendet
   gedrosselt (Rate messbar), Fortschritt live sichtbar, PDF-Anhang kommt an
   (Mailpit-Test).
2. Vorlage mit `{{briefanrede}}` rendert für Herr/Frau/Familie korrekt; unbekannter
   Platzhalter wird beim Speichern abgewiesen (Tests).
3. Testmail-Funktion sendet nur an den angemeldeten Benutzer, ohne die Aktion zu starten.
4. Login-Code/DOI überholen wartenden Massenversand (Prioritätstest).
5. Temporärer SMTP-Fehler ⇒ ein automatischer Retry; permanenter ⇒ `fehler` mit
   Fehltext, manuell neu einreihbar.
6. Zwei parallel gestartete Cron-Läufe versenden keine Mail doppelt (Lock-Test).
7. AP0-Fixtexte (Begrüßung, Kündigung, DOI, Login-Code) laufen jetzt über Vorlagen.
