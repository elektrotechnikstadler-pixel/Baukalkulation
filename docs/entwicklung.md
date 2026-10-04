# Entwicklung

## Projektstruktur

| Ordner/Datei | Inhalt |
|---|---|
| `public/` | Webroot. Nur was hier liegt, ist per HTTP erreichbar. |
| `public/api.php` | API-Einstiegspunkt (`?action=…`), leitet an `src/Handlers/*` weiter |
| `src/` | PHP-Backend, PSR-4-Namespace `App\` |
| `modules/<name>/` | Modul-Backend (`module.json`, `backend/Module.php`, Datenbankschema) |
| `public/modules/<name>/` | Browser-Dateien des Moduls (JS/CSS) |
| `src/frontend/`, `modules/*/frontend/` | Quellen für den Vite-Build → `public/dist/` |
| `cron_*.php`, `migrate.php` | CLI-Skripte (Cron, Container-Start) |
| `tests/` | PHPUnit-Tests und Backup-Fixtures |
| `data/` | Laufzeitdaten (SQLite, Uploads, Backups) – nie einchecken |
| `VERSION` | App-Version, einzige Quelle |

## Befehle

| Zweck | make | ohne make |
|---|---|---|
| Abhängigkeiten | `make install` | `composer install && npm ci` |
| Tests | `make test` | `vendor/bin/phpunit` |
| Tests gegen PostgreSQL | `make test-pgsql` | siehe unten |
| Statische Analyse | `make type-check` | `vendor/bin/phpstan analyse --memory-limit=2G` |
| Formatierung prüfen / korrigieren | `make lint` / `make fix` | `vendor/bin/php-cs-fixer check` / `fix` |
| Frontend bauen | `make build` | `npm run minify && npm run build` |
| Versionen prüfen | `make check-versions` | `node scripts/check-versions.mjs --strict` |

Git-Hooks: `prek install` (oder `pre-commit install`) aktiviert die Prüfungen aus
`.pre-commit-config.yaml` vor jedem Commit.

## Datenbank-Migrationen

Das Schema verwaltet [Phinx](https://book.cakephp.org/phinx/0/en/index.html); ausgeführte
Versionen stehen in der Tabelle `schema_migrations`.

- `migrations/20260925000000_legacy_baseline.php` ist der eingefrorene Stand bis v2.10.99
  (`src/Database/LegacySchema.php`). Sie legt neue DBs an und hebt jede ältere DB ohne
  Versionstabelle auf diesen Stand. **Nicht mehr ändern.**
- Jede Schemaänderung ist eine neue Migration: `vendor/bin/phinx create AddFooToBar`
  (Konfiguration in `phinx.php`). Nur vorwärts; `down()` darf eine Exception werfen.
- Die App migriert beim Verbinden automatisch (Dateisperre `data/migrate.lock`). Ist die DB
  neuer als der Code, antwortet die API mit 503 und `bin/console db:migrate` bricht ab.
- Sicherungen älterer Versionen werden beim Import als Kopie migriert – deshalb nach jeder
  Migration `make fixture` ausführen und die neue Fixture einchecken.

```bash
php bin/console db:status      # ausgeführt / offen
php bin/console db:migrate     # Schema anheben
php bin/console db:transfer to-pgsql|to-sqlite [--sqlite=datei] [--force]
php bin/console backup:create  # Sicherung in data/backups/
php bin/console backup:import <zip|ordner|json> [--mode=replace] [--password=…]
```

## SQLite und PostgreSQL

`BK_DB_DRIVER` wählt die Datenbank (`sqlite` Standard, `pgsql`); `App\Database\ConnectionConfig`
liest die Zugangsdaten. Beide Treiber liefern eine `App\Database\Connection`; Unterschiede
bündelt `App\Database\Dialect` (`SqliteDialect`, `PgsqlDialect`).

Regeln für neues SQL:

- Spaltennamen bleiben camelCase. `PgsqlDialect::translate()` setzt sie für PostgreSQL
  automatisch in Anführungszeichen und übersetzt die SQLite-DDL der Migrationen
  (`INTEGER PRIMARY KEY AUTOINCREMENT`, `REAL`, `COLLATE NOCASE` → `CITEXT`, `LIKE` → `ILIKE`,
  `INSERT OR IGNORE`, `PRAGMA table_info`).
- Nicht verwenden: `COLLATE NOCASE` in Abfragen (→ `LOWER(x)`), `INSERT OR REPLACE`
  (→ `ON CONFLICT(…) DO UPDATE`), `JSON_*`-Funktionen (in PHP), `GROUP_CONCAT`
  (→ `Dialect::groupConcat()`), `BEGIN IMMEDIATE` (→ `Dialect::beginExclusive()`),
  SQLite-Datumsfunktionen (in PHP formatieren).
- In `ON CONFLICT … DO UPDATE SET` Spalten der Zieltabelle qualifizieren (`tabelle.spalte + 1`).
- Sicherungen enthalten immer eine SQLite-Datei; bei PostgreSQL erzeugt `BackupWriter` sie
  über `App\Database\TableCopier` aus einem konsistenten Lese-Snapshot.

Tests gegen PostgreSQL: `make test-pgsql` (startet einen Wegwerf-Container), unter Windows
`powershell -File scripts/test-pgsql.ps1 -Php <pfad\php.exe>` (Docker Desktop muss laufen), oder manuell mit
`BK_DB_DRIVER=pgsql BK_DB_HOST=… BK_DB_PORT=… BK_DB_NAME=… BK_DB_USER=… BK_DB_PASSWORD=…`.
Ist `pdo_pgsql` nicht in der `php.ini` aktiv, zusätzlich `BK_TEST_PHP_ARGS="-d extension=pdo_pgsql"`
setzen und PHPUnit mit `php -d extension=pdo_pgsql vendor/bin/phpunit` starten. Achtung: Die
Tests leeren das Schema `public` der angegebenen Datenbank.

## Sicherungen (Format 2)

`App\Backup\BackupWriter` schreibt `baukalkulation.json`, `database.sqlite` (per `VACUUM INTO`)
und `manifest.json` (Format, App-/Schema-Version, SHA-256). `App\Backup\BackupArchive` liest alle
bisherigen Formate (2, 1 = ohne Manifest, 0 = einzelne JSON-Datei), `App\Backup\Importer` spielt
sie in **einer** Transaktion ein: Baustellen/Kataloge aus dem JSON, alle übrigen Tabellen
generisch aus der SQLite-Datei (gemeinsame Spalten). Jeder Fehler rollt vollständig zurück.

## Tests

- `tests/Unit/` – reine PHP-Funktionen.
- `tests/Api/` – Charakterisierungstests: starten `public/api.php` im PHP-Built-in-Server
  mit leerem Datenverzeichnis (`BK_DATA_DIR`) und prüfen das Verhalten über HTTP.
  Sie halten das heutige Verhalten fest, damit Umbauten (z. B. PostgreSQL) nichts
  unbemerkt verändern.
- `tests/fixtures/legacy-v0/` – alter JSON-Datenstand (vor SQLite), Import über `migrate.php`.
- `tests/fixtures/backups/v*.zip` – echte Backups früherer Versionen. Jede Datei muss sich in
  eine frische Installation einspielen lassen. Bei jedem Release mit Schemaänderung
  `make fixture` ausführen und die neue Datei einchecken.

## Statische Analyse

PHPStan läuft auf Level 5. Bestehende Befunde stehen in `phpstan-baseline.neon`; neuer Code
muss ohne neue Einträge auskommen. Behobene Befunde aus der Baseline entfernen
(`vendor/bin/phpstan analyse --generate-baseline`).

PHP-CS-Fixer (PER-CS 2.0) prüft vorerst nur `tests/`. Die übrigen Dateien werden in einem
eigenen, reinen Formatierungs-Commit umgestellt und danach in `.php-cs-fixer.dist.php` ergänzt.

## Versionen und Cache-Busting

1. Version nur in `VERSION` ändern, dann `npm run version:sync`
   (überträgt sie nach `public/manifest.json` und `package.json`).
2. Bei Änderungen an `public/script.js`: `npm run minify` und `?v=` in `index.html` + `sw.js`
   sowie `CACHE_VERSION` in `sw.js` erhöhen.
3. `make check-versions` meldet Abweichungen; die CI bricht dann ab.

## Commits und Release

Commit-Nachrichten nach [Conventional Commits](https://www.conventionalcommits.org/):
`feat:`, `fix:`, `sec:`, `perf:`, `refactor:`, `docs:`, `test:`, `ci:`, `chore:`.

Release:

```bash
# VERSION anpassen, npm run version:sync, CHANGELOG.md ergänzen, committen
git tag vX.Y.Z
git push origin main
git push origin vX.Y.Z
```

Es gibt keinen `deploy/`-Upload-Ordner mehr: Instanzen holen Updates ausschließlich aus den
GitHub-Releases (Updater bzw. Update-Fenster, oder Git-Checkout des Tags).

Die Release-Pipeline prüft, dass Tag und `VERSION` übereinstimmen, baut das Docker-Image
(`ghcr.io/<owner>/<repo>:<version>`) mit SBOM und erstellt ein GitHub-Release mit
Release-Notes aus den Commits (git-cliff). `CHANGELOG.md` bleibt die ausführliche,
von Hand gepflegte Änderungshistorie.
