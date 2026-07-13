# AP5 — Exporte, Statistik & Post-Workflow

**Voraussetzung:** AP1 (sinnvollerweise auch AP2, für Forderungs-Exporte und
Beitragsstatistik). Parallel zu AP3/AP4 möglich.
**Konzept-Referenzen:** KONZEPT.md F7, F8, F11, F1 (Anrede/Adresse), §7 Q10.
Bibliothek: `openspout/openspout`.

## Ziel

CSV/XLSX-Exporte auf allen Listen, der Serienbrief-Workflow für Post-Mitglieder und
die Statistikseite für Vorstand/JHV.

## Scope

### 1. Export-Infrastruktur (F8)

- Wiederverwendbarer `ExportDienst`: nimmt Spaltendefinition + Datenzeilen, liefert
  **CSV** (UTF-8 **mit BOM**, Semikolon, CRLF — öffnet sauber in deutschem Excel) oder
  **XLSX** (openspout, Streaming — kein Memory-Aufbau bei 500+ Zeilen).
- Export-Buttons auf: Mitgliederliste, Offene Posten, Einzugslauf-Positionen,
  Versandaktions-Protokoll — jeweils exportiert die **aktuell gefilterte** Ansicht
  (Filterlogik aus AP1/AP2 wiederverwenden, nicht duplizieren).
- Dateinamen: `{thema}-{JJJJ-MM-TT}.csv|xlsx`. Jeder Export ⇒ Audit-Log
  (wer, was, Filter, Zeilenzahl). IBANs in Exporten nur maskiert (CLAUDE.md Regel 3).

### 2. Vordefinierte Exporte

- **Mitgliederliste** (alle Stammdatenfelder inkl. Land, Beitrag, Zahlweise, Status).
- **Offene Posten** (Jahr, Mitglied, Betrag, Status, Zahlweise).
- **Post-Empfängerliste / Serienbrief (F7)**: für Mitglieder mit „kein E-Mail-Kontakt"
  (bzw. die Post-Liste einer Versandaktion oder eines Einzugslaufs aus AP3/AP4):
  Spalten `anrede`, `vorname`, `nachname`, `adresszeile`, `strasse`, `plz`, `ort`,
  `land`, `laenderzeile` (leer bei DE), `briefanrede` (fertig, aus dem Anrede-Service —
  Familien korrekt), plus kontextabhängig `betrag`, `faelligkeitsdatum`,
  `mandatsreferenz` (Pre-Notification-Briefe). Kurzanleitung Serienbrief
  (Word/LibreOffice) als Hilfetext auf der Seite.
- **Jahresstatistik-Export** für die JHV (siehe unten, als CSV/XLSX).

### 3. Statistikseite (F11)

- Kennzahlen-Kopf (Stichtag heute): aktive Mitglieder, davon Lastschrift/Selbstzahler,
  Beitragssumme laufendes Jahr (Soll), offene Posten (Anzahl + Summe).
- Vier Auswertungen, je Diagramm + Tabelle darunter:
  1. **Mitgliederentwicklung**: Bestand aktiver Mitglieder zum 31.12. je Jahr, aus
     Ein-/Austrittsdaten berechnet (funktioniert rückwirkend für Altdaten-Import);
     Liniendiagramm.
  2. **Ein-/Austritte pro Jahr**: gruppierte Balken; Klick/Link zur gefilterten
     Mitgliederliste des Jahrgangs.
  3. **Beitragsentwicklung**: Soll- und Ist-Summe (bezahlte Forderungen) je Jahr +
     Durchschnittsbeitrag; Balken + Linie.
  4. **Verteilung der Beitragshöhen** (aktive Mitglieder): Histogramm über
     12/30/60/120 € + Gruppe „Sonstige", mit Anzahl und Summe je Stufe.
- Aggregation serverseitig (SQL); Rendering mit lokal eingebundenem Chart.js
  (Datei ins Repo, kein CDN). Farben mit ausreichendem Kontrast, Werte auch ohne
  Diagramm in der Tabelle ablesbar.
- **Druckansicht** (print-CSS): Kennzahlen + vier Auswertungen auf 1–2 Seiten für die JHV.

## Out of Scope

Brief-PDF-Generierung (Stufe 2), CAMT-Abgleich (Stufe 2), frei konfigurierbare Reports.

## Akzeptanzkriterien

1. Gefilterte Mitgliederliste (z. B. Status aktiv + Land NL) exportiert exakt die
   angezeigten Zeilen; Umlaute/Sonderzeichen korrekt in Excel (BOM-Test), XLSX öffnet
   in Excel/LibreOffice.
2. Serienbrief-Export enthält fertige `briefanrede` („Sehr geehrte Familie Müller")
   und `laenderzeile` („Niederlande") korrekt gefüllt (Tests am Anrede-Service-Output).
3. Kein Export enthält eine Klartext-IBAN (Test über alle Exportdefinitionen).
4. Statistik: berechnete Bestandszahlen stimmen für konstruierte Testdaten (Eintritte/
   Austritte über mehrere Jahre, inkl. Grenzfall Ein- und Austritt im selben Jahr).
5. Jeder Export erzeugt einen Audit-Log-Eintrag.
6. Statistikseite lädt bei 500 Mitgliedern/10 Jahren Historie < 1 s (einfache Indexe).
