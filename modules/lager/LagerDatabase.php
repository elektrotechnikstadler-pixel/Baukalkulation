<?php
// ============================================================
// Lager-Modul – Datenbank-Schema (v2.1)
// ============================================================
// Idempotente Migration: Lagerorte und Lagerartikel.
// Bestand wird direkt im Artikel geführt (menge_aktuell);
// Bestandsänderungen werden zusätzlich im Audit-Log protokolliert.
// ============================================================

namespace App\Modules\Lager;

class LagerDatabase
{
    public static function init(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lager_orte (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT NOT NULL UNIQUE,
                beschreibung TEXT,
                aktiv        INTEGER NOT NULL DEFAULT 1,
                erstellt_am  TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS lager_artikel (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                artikelnr      TEXT,
                bezeichnung    TEXT NOT NULL,
                menge          REAL NOT NULL DEFAULT 0,
                einheit        TEXT,
                ek_preis       REAL,
                vk_preis       REAL,
                lagerort_id    INTEGER,
                mindestbestand REAL DEFAULT 0,
                kategorie      TEXT,
                notiz          TEXT,
                erstellt_von   TEXT,
                erstellt_am    TEXT DEFAULT CURRENT_TIMESTAMP,
                geaendert_am   TEXT,
                FOREIGN KEY(lagerort_id) REFERENCES lager_orte(id) ON DELETE SET NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_lager_art_bez ON lager_artikel(LOWER(bezeichnung))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_lager_art_nr  ON lager_artikel(artikelnr)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_lager_art_ort ON lager_artikel(lagerort_id)");
    }
}
