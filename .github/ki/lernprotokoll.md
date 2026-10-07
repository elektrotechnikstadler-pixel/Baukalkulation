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
| 2026-09-29 | kupferpreis | Planung | Tester legte Produktivcode an, Lauf brach ohne Bericht ab | Rollengrenze nur per Anweisung | 1 | E-071 |
| 2026-09-29 | kupferpreis | Werkzeuge | Tester/Entwickler ohne Rückgabe | lange Terminal-Läufe im Subagenten | 2 | E-032 |
| 2026-09-29 | kupferpreis | SQL | Fehlschlag konnte manuellen Wert überschreiben (Major) | Read-Modify-Write ohne Bedingung | 1 | E-003 |
| 2026-09-29 | kupferpreis | Sicherheit | Exception-Text in `fetchError`; `force` per GET | – | 1 | E-014, E-015 |
| 2026-09-29 | kupferpreis | Tests | Plausibilitätstest ersetzte alle Zeilen | – | 1 | E-033 |
| 2026-09-29 | kupferpreis | Werkzeuge | PostgreSQL-Lauf nicht möglich (Docker-Dienst beendet) | Umgebung | 1 | E-064 |
| 2026-10-01 | projektansicht | Werkzeuge | Tester-Lauf ohne Rückgabe (nur Generator angelegt) | zu großer Teilauftrag | 3 | E-032, E-072 |
| 2026-10-01 | projektansicht | Planung | Tester meldete Löschung, Reviewer schrieb nicht ins AP | Subagent-Angaben ungeprüft | 1 | E-072 |
| 2026-10-01 | projektansicht | Tests | Erkennung aus Stichprobe (Major) | fehlende Randfall-Tests | 1 | E-035 |
| 2026-10-01 | projektansicht | Frontend | Filtersumme/Zuklappzustand nach Inline-Änderung veraltet | – | 1 | – |
| 2026-10-01 | update S1 | Sicherheit | Update-Knopf sendete immer `force` (Major) | UI-Pfad nicht abgenommen | 1 | E-016 |
| 2026-10-01 | update S1 | Planung | Docker-Anleitung aktualisierte die App nicht (Major) | `build:` statt `image:` übersehen | 1 | – |
| 2026-10-01 | update S1 | Werkzeuge | CLI als root erzeugt root-eigene Cache-Datei | `exec` ohne `-u www-data` | 1 | E-065 |
| 2026-10-01 | update S1 | Tests | Zusatzmethode ungetestet | – | 1 | E-074 |
| 2026-10-01 | update S1 | Werkzeuge | Custom Agent „Tester“ nicht gefunden | Registry | 1 | E-066 |
| 2026-10-01 | update S2 | Sicherheit | Fremder Registry-Namensraum als Standard-Image (Blocker) | Platzhalter `local` | 1 | E-017 |
| 2026-10-01 | update S2 | Sicherheit | Symlink-Angriffe auf root-Sidecar (2× Major) | gemeinsames Austauschverzeichnis | 2 | E-018 |
| 2026-10-01 | update S2 | Planung | Polling nach Container-Tausch ohne Session, din1090_api ohne Wartung | Neustart/Einstiegspunkte nicht geplant | 1 | E-075 |
| 2026-10-01 | update S2 | Werkzeuge | Entwickler-Lauf ohne Bericht, Testdateien im Projektwurzelverzeichnis | großer Auftrag | 4 | E-032, E-072 |
| 2026-10-01 | release 3.0.8 | Struktur | `install.sh` nach `git clone` nicht ausführbar | Commit unter Windows mit Modus 100644 | 1 | E-078 |
| 2026-10-01 | release 3.0.8 | Struktur | 2. Instanz: Updater-Container-Name kollidiert | Installer setzte `UPDATER_NAME` nicht | 1 | E-079 |
| 2026-10-04 | sollzeit-wochentage | Planung | Architekt meldete „Hoch“-Befund (getDay-Versatz), widerlegt durch Leitstand | Subagent-Befund ungeprüft | 2 | E-072 |
| 2026-10-04 | sollzeit-wochentage | Planung | Monats-Mail: Feiertag doppelt als Ist (Major) | Soll und Ist aus unterschiedlicher Logik | 1 | E-080 |
| 2026-10-04 | sollzeit-wochentage | Tests | API-Test zu strikt auf JSON-Typ von 0 (1 roter Lauf) | – | 1 | – |
| 2026-10-04 | anleitung-zeiterfassung | Planung | Anleitung beschrieb ZE003 als Speicher-Blocker (Major) | nur Katalog statt aktivem Pfad geprüft | 1 | E-081 |
| 2026-10-04 | anleitung-zeiterfassung | Werkzeuge | PG-Testskript brach unter PowerShell 5.1 ab (`Stop` + stderr von docker) | NativeCommandError | 1 | E-082 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Stunden-Erinnerung seit DB-Umstellung wirkungslos, Anleitung beschrieb sie als aktiv (Hoch) | Cron-Einstiegspunkte bei Datenquellen-Umstellung nicht geprüft | 1 | E-083 |
| 2026-10-07 | zeiterfassung-abgleich | Frontend | Monats-Mail ohne betriebliche Feiertage; Wochenplanung ohne Wochentags-Soll (3.0.11) | duplizierte Feiertags-/Soll-Logik | 2 | E-041 → Instructions |
| 2026-10-07 | zeiterfassung-abgleich | Planung | 5 Explore-Befunde „Hoch/Mittel“ widerlegt (betriebliche Feiertage, Legacy-Typen, Cron-Typen) | Subagent-Befund ungeprüft | 3 | E-072 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Ungeplanter Benutzerfilter in der Erinnerung (Major) | Vertragsabweichung nicht gemeldet | 1 | E-084 |
| 2026-10-07 | zeiterfassung-abgleich | Planung | Übernommene Doku-Cronzeit passte nicht zur Vortagslogik (Major) | Doku-Aussage nicht gegen aktiven Pfad geprüft | 2 | E-081 |
| 2026-10-07 | zeiterfassung-abgleich | Tests | PHP↔JS-Parität ungetestet | fehlende JS-Testumgebung | 1 | E-036 |
