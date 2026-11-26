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
- Code mit Prozess-, Pfad- oder Plattformabhängigkeit zusätzlich wie in der CI testen: `docker run --rm -v "${PWD}:/home/runner/work/app" -w /home/runner/work/app php:8.2-cli php vendor/bin/phpunit --filter …` (kleinste PHP-Version, Repository unter `/home` wie auf dem Runner, Befehl ohne `sh -c`) – E-067.

## E2E/Klicktests (`tests/e2e/`)

- Laufen nur auf ausdrückliche Aufforderung (siehe `AGENTS.md`), nie automatisch/pre-commit/CI-Pflicht.
- Jeder Testlauf erzeugt eigene Testdaten mit eindeutigem Namen (Zeitstempel/Zufallssuffix) statt
  feste Namen wiederzuverwenden – Tests laufen parallel über mehrere Worker/Server-Instanzen.
- Keine festen `waitForTimeout(...)`; auf konkrete Zustände warten (`expect(...).toHaveURL(...)`,
  sichtbare/stabile Elemente, `page.waitForLoadState(...)`).
- Locator nach Rolle/Text (`getByRole`, `getByLabel`) statt CSS-Selektoren, wo möglich; bei
  mehrdeutigen Treffern (z. B. gleich benannte Felder in unterschiedlichen Formularen) auf das
  passende Formular/Container eingrenzen statt `exact`/Selektoren zu verkomplizieren.
  „Failed to load resource“-Konsolenmeldungen (Browser-Netzwerkdiagnose für fehlgeschlagene
  Requests) nicht mit echten `console.error()`-Aufrufen der App verwechseln – Status ≥ 500 wird
  separat geprüft.
- IDs aus DOM/App-Daten vor dem Vergleich in Selektoren/Fixture-Code als String normalisieren (E-096).
