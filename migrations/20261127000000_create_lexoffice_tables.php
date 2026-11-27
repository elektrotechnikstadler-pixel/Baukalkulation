<?php

use Phinx\Migration\AbstractMigration;

/**
 * Lexware Office (AP-20261126-lexoffice-schnittstelle, B2): Verknüpfungen, Belegspiegel und
 * abgerechnete Projektpositionen (B4/B5) sowie Kundenfelder Land, USt-IdNr. und Leitweg-ID.
 * DDL in SQLite-Syntax, PgsqlDialect::translate() übersetzt für PostgreSQL.
 */
final class CreateLexofficeTables extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lexoffice_verknuepfung (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                objektTyp TEXT NOT NULL DEFAULT '',
                objektId TEXT NOT NULL DEFAULT '',
                lexId TEXT NOT NULL DEFAULT '',
                lexVersion INTEGER NOT NULL DEFAULT 0,
                organisationId TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '',
                fehlerCode TEXT NOT NULL DEFAULT '',
                inhaltHash TEXT NOT NULL DEFAULT '',
                zahlstatus TEXT NOT NULL DEFAULT '',
                uebertragenAm TEXT NOT NULL DEFAULT '',
                geprueftAm TEXT NOT NULL DEFAULT '',
                UNIQUE (objektTyp, objektId)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lexoffice_belege (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lexId TEXT NOT NULL DEFAULT '' UNIQUE,
                organisationId TEXT NOT NULL DEFAULT '',
                belegart TEXT NOT NULL DEFAULT '',
                nummer TEXT NOT NULL DEFAULT '',
                datum TEXT NOT NULL DEFAULT '',
                netto REAL NOT NULL DEFAULT 0,
                brutto REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT '',
                bezahltAm TEXT NOT NULL DEFAULT '',
                baustelleId INTEGER,
                kundeId INTEGER,
                vorgaengerLexId TEXT NOT NULL DEFAULT '',
                schlussrechnung INTEGER NOT NULL DEFAULT 0,
                geprueftAm TEXT NOT NULL DEFAULT ''
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lexoffice_belege_baustelle ON lexoffice_belege(baustelleId)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS lexoffice_abrechnung (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                baustelleId INTEGER NOT NULL DEFAULT 0,
                positionArt TEXT NOT NULL DEFAULT '',
                positionId TEXT NOT NULL DEFAULT '',
                lexId TEXT NOT NULL DEFAULT '',
                UNIQUE (baustelleId, positionArt, positionId, lexId)
            )
        ");

        $this->table('kunden')
            ->addColumn('land', 'string', ['default' => 'DE', 'null' => false])
            ->addColumn('ustId', 'string', ['default' => '', 'null' => false])
            ->addColumn('leitwegId', 'string', ['default' => '', 'null' => false])
            ->update();
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
