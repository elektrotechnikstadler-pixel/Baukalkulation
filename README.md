# Baukalkulation ES (Planning Dashboard)

[![CI](https://github.com/OWNER/REPO/actions/workflows/ci.yml/badge.svg)](https://github.com/OWNER/REPO/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4)
![Lizenz](https://img.shields.io/badge/Lizenz-propriet%C3%A4r-lightgrey)

Web-App für Handwerksbetriebe: Baustellen/Kalkulation, Zeiterfassung, Wochenplanung,
Kunden, Rechnungen/Angebote (ZUGFeRD), Termine sowie Module für Aufmaß, Lager,
VDE 0100 und DIN EN 1090.

## Installation

Betrieb per Docker – siehe [docs/installation.md](docs/installation.md).

```bash
./install.sh            # Linux / NAS
.\install.ps1           # Windows
```

## Projektstruktur

```
public/        Webroot (einziger per HTTP erreichbarer Ordner)
src/           PHP-Backend (Namespace App\)
modules/       Modul-Backends (aufmass, din1090, lager, vde0100)
src/frontend/  Frontend-Quellen für den Vite-Build (→ public/dist/)
tests/         PHPUnit: Unit- und API-Tests, Backup-Fixtures
scripts/       Build-Hilfen (Versionsprüfung, Manifest-Sync)
docs/          Dokumentation (MkDocs)
data/          Laufzeitdaten – nie einchecken
VERSION        App-Version (einzige Quelle)
```

## Entwicklung

Voraussetzungen: PHP ≥ 8.2 (pdo_sqlite, mbstring, fileinfo, gd, zip, curl), Composer, Node.js LTS.

```bash
make install     # composer install + npm ci
make test        # PHPUnit (Unit + API)
make verify      # PHPStan, PHP-CS-Fixer, Versionsprüfung
make build       # script.min.js + Vite-Build
make help        # alle Befehle
```

Ohne `make` (Windows): `composer test`, `vendor/bin/phpstan analyse`, `npm run minify`, `npm run build`.

Details: [docs/entwicklung.md](docs/entwicklung.md).

## Änderungen

Siehe [CHANGELOG.md](CHANGELOG.md).
