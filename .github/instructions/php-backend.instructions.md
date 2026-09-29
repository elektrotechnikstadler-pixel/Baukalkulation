---
description: "Use when editing PHP backend code: API handlers, services, modules, cron scripts, SQL queries, permissions."
applyTo: "**/src/**/*.php, **/modules/**/*.php, **/public/*.php, **/cron_*.php, **/bin/console"
---
# PHP-Backend

- SQL muss auf SQLite und PostgreSQL laufen. Verboten: `COLLATE NOCASE` in Abfragen (→ `LOWER()`),
  `INSERT OR REPLACE` (→ `ON CONFLICT … DO UPDATE`, Zielspalte qualifizieren), `JSON_*`, `GROUP_CONCAT`
  (→ `Dialect::groupConcat()`), `BEGIN IMMEDIATE` (→ `Dialect::beginExclusive()`), SQLite-Datumsfunktionen.
- Spaltennamen camelCase; `PgsqlDialect` quotet automatisch – nicht selbst quoten.
- Nur Prepared Statements mit Parametern; nie Nutzereingaben in SQL-Strings einsetzen.
- Jede neue Aktion prüft Rechte (`Auth::requireAuth()` bzw. `$this->requirePerm(...)` in Modulen).
- Antworten über `jsonOut()`; Nutzertext in Fehlermeldungen mit `htmlspecialchars` escapen.
- Dateipfade aus Eingaben mit Whitelist/`basename` absichern (kein Path-Traversal).
- Geheime Einstellungen über `Auth::SECRET_SETTINGS` / `SecretBox` speichern, nie im Klartext.
- Kein `@`-Operator; Fehlerfälle explizit prüfen.
- Neuer Code ohne neue PHPStan-Baseline-Einträge (Level 5).
