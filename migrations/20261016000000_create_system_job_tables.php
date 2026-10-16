<?php

use Phinx\Migration\AbstractMigration;

/**
 * Grundgerüst für T2 (Planer, Laufprotokoll, zeitgesteuerte Serversicherung) und
 * Vorarbeit für T3 (NAS-Abgleich, Überwachung) – AP-20261012-datensicherung-nas.
 * DDL in SQLite-Syntax, PgsqlDialect::translate() übersetzt für PostgreSQL.
 */
final class CreateSystemJobTables extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_einstellungen (
                id INTEGER PRIMARY KEY,
                data TEXT NOT NULL DEFAULT '{}'
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_status (
                job TEXT NOT NULL PRIMARY KEY,
                letzterLaufAm TEXT NOT NULL DEFAULT '',
                letzterErfolgAm TEXT NOT NULL DEFAULT '',
                letzterFehlerAm TEXT NOT NULL DEFAULT '',
                fehlerCode TEXT NOT NULL DEFAULT '',
                stoerungSeit TEXT NOT NULL DEFAULT '',
                laeuftSeit TEXT NOT NULL DEFAULT '',
                tageslaufDatum TEXT NOT NULL DEFAULT '',
                tageslaufStatus TEXT NOT NULL DEFAULT '',
                versuche INTEGER NOT NULL DEFAULT 0,
                naechsterVersuchAm TEXT NOT NULL DEFAULT ''
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_laeufe (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT NOT NULL DEFAULT '',
                ausloeser TEXT NOT NULL DEFAULT '',
                gestartetAm TEXT NOT NULL DEFAULT '',
                beendetAm TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '',
                fehlerCode TEXT NOT NULL DEFAULT '',
                meldung TEXT NOT NULL DEFAULT '',
                kennzahlen TEXT NOT NULL DEFAULT '{}'
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_job_laeufe_job_gestartet ON job_laeufe(job, gestartetAm)');

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_auftraege (
                id TEXT NOT NULL PRIMARY KEY,
                job TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '',
                erstelltAm TEXT NOT NULL DEFAULT '',
                ablaufAm TEXT NOT NULL DEFAULT '',
                aktualisiertAm TEXT NOT NULL DEFAULT '',
                angefordertVon TEXT NOT NULL DEFAULT '',
                payload TEXT NOT NULL DEFAULT '',
                ergebnis TEXT NOT NULL DEFAULT '{}'
            )
        ");

        // Wird erst in T3 genutzt (Überwachung/Outbox) – Tabelle bereits jetzt anlegen,
        // damit die Fixture-Erneuerung und Importer::SKIP_TABLES nicht nochmals ändern müssen.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS meldungen_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                schluessel TEXT NOT NULL DEFAULT '' UNIQUE,
                job TEXT NOT NULL DEFAULT '',
                typ TEXT NOT NULL DEFAULT '',
                daten TEXT NOT NULL DEFAULT '{}',
                erstelltAm TEXT NOT NULL DEFAULT '',
                gesendetAm TEXT NOT NULL DEFAULT '',
                versuche INTEGER NOT NULL DEFAULT 0,
                naechsterVersuchAm TEXT NOT NULL DEFAULT ''
            )
        ");
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
