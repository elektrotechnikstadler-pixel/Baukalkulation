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

## Sicherheit

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-010 | Geheime Einstellungen über `Auth::SECRET_SETTINGS`/`SecretBox` speichern | Klartext in DB und Backups | Sicherheits-Umbau | php-backend.instructions.md |
| E-011 | Secrets nicht nur als Docker-Env anbieten, wenn Cron-Jobs sie brauchen | Cron sieht die Container-Umgebung nicht | Sicherheits-Umbau | nur hier |

## Backup / Migration

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-020 | Nach jeder Migration Backup-Fixture neu erzeugen und einchecken | Import älterer Stände muss getestet bleiben | Umbau Phase 2 | migrationen.instructions.md |

## Tests

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|
| E-030 | SQLite-Dateien unter Windows erst nach `gc_collect_cycles()` löschen/umbenennen | Phinx hält Referenzzyklen, Datei bleibt gesperrt | Umbau Phase 3 | tests.instructions.md |

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

## Planung / Zusammenarbeit

| Nr | Regel | Warum | Quelle | Umgesetzt in |
|---|---|---|---|---|

## Abgelöst

<!-- Überholte Einträge mit Verweis auf den Nachfolger, z. B. „E-002 → E-0xx (Datum)“ -->
