<?php

use Phinx\Migration\AbstractMigration;

/**
 * Die Prüf-Tabellen legte bisher erst der erste Aufruf der Wochen-/Tagesprüfung an.
 * Als Migration existieren sie in jeder DB – auch in der SQLite-Datei von Sicherungen.
 */
final class CreatePruefungTables extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        foreach (['zeiterfassung_wochenpruefung' => 'kw', 'zeiterfassung_tagespruefung' => 'datum'] as $table => $key) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    {$key} TEXT NOT NULL,
                    geprueft INTEGER NOT NULL DEFAULT 0,
                    geprueftVon TEXT NOT NULL DEFAULT '',
                    geprueftAm TEXT NOT NULL DEFAULT '',
                    kommentar TEXT NOT NULL DEFAULT ''
                )
            ");
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_zp_user_kw ON zeiterfassung_wochenpruefung(username, kw)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_tp_user_datum ON zeiterfassung_tagespruefung(username, datum)');
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
