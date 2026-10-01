# Baukalkulation – Regeln für KI-Agenten

PHP-8.2-Backend (`src/`, Namespace `App\`), Module in `modules/<name>/`, Webroot `public/`,
Datenbank SQLite **und** PostgreSQL. Details: [docs/entwicklung.md](docs/entwicklung.md).
Rollen und Ablauf: [docs/ki-architektur.md](docs/ki-architektur.md).
Aufbau und Struktur nach Vorlage `../copier-astral-main/template/` – Abbildung in `.github/instructions/struktur.instructions.md`.

## Befehle

- Tests: `php vendor/bin/phpunit` (lange Läufe asynchron) · nur Unit: `--testsuite unit`
- Analyse: `php vendor/bin/phpstan analyse --memory-limit=2G` – keine neuen Baseline-Einträge
- Format: `php vendor/bin/php-cs-fixer check --diff` (prüft vorerst nur `tests/`)
- Windows: `php` fehlt in neuen Terminals oft im PATH → absoluten WinGet-Pfad nutzen; npm als `npm.cmd`

## Unverhandelbar

- Jede Änderung läuft auf SQLite und PostgreSQL (SQL-Regeln in docs/entwicklung.md).
- Alte Sicherungen (`tests/fixtures/backups/`) bleiben importierbar.
- Schema nur per neuer Phinx-Migration; `legacy_baseline` und `LegacySchema.php` nie ändern.
- Keine Secrets, Kundendaten oder Inhalte aus `data/` in Code, Tests oder `.md`-Dateien.
- Kein `@`-Unterdrücken – der globale Error-Handler wirft trotzdem.
- `deploy/` ist generiert (`make deploy`) – nie direkt bearbeiten.
- Privilegierte Dienste (Updater mit Docker-Socket) schreiben nie in Verzeichnisse, die die App austauschen kann (E-018).
- Commits nach Conventional Commits, nur lokal; kein Push, kein History-Rewrite ohne Rückfrage.

## Zusammenarbeit

Arbeitspakete: `.github/ki/arbeitspakete/` · Lernprotokoll: `.github/ki/lernprotokoll.md`
· **Alle gelernten Punkte:** `.github/ki/erkenntnisse.md` – vor Arbeitsbeginn die betroffenen Kategorien lesen.
