# AP2 — SEPA-Mandate & Sollstellung

**Voraussetzung:** AP0, AP1 abgeschlossen.
**Konzept-Referenzen:** KONZEPT.md §3.2 (Zahlweise), §3.3 (Mandat), §3.4
(Beitragsjahr/Sollstellung), §3.5 (Beitragsänderung), F3, F4, §5, §7 Q1/Q4/Q15.

## Ziel

Mandatsverwaltung und jährliche Beitrags-Sollstellung mit offenen Posten — die
fachliche Grundlage für den Lastschrift-Export (AP3).

## Scope

### 1. Datenmodell (Migrationen)

- `mandat`, `mandat_version`, `forderung` gemäß KONZEPT.md §5.
- Vereins-Stammdaten als Einstellungen: `glaeubiger_id`, `verein_name`,
  `verein_iban` (verschlüsselt), `verein_bic`.

### 2. Mandate (F3)

- Anlage automatisch bei Aktivierung eines Antrags (Hook aus AP1 füllen): IBAN,
  Kontoinhaber (Default: Mitgliedsname), Erteilungsdatum = DOI-Bestätigungsdatum,
  Status `aktiv`, `sequenz_genutzt = false`.
- Mandatsreferenz: `FGH-{Mitgliedsnr 4-stellig}-{lfdNr 2-stellig}` (z. B.
  `FGH-2001-01`); bei importierten Altmandaten bleibt die vorhandene Referenz (AP6).
- Manuelle Anlage (Papierantrag) und Bearbeitung über Mitglied-Reiter „Mandate";
  alle Änderungen über den Versionierungs-Service.
- **Bankwechsel = neues Mandat** mit neuer Referenz (`lfdNr + 1`, beginnt als FRST);
  altes Mandat ⇒ `inaktiv`. Kein Amendment-Handling. Pro Mitglied höchstens ein
  aktives Mandat (DB-seitig absichern).
- Widerruf erfassbar (`widerrufen`); Mitglied wird dabei auf Zahlweise `selbstzahler`
  gestellt (mit Bestätigungsdialog).
- Anzeige: IBAN immer maskiert; Klartext nur für die XML-Erzeugung (AP3) intern.
- Hinweis-Badge, wenn ein aktives Mandat 36 Monate nicht genutzt wurde (verfallen).

### 3. Zahlweise (§3.2)

- Umschaltung `lastschrift` ⇄ `selbstzahler` am Mitglied. Nach `lastschrift` nur mit
  aktivem Mandat. Umstellung auf `selbstzahler` bietet an, das Mandat zu deaktivieren.

### 4. Sollstellung (F4)

- Seite „Sollstellung": Jahr wählen ⇒ **Vorschau** (welche Mitglieder, welcher Betrag,
  Summe; aktive Mitglieder ohne Forderung für das Jahr) ⇒ Ausführen erzeugt je
  Mitglied eine `forderung` (typ `beitrag`, status `offen`, Betrag = aktueller
  Jahresbeitrag). **Idempotent:** UNIQUE (mitglied_id, jahr, typ=beitrag) — erneuter
  Lauf ergänzt nur fehlende (z. B. zwischenzeitlich Aktivierte).
- Einzelsollstellung automatisch bei Aktivierung (Hook aus AP1): volle Forderung fürs
  laufende Jahr (Q1).
- `gekuendigt`/`ausgeschieden`: keine Forderungen für Jahre nach dem Wirksamkeitsjahr.
- Beitragsänderung (§3.5) fertigstellen: bei offener Forderung des laufenden Jahres
  Rückfrage „Forderung anpassen?"; Forderungen in `im_einzug`/`bezahlt` sind tabu.
- Manuelle Vorgänge (nur mit Audit-Log): Forderung stornieren (Storno-Status, kein
  DELETE), Selbstzahler-Forderung auf `bezahlt` setzen (Datum, Zahlungsart bar/
  ueberweisung), Einzelforderung manuell anlegen (z. B. Gebühr, typ `gebuehr`).
- Übersicht „Offene Posten": Filter Jahr/Status/Zahlweise, Summenzeile;
  Mitglied-Reiter „Beiträge" zeigt alle Forderungen des Mitglieds.

## Out of Scope

XML-Erzeugung, Einzugsläufe, Pre-Notification, Rücklastschriften (AP3); Exporte (AP5).

## Akzeptanzkriterien

1. Aktivierung eines bestätigten Antrags erzeugt Mandat (`FGH-….-01`, FRST-fähig) und
   Forderung fürs laufende Jahr.
2. Sollstellungslauf für ein Jahr: korrekt für aktive, keine Duplikate bei Wiederholung,
   keine Forderung für `gekuendigt` im Folgejahr (Tests inkl. Grenzfälle Jahreswechsel).
3. Bankwechsel legt neues Mandat an, deaktiviert das alte; nie zwei aktive Mandate.
4. Beitragsänderung mit offener/eingezogener Forderung verhält sich gemäß §3.5 (Tests).
5. Storno statt Löschen: keine Route/kein Code löscht Forderungen.
6. Alle Mandatsänderungen erscheinen in der Änderungshistorie; IBAN überall maskiert.
