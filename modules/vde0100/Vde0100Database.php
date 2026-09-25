<?php
// ============================================================
// Vde0100Database – Schema & Migrationen (DIN VDE 0100-600)
// ============================================================

namespace App\Modules\Vde0100;

final class Vde0100Database
{
    public static function init(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_protokolle (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                titel             TEXT    NOT NULL DEFAULT '',
                kundeId           INTEGER DEFAULT NULL,
                baustelleId       TEXT    DEFAULT NULL,
                status            TEXT    NOT NULL DEFAULT 'entwurf',
                anlagenart        TEXT    NOT NULL DEFAULT '',
                schutzmassnahme   TEXT    NOT NULL DEFAULT '',
                nennspannung      TEXT    NOT NULL DEFAULT '230',
                nennfrequenz      TEXT    NOT NULL DEFAULT '50',
                nennstrom         TEXT    NOT NULL DEFAULT '',
                erstellt_von      TEXT    NOT NULL DEFAULT '',
                erstellt_am       TEXT    NOT NULL DEFAULT '',
                fixiert_am        TEXT    NOT NULL DEFAULT '',
                unterschrift      TEXT    NOT NULL DEFAULT '',
                bemerkung         TEXT    NOT NULL DEFAULT ''
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_gebaeude (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                protokollId INTEGER NOT NULL,
                bezeichnung TEXT    NOT NULL DEFAULT '',
                sortPos     INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_verteiler (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                gebaeudeId     INTEGER NOT NULL,
                bezeichnung    TEXT    NOT NULL DEFAULT '',
                nennstrom      REAL    DEFAULT NULL,
                rcd_vorhanden  INTEGER NOT NULL DEFAULT 0,
                rcd_nennstrom  REAL    DEFAULT NULL,
                rcd_nennfehler REAL    DEFAULT NULL,
                rcd_typ        TEXT    NOT NULL DEFAULT '',
                sortPos        INTEGER NOT NULL DEFAULT 0,
                bemerkung      TEXT    NOT NULL DEFAULT ''
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_sicherungen (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                verteilerId      INTEGER NOT NULL,
                bezeichnung      TEXT    NOT NULL DEFAULT '',
                typ              TEXT    NOT NULL DEFAULT '',
                nennstrom        REAL    DEFAULT NULL,
                leiterquerschnitt TEXT   NOT NULL DEFAULT '',
                messung_iso      REAL    DEFAULT NULL,
                messung_zs       REAL    DEFAULT NULL,
                messung_rcd_id   REAL    DEFAULT NULL,
                messung_rcd_tt   REAL    DEFAULT NULL,
                messung_rb       REAL    DEFAULT NULL,
                messung_re       REAL    DEFAULT NULL,
                pruefstatus      TEXT    NOT NULL DEFAULT '',
                sortPos          INTEGER NOT NULL DEFAULT 0,
                bemerkung        TEXT    NOT NULL DEFAULT ''
            )
        ");

        // Gruppen-Vorlagen (v2.7.1) – benutzerdefinierte RCD-Gruppen
        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_templates (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                beschreibung TEXT   NOT NULL DEFAULT '',
                daten       TEXT    NOT NULL DEFAULT '{}',
                erstellt_von TEXT   NOT NULL DEFAULT '',
                erstellt_am TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // RCD-Ebene (v2.7.2) – Verteiler → RCD → Abgänge
        $db->exec("
            CREATE TABLE IF NOT EXISTS vde0100_rcd (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                verteilerId     INTEGER NOT NULL,
                bezeichnung     TEXT    NOT NULL DEFAULT 'RCD',
                nennstrom       REAL    DEFAULT NULL,
                nennfehlerstrom REAL    DEFAULT NULL,
                typ             TEXT    NOT NULL DEFAULT '',
                sortPos         INTEGER NOT NULL DEFAULT 0,
                bemerkung       TEXT    NOT NULL DEFAULT ''
            )
        ");

        // Protokolle: norm + projektnummer ergänzen (v2.7.3)
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN norm TEXT NOT NULL DEFAULT 'vde0100_600'");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN projektnummer TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // Protokolle: pruef_datum ergänzen (v2.7.4)
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN pruef_datum TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // Sicherungen: rcdId-Spalte ergänzen (v2.7.2)
        try {
            $db->exec("ALTER TABLE vde0100_sicherungen ADD COLUMN rcdId INTEGER DEFAULT NULL");
        } catch (\Exception $e) {
            // Spalte existiert bereits – ignorieren
        }

        // Datenmigration (v2.7.2): bestehende Sicherungen in RCD-Struktur überführen
        $needsMigration = (int)$db->query(
            "SELECT COUNT(*) FROM vde0100_sicherungen WHERE rcdId IS NULL"
        )->fetchColumn();

        if ($needsMigration > 0) {
            $stmt = $db->query(
                "SELECT DISTINCT s.verteilerId FROM vde0100_sicherungen s
                 WHERE s.rcdId IS NULL AND s.verteilerId IS NOT NULL"
            );
            $verteilerIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($verteilerIds as $vid) {
                $vStmt = $db->prepare("SELECT * FROM vde0100_verteiler WHERE id = ?");
                $vStmt->execute([$vid]);
                $v = $vStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$v) continue;

                // RCD-Bezeichnung aus alten Verteiler-FI-Feldern ableiten
                if ((int)$v['rcd_vorhanden']) {
                    $parts = array_filter([
                        $v['rcd_nennstrom']  ? $v['rcd_nennstrom']  . ' A'  : '',
                        $v['rcd_nennfehler'] ? $v['rcd_nennfehler'] . ' mA' : '',
                        $v['rcd_typ']        ? 'Typ ' . $v['rcd_typ']        : '',
                    ]);
                    $rcdBez            = 'FI ' . implode(' / ', $parts);
                    $rcdNennstrom      = $v['rcd_nennstrom']  !== null ? (float)$v['rcd_nennstrom']  : null;
                    $rcdNennfehlerstrom= $v['rcd_nennfehler'] !== null ? (float)$v['rcd_nennfehler'] : null;
                    $rcdTyp            = $v['rcd_typ'] ?? '';
                } else {
                    $rcdBez             = 'Direktabgänge';
                    $rcdNennstrom       = null;
                    $rcdNennfehlerstrom = null;
                    $rcdTyp             = '';
                }

                $ins = $db->prepare(
                    "INSERT INTO vde0100_rcd (verteilerId, bezeichnung, nennstrom, nennfehlerstrom, typ, sortPos)
                     VALUES (?,?,?,?,?,0)"
                );
                $ins->execute([$vid, $rcdBez, $rcdNennstrom, $rcdNennfehlerstrom, $rcdTyp]);
                $rcdId = (int)$db->lastInsertId();

                $upd = $db->prepare(
                    "UPDATE vde0100_sicherungen SET rcdId = ? WHERE verteilerId = ? AND rcdId IS NULL"
                );
                $upd->execute([$rcdId, $vid]);
            }
        }

        // ── v2.8.0: neue Messfelder je Abgang (Zi, Polarität) ────────
        try {
            $db->exec("ALTER TABLE vde0100_sicherungen ADD COLUMN messung_zi REAL DEFAULT NULL");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_sicherungen ADD COLUMN messung_polaritaet TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // ── v2.8.0: RCD-Messfelder (Auslösestrom + Auslösezeit) ──────
        try {
            $db->exec("ALTER TABLE vde0100_rcd ADD COLUMN messung_rcd_id REAL DEFAULT NULL");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_rcd ADD COLUMN messung_rcd_tt REAL DEFAULT NULL");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // ── v2.8.0: Sichtprüfung + Funktionsprüfung je Protokoll ─────
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN sichtpruefung_status TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN sichtpruefung_bem TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN funktionspruefung_status TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN funktionspruefung_bem TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // ── v2.9.19: Messgerät-Felder ─────────────────────────
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN messgeraet_hersteller TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN messgeraet_typ TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN messgeraet_kalibrierung TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }

        // ── v2.10.1: Anlagenanschrift, Kundenanschrift, Netzbetreiber ─
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN anlagenanschrift TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN kundenanschrift TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
        try {
            $db->exec("ALTER TABLE vde0100_protokolle ADD COLUMN netzbetreiber TEXT NOT NULL DEFAULT ''");
        } catch (\Exception $e) { /* Spalte existiert bereits */ }
    }
}
