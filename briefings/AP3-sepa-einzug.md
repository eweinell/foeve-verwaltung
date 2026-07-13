# AP3 — SEPA-Export & Einzugslauf

**Voraussetzung:** AP0–AP2 abgeschlossen.
**Konzept-Referenzen:** KONZEPT.md F5 (kompletter Ablauf), §3.3, §5, §7 Q4/Q5.
Bibliothek: `abcaeffchen/sephpa` (pain.008).

## Ziel

Geführter Einzugslauf: Forderungen auswählen → Pre-Notification → pain.008-XML fürs
Online-Banking → Abschluss → Rücklastschriften nacharbeiten.

## Scope

### 1. Datenmodell

- `einzugslauf` gemäß KONZEPT.md §5; `forderung.einzugslauf_id` wird hier genutzt.
- Statusmaschine Einzugslauf: `entwurf → angekuendigt → exportiert → abgeschlossen`
  (kein Zurück; Abbruch nur aus `entwurf`/`angekuendigt` durch Löschen des Laufs, das
  die Forderungen wieder freigibt).

### 2. Einzugslauf-Workflow (F5)

1. **Anlegen** (`entwurf`): Bezeichnung, Fälligkeitsdatum. Aufgenommen werden alle
   Forderungen `offen` mit Zahlweise `lastschrift` und aktivem Mandat. Validierung:
   Fälligkeit mindestens 2 Bankarbeitstage (TARGET2: Wochenenden + Feiertage) in der
   Zukunft; Warnung, wenn Pre-Notification-Frist (Einstellung
   `prenotification_tage`, Default 14) nicht einzuhalten ist.
2. **Vorschau**: Positionen (Mitglied, Betrag, Mandatsreferenz, FRST/RCUR), Summen je
   Sequenztyp, Gesamtsumme; einzelne Positionen abwählbar.
3. **Ankündigen** (`angekuendigt`): Pre-Notification je Position in die Mail-Queue
   (Template `prenotification`: Betrag, Fälligkeitsdatum, Mandatsreferenz,
   Gläubiger-ID, maskierte IBAN). Mitglieder mit „kein E-Mail-Kontakt" landen auf der
   **Brief-Liste des Laufs** (Anzeige + Übergabe an AP5-Export); der Lauf merkt sich
   die Aufteilung X E-Mail / Y Post.
4. **XML erzeugen** (`exportiert`): pain.008 via sephpa. Sequenztyp je Position aus
   `mandat.sequenz_genutzt` (false ⇒ FRST, sonst RCUR); getrennte PaymentInfo-Blöcke.
   Verwendungszweck: `Mitgliedsbeitrag {jahr} Foerderverein Gymnasium Herzogenrath,
   Mitglied {nummer}` (ASCII/SEPA-Zeichensatz, Umlaute transliterieren). Download als
   Datei `einzug-{jahr}-{laufId}.xml`; XML zusätzlich unter `var/sepa/` ablegen.
   Nebenwirkungen (transaktional): Forderungen ⇒ `im_einzug`,
   `mandat.sequenz_genutzt = true`, `zuletzt_genutzt_am` setzen. Erneuter Download
   liefert dieselbe Datei (keine Doppel-Erzeugung).
5. **Abschließen** (`abgeschlossen`, nach Bankeinreichung, manuell): alle Positionen
   ⇒ `bezahlt` (bezahlt_am = Fälligkeitsdatum, Zahlungsart `lastschrift`).
6. **Rücklastschrift erfassen** (auch nach Abschluss): Position wählen ⇒ Forderung
   wieder `offen` (Status `ruecklastschrift` dokumentiert den Vorfall in der
   Forderungshistorie), optional Gebühren-Forderung (typ `gebuehr`, Betrag aus
   Einstellung `ruecklastschrift_gebuehr`), Ein-Klick-Angebot: Zahlweise
   `selbstzahler` + Mandat `inaktiv`.

### 3. Validierung & Sicherheit

- XML gegen das pain.008-XSD validieren (Schema lokal beilegen); Lauf ohne gültige
  Gläubiger-ID/Vereins-IBAN (Einstellungen) nicht startbar.
- IBAN-Klartext nur im XML; Audit-Log für jeden Statuswechsel und jede
  Rücklastschrift.
- Beträge in Cents rechnen; Summen im XML müssen exakt der Vorschau entsprechen.

## Out of Scope

Automatischer CAMT-Abgleich (Stufe 2), Brief-PDF (AP5/Stufe 2), Template-Verwaltung (AP4).

## Akzeptanzkriterien

1. Kompletter Durchlauf mit gemischten FRST/RCUR-Positionen erzeugt schemavalides
   pain.008-XML (XSD-Test) mit korrekten Summen, Referenzen, Gläubiger-ID.
2. Nach Export sind alle enthaltenen Mandate `sequenz_genutzt = true`; der nächste
   Lauf stuft dieselben Mitglieder als RCUR ein (Test).
3. Fälligkeits-/Fristvalidierung: Lauf mit Fälligkeit morgen wird abgelehnt; Lauf mit
   Fälligkeit in 5 Tagen bei 14-Tage-Frist erzeugt Warnung, ist aber (bewusst) möglich.
4. Pre-Notifications liegen für alle E-Mail-Mitglieder in der Queue; Post-Mitglieder
   stehen auf der Brief-Liste; Aufteilung wird angezeigt.
5. Rücklastschrift: Forderung wieder offen, Gebühr optional angelegt, Umstellung auf
   Selbstzahler deaktiviert das Mandat; alles im Audit-Log.
6. Ein Lauf kann nie zweimal exportiert werden und Forderungen können nie in zwei
   Läufen gleichzeitig stecken (DB-Constraints + Tests).
7. Sonderzeichen-Test: Mitglied „Müller-Lüdenscheidt" erscheint SEPA-konform im XML.
