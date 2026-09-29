---
description: "Use when changing the database schema: Phinx migrations, new tables or columns, module schema, backup fixtures."
applyTo: "**/migrations/**, **/src/Database/**"
---
# Migrationen

- Neue Migration: `php vendor/bin/phinx create AddFooToBar`. Nur vorwärts; `down()` darf werfen.
- `20260925000000_legacy_baseline.php` und `src/Database/LegacySchema.php` sind eingefroren.
- DDL in SQLite-Syntax schreiben; `PgsqlDialect::translate()` übersetzt für PostgreSQL.
- Nach jeder Migration: `php tests/bin/build-backup-fixture.php` und neue Fixture einchecken.
- Alle Tests auf SQLite **und** PostgreSQL ausführen (`make test-pgsql` oder Anleitung in docs/entwicklung.md).
- Backups älterer Versionen müssen danach weiterhin importierbar sein (Tests in `tests/Api/`).
