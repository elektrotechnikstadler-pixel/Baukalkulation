---
description: "Use when writing or changing PHPUnit tests, API characterization tests, fixtures, or test helpers."
applyTo: "**/tests/**"
---
# Tests

- `tests/Unit/` für reine Funktionen, `tests/Api/` für Verhalten über HTTP
  (PHP-Built-in-Server, leeres Datenverzeichnis per `BK_DATA_DIR`).
- Tests aus den Abnahmekriterien ableiten und Verhalten prüfen, nicht Implementierungsdetails.
- Bugfix: zuerst ein Test, der den Fehler rot zeigt; danach grün.
- Bestehende Tests nie abschwächen oder löschen, um Grün zu erreichen – Abweichung melden.
- Keine echten Kundendaten in Fixtures.
- Windows: SQLite-Dateien erst nach `gc_collect_cycles()` löschen/umbenennen.
- PostgreSQL-Läufe leeren das Schema `public` der Test-DB – nie gegen echte Datenbanken.
- Formatierung: `php vendor/bin/php-cs-fixer check --diff`.
