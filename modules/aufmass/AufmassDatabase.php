<?php
// ============================================================
// Aufmaß-Modul – Datenbank-Schema (v2.1)
// ============================================================
// Idempotente Migration: legt Tabellen für Aufmaße, optionale
// Abschnitte und Positionen an. Wird vom ModuleLoader bei jedem
// Boot aufgerufen (CREATE TABLE IF NOT EXISTS …).
// ============================================================

namespace App\Modules\Aufmass;

class AufmassDatabase
{
    public static function init(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS aufmass (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                baustelle_id  INTEGER,
                titel         TEXT,
                status        TEXT NOT NULL DEFAULT 'entwurf' CHECK(status IN ('entwurf','geprueft','uebernommen')),
                erstellt_von  TEXT,
                erstellt_am   TEXT DEFAULT CURRENT_TIMESTAMP,
                geprueft_von  TEXT,
                geprueft_am   TEXT,
                uebernommen_am TEXT,
                notiz         TEXT
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_aufmass_baustelle ON aufmass(baustelle_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_aufmass_status ON aufmass(status)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS aufmass_abschnitt (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                aufmass_id INTEGER NOT NULL,
                name       TEXT,
                sortier    INTEGER DEFAULT 0,
                FOREIGN KEY(aufmass_id) REFERENCES aufmass(id) ON DELETE CASCADE
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_aufmass_abs_aufmass ON aufmass_abschnitt(aufmass_id)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS aufmass_position (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                aufmass_id   INTEGER NOT NULL,
                abschnitt_id INTEGER,
                bezeichnung  TEXT,
                formel       TEXT,
                menge        REAL DEFAULT 0,
                einheit      TEXT,
                einzelpreis  REAL,
                ek           REAL,
                ref_typ      TEXT,
                ref_id       TEXT,
                ausgewaehlt  INTEGER DEFAULT 1,
                sortier      INTEGER DEFAULT 0,
                notiz        TEXT,
                FOREIGN KEY(aufmass_id)   REFERENCES aufmass(id) ON DELETE CASCADE,
                FOREIGN KEY(abschnitt_id) REFERENCES aufmass_abschnitt(id) ON DELETE SET NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_aufmass_pos_aufmass ON aufmass_position(aufmass_id)");
    }
}
