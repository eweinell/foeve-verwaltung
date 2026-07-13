# Agent-Briefings — Übersicht und Reihenfolge

Jedes Briefing ist ein eigenständiges Arbeitspaket. Vor Beginn: `CLAUDE.md` und die im
Briefing referenzierten Abschnitte aus `KONZEPT.md` lesen.

| AP | Titel | Abhängig von | Parallelisierbar |
|----|-------|--------------|------------------|
| AP0 | Projektgerüst, Auth, Versionierung, Mail-Queue-Basis | — | — |
| AP1 | Mitglieder & Anträge | AP0 | — |
| AP2 | Mandate & Sollstellung | AP0, AP1 | — |
| AP3 | SEPA-Export & Einzugslauf | AP2 | — |
| AP4 | E-Mail-System (Vollausbau) | AP0 (Basis), AP1 | ab AP1, parallel zu AP2/AP3 |
| AP5 | Exporte, Statistik & Post-Workflow | AP1 (sinnvoll: AP2) | ab AP1, parallel zu AP3/AP4 |
| AP6 | Altdatenimport & Betrieb | AP1–AP3 | zum Schluss |

Definition of Done für jedes AP:
- Akzeptanzkriterien des Briefings erfüllt und manuell durchgespielt.
- PHPUnit-Tests für die im Briefing genannte Domänenlogik grün.
- Migrationen laufen auf leerer DB von 0 an durch.
- Keine Verletzung der Grundregeln aus `CLAUDE.md` (insb. Versionierung, IBAN, Queue).
