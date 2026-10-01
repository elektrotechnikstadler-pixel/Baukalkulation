# Erkenntnisse

Alle gelernten Punkte des KI-Teams an einer Stelle. Jede Rolle liest vor Arbeitsbeginn die
Kategorien, die ihre Aufgabe berühren. Rohbefunde und Kennzahlen stehen im
[Lernprotokoll](lernprotokoll.md), die Pflegeregeln in `.github/instructions/ki-dateien.instructions.md`.

Eintrag: `E-Nr` · **Regel** (eine Zeile, positiv, prüfbar) · Warum · Quelle · Umgesetzt in
(`nur hier` oder Datei, in die die Regel nach dem 2. Auftreten übernommen wurde).

## SQL / Datenbank

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-001 | In `ON CONFLICT … DO UPDATE SET` Spalten der Zieltabelle qualifizieren | PostgreSQL meldet sonst „ambiguous column“ | Umbau Phase 3 | php-backend.instructions.md |
| E-002 | `VACUUM INTO` über eine eigene Verbindung ausführen | Sonst „statements in progress“ | Umbau Phase 2 | nur hier |
| E-003 | Nach langsamem externem Aufruf nur bedingt schreiben (`UPDATE … WHERE id = ? AND data = ?`, `rowCount()` prüfen) | Paralleler manueller Wert wurde sonst überschrieben | AP-20260929-kupferpreis | nur hier |

## Sicherheit

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-010 | Geheime Einstellungen über `Auth::SECRET_SETTINGS`/`SecretBox` speichern | Klartext in DB und Backups | Sicherheits-Umbau | php-backend.instructions.md |
| E-011 | Secrets nicht nur als Docker-Env anbieten, wenn Cron-Jobs sie brauchen | Cron sieht die Container-Umgebung nicht | Sicherheits-Umbau | nur hier |
| E-012 | Einstellungen, die als Dateipfad dienen, auf feste Dateinamen im Datenverzeichnis abbilden – auch Werte aus Sicherungen | `firma_logo_url` erlaubte Lesen beliebiger Dateien | AP-20260929-sicherheit | nur hier |
| E-013 | Ohne Anmeldung erreichbare Endpunkte (`check`, `get_logo` …) in jedem AP auf ungefilterte Ausgabe prüfen | `check` lieferte IBAN/SMTP an Anonyme | AP-20260929-sicherheit | nur hier |
| E-014 | Ausnahmetexte nie in gespeicherte oder ausgelieferte Felder schreiben – feste Meldung, Details per `error_log()` | Globaler Error-Handler macht Warnungen zu Exceptions mit Interna | AP-20260929-kupferpreis | nur hier |
| E-015 | Zustandsändernde Aktionen und Flags (z. B. `force`) nur per POST; 405 erst nach Rollen-/Flag-Prüfung | GET-Links umgehen die Origin-Prüfung | AP-20260929-kupferpreis | nur hier |
| E-016 | Drosselung umgehende Flags (`force`) nie standardmäßig aus dem Frontend senden; Abnahme prüft den UI-Pfad, nicht nur die API | Update-Knopf umging Drosselung (GitHub-Rate-Limit) | AP-20261001-update | nur hier |
| E-017 | Standard-Image-Namen nie in einem fremden Registry-Namensraum (`ghcr.io/local/…` gehört einer realen Organisation); ohne Repo lokaler Name je Compose-Projekt | Lieferketten-Risiko (Blocker) | AP-20261001-update | nur hier |
| E-018 | Privilegierte Prozesse schreiben nie in Verzeichnisse, die ein weniger privilegierter Prozess beschreiben oder austauschen kann; Eigentum per root-Marker nachweisen, `mktemp`, keine Symlinks folgen | Symlink-Angriff auf root-Sidecar (2 Majors) | AP-20261001-update | AGENTS.md |

## Backup / Migration

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-020 | Nach jeder Migration Backup-Fixture neu erzeugen und einchecken | Import älterer Stände muss getestet bleiben | Umbau Phase 2 | migrationen.instructions.md |

## Tests

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-030 | SQLite-Dateien unter Windows erst nach `gc_collect_cycles()` löschen/umbenennen | Phinx hält Referenzzyklen, Datei bleibt gesperrt | Umbau Phase 3 | tests.instructions.md |
| E-031 | `TestServer::resetData()` verschluckt Aufräumfehler; nach leerer Antwort des Built-in-Servers Folgeanfrage senden | Folgetest sah alte Daten (`setup` → 403) | AP-20260929-sicherheit | nur hier (Fix als eigenes AP) |
| E-032 | Gesamtläufe (SQLite ~2 min, PostgreSQL ~5 min) asynchron mit Logdatei starten; Subagenten führen keine Gesamtläufe aus | Subagenten lieferten bei langen Läufen kein Ergebnis (2×) | AP sicherheit, kupferpreis | ki-leitstand.agent.md |
| E-033 | Parser-Randfälle nur in der betroffenen Zeile manipulieren; fremdes Zahlenformat in oberster Westmetall-Zeile → ältere Zeile ist akzeptiert (Veraltet nach 5 Tagen) | Sonst besteht auch ein falscher Fallback | AP-20260929-kupferpreis | nur hier |
| E-034 | Externe Abrufe von Anfang an injizierbar planen | Gutfall (Admin-`force`) sonst nicht testbar | AP-20260929-kupferpreis | nur hier |
| E-035 | Zeichensatz von Importdateien zeilenweise entscheiden (UTF-8 gültig → UTF-8, sonst Indizbytes CP850/Windows-1252, Datei-Vorgabe nur bei Gleichstand); Tests mit ASCII-Präfix und Mischdateien | Datanorm von FEGA & Schmitt ist CP850; Stichprobe allein klassifiziert falsch | AP-20260929-projektansicht | nur hier |

## Frontend

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-040 | In `vite.config.js` `publicDir: false` belassen | Sonst kopiert Vite `public/` nach `public/dist/` | Umbau Phase 1 | nur hier |

## Struktur (Vorlage copier-astral)

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-050 | Aufbau, Namen und Abläufe nach `copier-astral-main/template` planen und bauen | Einheitliche, bewährte Projektstruktur | Vorgabe Nutzer 2026-09-29 | struktur.instructions.md |

## Werkzeuge / Umgebung

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-060 | `php` in neuen Terminals über den absoluten WinGet-Pfad aufrufen | Nicht im PATH | Umbau Phase 0 | AGENTS.md |
| E-061 | npm unter Windows als `npm.cmd` aufrufen | ExecutionPolicy blockiert `npm.ps1` | Umbau Phase 1 | AGENTS.md |
| E-062 | Composer-Constraints mit `^` direkt in `composer.json` eintragen | `composer.bat` verschluckt `^` | Umbau Phase 1 | nur hier |
| E-063 | Kein `@`-Operator | Globaler Error-Handler wirft trotzdem | Umbau Phase 2 | AGENTS.md |
| E-064 | PostgreSQL-Tests nur starten, wenn der eigene Wegwerf-Container läuft – bei belegtem Port abbrechen, nie gegen einen unbekannten Server testen | Tests leeren das Schema `public`; Port 55432 war von einem Rest-Container belegt | AP-20260929-sicherheit | nur hier |
| E-065 | Befehle im Container als `www-data` ausführen (`docker compose exec -u www-data app …`) – in Doku und UI | Sonst root-eigene Dateien in `data/`, Webserver kann nicht mehr schreiben | AP-20261001-update | nur hier |
| E-066 | Ist ein Custom Agent per Name nicht verfügbar, generischen Subagenten mit Verweis auf die `.agent.md` beauftragen | Agent-Registry lud die Rollen zeitweise nicht | AP-20261001-update | nur hier |

## Planung / Zusammenarbeit

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-070 | Zusätzlich angelegte Testdateien im AP (Abschnitt 3/4) aufführen | Reviewer fand undokumentierten Test | AP-20260929-sicherheit | nur hier |
| E-071 | Tester schreibt nur unter `tests/`; bei Grenzverletzung Entwurf durch Entwickler prüfen lassen, nie ungeprüft übernehmen | Abgebrochener Tester-Lauf legte Produktivcode an | AP-20260929-kupferpreis | tester.agent.md (Hook-Kandidat Stufe 3) |
| E-072 | Behauptungen von Subagenten („gelöscht“, „ins AP geschrieben“) per `git status`/Dateiinhalt prüfen; Teilaufträge klein schneiden | Tester meldete Löschung ohne Ausführung; Reviewer schrieb Runde 1 nicht ins AP | AP-20260929-projektansicht | nur hier |
| E-074 | Vom Entwickler zusätzlich eingeführte öffentliche Methoden/Festlegungen im selben Schritt testen lassen | `gecachterStand()` und GET-Verhalten waren ungetestet | AP-20261001-update | nur hier |
| E-075 | Abläufe über einen Container-Neustart dürfen keine Session voraussetzen; Rückfall im UI genauso abnehmen wie Erfolg; globale Sperren für alle Einstiegspunkte (`api.php`, `din1090_api.php`) planen | Polling nach Tausch hängend, Wartung umgehbar | AP-20261001-update | nur hier |
| E-073 | Versionierung ab 3.0.1: nach jedem abgeschlossenen AP Patch-Version erhöhen und Tag `vX.Y.Z` auf `main` setzen (keine Branches) | Vorgabe Nutzer 2026-10-01 | Nutzer | ki-leitstand.agent.md |
| E-076 | Commits immer mit expliziten Pfaden (`git commit -- <pfade>`), auch `--amend` | Amend nahm fremde gestagte Dateien mit (vor Push korrigiert) | Release 3.0.6 | AGENTS.md, ki-leitstand.agent.md (auf Wunsch) |
| E-077 | Ab 3.0.6 kein `deploy/`-Spiegel: Release = Push `main` + Tag, Instanzen updaten aus dem GitHub-Release | Vorgabe Nutzer 2026-10-01 | Nutzer | AGENTS.md, ki-leitstand.agent.md, struktur.instructions.md |

## Abgelöst

<!-- Überholte Einträge mit Verweis auf den Nachfolger, z. B. „E-002 → E-0xx (Datum)“ -->
