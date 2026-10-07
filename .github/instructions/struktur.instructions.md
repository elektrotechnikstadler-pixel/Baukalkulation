---
description: "Use when planning or changing project structure, folders, tooling, Makefile targets, CI workflows, docs, release, pre-commit, Docker or CLI commands. Structure follows the copier-astral template."
applyTo: "**/Makefile, **/composer.json, **/phpunit.xml.dist, **/.github/workflows/**, **/mkdocs.yml, **/docs/**, **/.pre-commit-config.yaml, **/Dockerfile*, **/cliff.toml, **/bin/**"
---
# Struktur nach Vorlage copier-astral

Referenz: `../copier-astral-main/template/` (Python-Vorlage im selben Workspace). Aufbau, Namen und
Abläufe werden übernommen, Werkzeuge durch PHP-Gegenstücke ersetzt. Keine Python-Werkzeuge einführen.

| Vorlage | Baukalkulation |
|---|---|
| `src/<paket>/` + `py.typed` | `src/` (PSR-4 `App\`), Module in `modules/<name>/`; neuer Code vollständig typisiert |
| `cli.py` (Typer, `--version`) | `bin/console` (symfony/console) – neue CLI-Befehle dort, keine neuen Root-Skripte |
| `tests/` + `conftest.py`, Marker `slow` | `tests/Unit`, `tests/Api`, Testsuites in `phpunit.xml.dist`, `#[Group('slow')]`; JS-Helfer: `tests/js/*.test.mjs` mit `node --test` (Abweichung, Parität E-041) |
| uv + `pyproject.toml` | Composer `composer.json` (+ npm nur für Frontend) |
| ruff (Lint + Format) | PHP-CS-Fixer (PER-CS 2.0) |
| ty (Typprüfung) | PHPStan (Baseline darf nur schrumpfen) |
| `.pre-commit-config.yaml` (prek) | gleich |
| Makefile `install verify fix lint type-check test test-cov docs docs-serve` | gleiche Target-Namen, `make help` listet alle |
| CI-Jobs `lint`, `type-check`, `test`-Matrix, Secret-/Dependency-Scan, Semgrep | `ci.yml`: statische Prüfung, Test-Matrix (PHP × SQLite/PostgreSQL), Sicherheit |
| `release.yml`: Tag `v*` → Build → GitHub-Release mit git-cliff | gleich; Docker-Image statt PyPI |
| `docs.yml` + MkDocs Material; `docs/contributing.md` | `mkdocs.yml`, `docs/`; Contributing ≙ `docs/entwicklung.md` |
| `CHANGELOG.md`, `cliff.toml`, Conventional Commits, SemVer | gleich; Version nur in `VERSION` |
| `renovate.json`, `.editorconfig`, `.gitignore`, `Dockerfile` + `.dockerignore` | gleich (`Dockerfile.app`) |
| README: Badges, Features, Installation, Quick Start | gleiche Gliederung |

## Regeln

- Vor jeder Struktur- oder Tooling-Entscheidung die Vorlage prüfen und Aufbau/Namen übernehmen.
- Optionale Bausteine der Vorlage (Doku, Docker, Renovate, Sicherheits-Scan, prek) sind aktiv; PyPI und Codecov entfallen.
- Abweichung nur mit Begründung im Plan; dauerhafte Abweichungen in dieser Tabelle nachtragen.
- Bleibende Besonderheiten: Webroot `public/`, Modulsystem, SQLite + PostgreSQL.
- Auslieferung nur über GitHub (Tag → `release.yml` → Image/Release → Updater); keinen `deploy/`-Spiegel oder Upload-Ordner einführen.
