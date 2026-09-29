# Lernprotokoll

Rohbefunde und Kennzahlen. Jeder gelernte Punkt wird als Eintrag in [erkenntnisse.md](erkenntnisse.md)
festgehalten. Tritt eine **Ursache zum zweiten Mal** auf, wird die Erkenntnis zusätzlich als Regel in die
passende `.md`-Datei übernommen (siehe `.github/instructions/ki-dateien.instructions.md`).

## Kennzahlen

| Zeitraum | Arbeitspakete | Ø Review-Runden | Erster Testlauf grün | Eskalationen | Zeilen Ebene 0–1 |
|---|---|---|---|---|---|
| – | 0 | – | – | 0 | – |

## Einträge

Kategorien: SQL · Sicherheit · Backup · Migration · Tests · Frontend · Struktur · Planung · Werkzeuge

| Datum | AP | Kategorie | Befund | Ursache | Anzahl | Erkenntnis (E-Nr) |
|---|---|---|---|---|---|---|
| 2026-09-29 | sicherheit | Sicherheit | Logo-Pfad aus Einstellung ungeprüft gelesen | Einstellungswert als Dateipfad | 1 | E-012 |
| 2026-09-29 | sicherheit | Sicherheit | `check` gab Einstellungen an Anonyme | fehlender Filter für nicht angemeldete Aufrufer | 1 | E-013 |
| 2026-09-29 | sicherheit | Tests | Folgetest sah alte Daten | `resetData()` verschluckt Fehler | 1 | E-031 |
| 2026-09-29 | sicherheit | Werkzeuge | Tester-Subagent ohne Rückgabe bei Gesamtlauf | langer synchroner Lauf | 1 | E-032 |
| 2026-09-29 | sicherheit | Werkzeuge | PG-Testskript lief trotz belegtem Port weiter (rechtzeitig abgebrochen) | fehlende Abbruchprüfung | 1 | E-064 |
| 2026-09-29 | sicherheit | Planung | Testdatei nicht im AP dokumentiert | – | 1 | E-070 |
