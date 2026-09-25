<?php
namespace App\Database;

/**
 * Eingefrorener Schema-Stand bis v2.10.99 (vor Phinx). Wird nur von der Basis-Migration
 * genutzt: legt eine frische DB an oder hebt jede ältere SQLite-DB auf diesen Stand.
 * Neue Schemaänderungen gehören ausschließlich in migrations/.
 */
final class LegacySchema
{
    public static function apply(\PDO $db): void
    {
        // Ältere DBs: Indizes auf später ergänzte Spalten erst nach dem Upgrade anlegen.
        $deferred = [];
        foreach (self::statements() as $sql) {
            try {
                $db->exec($sql);
            } catch (\PDOException) {
                $deferred[] = $sql;
            }
        }
        self::upgrade($db);
        foreach ($deferred as $sql) {
            try {
                $db->exec($sql);
            } catch (\PDOException $e) {
                error_log('[LegacySchema] ' . $e->getMessage());
            }
        }
    }

    /** @return list<string> */
    private static function statements(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(';', self::SCHEMA)),
            static fn(string $s): bool => preg_match('/^\s*(CREATE|INSERT)/mi', $s) === 1,
        ));
    }

    private static function upgrade(\PDO $db): void
    {
        // gleitzeitkonto_buchungen-Tabelle anlegen (Migration für bestehende DBs)
        $db->exec("
            CREATE TABLE IF NOT EXISTS gleitzeitkonto_buchungen (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT    NOT NULL,
                datum        TEXT    NOT NULL DEFAULT '',
                betrag       REAL    NOT NULL DEFAULT 0,
                kommentar    TEXT    NOT NULL DEFAULT '',
                erstellt_von TEXT    NOT NULL DEFAULT '',
                erstellt_am  TEXT    NOT NULL DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_gleitzeit_user ON gleitzeitkonto_buchungen(username)");

        // theme-Spalte in users ergänzen
        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
        $colNames = array_column($cols, 'name');
        if (!in_array('theme', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN theme TEXT DEFAULT 'light'");
        }
        if (!in_array('sollTageWoche', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN sollTageWoche REAL DEFAULT 5");
        }

        // Benutzer-Profilfelder ergänzen
        if (!in_array('anschrift', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN anschrift TEXT DEFAULT ''");
        }
        if (!in_array('email', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN email TEXT DEFAULT ''");
        }
        if (!in_array('telefon', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN telefon TEXT DEFAULT ''");
        }

        // Kontosperre nach Fehlversuchen
        if (!in_array('isLocked', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN isLocked INTEGER DEFAULT 0");
        }
        if (!in_array('failedLoginAttempts', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN failedLoginAttempts INTEGER DEFAULT 0");
        }
        if (!in_array('mobileLightOnly', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN mobileLightOnly INTEGER DEFAULT 0");
        }
        if (!in_array('personalnummer', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN personalnummer TEXT DEFAULT ''");
        }
        if (!in_array('arbeitstage', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN arbeitstage TEXT DEFAULT '1,2,3,4,5'");
        }
        // v2.0.1: Letzter erfolgreicher Login (Unix-Timestamp)
        if (!in_array('lastLoginAt', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN lastLoginAt INTEGER DEFAULT 0");
        }
        // K2 (v1.7.1): Session-Invalidierung bei Rollen-/Sicherheits-Änderungen.
        // Unix-Timestamp; ältere Sessions als dieser Wert sind ungültig.
        if (!in_array('sessionInvalidatedAt', $colNames, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN sessionInvalidatedAt INTEGER DEFAULT 0");
        }

        // K4 (v1.7.1): Login-Rate-Limiting in SQLite (statt JSON-Datei,
        // race-condition-frei). Wird kontinuierlich aufgeräumt.
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ip       TEXT    NOT NULL DEFAULT '',
                username TEXT    NOT NULL DEFAULT '',
                ts       INTEGER NOT NULL DEFAULT 0
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_ts ON login_attempts(ip, ts)");

        // H4 (v1.8.0): Audit-Log mit HMAC-Hash-Chain für Manipulationserkennung.
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                ts        INTEGER NOT NULL,
                username  TEXT    NOT NULL DEFAULT '',
                action    TEXT    NOT NULL DEFAULT '',
                details   TEXT    NOT NULL DEFAULT '',
                prev_hash TEXT    NOT NULL DEFAULT '',
                hash      TEXT    NOT NULL DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_ts ON audit_log(ts)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log(username)");

        // Fehlbuchungs-Reconciliation: schneller Lookup Zeiterfassung ↔ Projektbuchung
        // über die exakte Verknüpfung (username, entryId).
        $db->exec("CREATE INDEX IF NOT EXISTS idx_zeit_user_entry ON zeiterfassung(username, entryId)");

        // Optimistic Locking: globale Datensatz-Revision gegen Lost-Updates beim
        // Ganzdatensatz-Full-Replace (save). Ein-Zeilen-Tabelle (id immer 1).
        $db->exec("CREATE TABLE IF NOT EXISTS data_meta (
            id        INTEGER PRIMARY KEY CHECK (id = 1),
            rev       INTEGER NOT NULL DEFAULT 0,
            updatedAt TEXT    DEFAULT '',
            updatedBy TEXT    DEFAULT ''
        )");
        $db->exec("INSERT OR IGNORE INTO data_meta (id, rev, updatedAt, updatedBy) VALUES (1, 0, '', '')");

        $kcols = $db->query("PRAGMA table_info(kunden)")->fetchAll();
        $kcolNames = array_column($kcols, 'name');
        if (!in_array('kundennummer', $kcolNames, true)) {
            $db->exec("ALTER TABLE kunden ADD COLUMN kundennummer TEXT DEFAULT ''");
        }

        $rcols = $db->query("PRAGMA table_info(rechnungen)")->fetchAll();
        $rcolNames = array_column($rcols, 'name');
        if (!in_array('beschreibung', $rcolNames, true)) {
            $db->exec("ALTER TABLE rechnungen ADD COLUMN beschreibung TEXT DEFAULT ''");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS metall_profile_catalog (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            data TEXT DEFAULT '{}'
        )");
        $db->exec("INSERT OR IGNORE INTO metall_profile_catalog (id, data) VALUES (1, '{}')");

        // HiCAD-Materialbibliothek (Import aus HALBZEUGE.IPL + Online-Gewichte)
        $db->exec("CREATE TABLE IF NOT EXISTS hicad_profile (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            kategorie_pfad TEXT    NOT NULL DEFAULT '',
            kategorie      TEXT    NOT NULL DEFAULT '',
            tabelle        TEXT    NOT NULL DEFAULT '',
            bezeichnung    TEXT    NOT NULL DEFAULT '',
            typ            TEXT    NOT NULL DEFAULT 'profil',
            kg_pro_m       REAL,
            material       TEXT    DEFAULT 'S235',
            quelle         TEXT    DEFAULT 'hicad-ipl',
            updated_at     TEXT    DEFAULT ''
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hicad_kat ON hicad_profile(kategorie)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_hicad_typ ON hicad_profile(typ)");
        $db->exec("CREATE TABLE IF NOT EXISTS hicad_profile_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            data TEXT DEFAULT '{}'
        )");
        $db->exec("INSERT OR IGNORE INTO hicad_profile_state (id, data) VALUES (1, '{}')");

        // termine-Tabelle anlegen (falls noch nicht vorhanden)
        $db->exec("
            CREATE TABLE IF NOT EXISTS termine (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                titel        TEXT    NOT NULL DEFAULT '',
                beschreibung TEXT    DEFAULT '',
                datum        TEXT    NOT NULL,
                zeitVon      TEXT    DEFAULT '',
                zeitBis      TEXT    DEFAULT '',
                ganztags     INTEGER DEFAULT 0,
                ort          TEXT    DEFAULT '',
                baustelleId  INTEGER,
                zugewiesen   TEXT    DEFAULT '[]',
                ersteller    TEXT    DEFAULT '',
                erstelltAm   TEXT    DEFAULT '',
                farbe        TEXT    DEFAULT '#00B4D8',
                wiederholung TEXT    DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_termine_datum ON termine(datum)");

        // Dashboard-Einträge (v2.4)
        $db->exec("
            CREATE TABLE IF NOT EXISTS dashboard_items (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                typ           TEXT    NOT NULL DEFAULT 'aufgabe',
                titel         TEXT    NOT NULL DEFAULT '',
                beschreibung  TEXT    DEFAULT '',
                faelligAm     TEXT    DEFAULT '',
                status        TEXT    NOT NULL DEFAULT 'offen',
                prioritaet    TEXT    NOT NULL DEFAULT 'normal',
                linkTyp       TEXT    DEFAULT '',
                linkId        INTEGER DEFAULT NULL,
                ersteller     TEXT    DEFAULT '',
                erstelltAm    TEXT    DEFAULT '',
                farbe         TEXT    DEFAULT '#00B4D8',
                sortPos       INTEGER DEFAULT 0,
                zugewiesen_an TEXT    DEFAULT ''
            )
        ");

        // Dashboard: zugewiesen_an Spalte (v2.5, Migration für bestehende DBs)
        $dbCols = $db->query("PRAGMA table_info(dashboard_items)")->fetchAll();
        $dbColNames = array_column($dbCols, 'name');
        if (!in_array('zugewiesen_an', $dbColNames, true)) {
            $db->exec("ALTER TABLE dashboard_items ADD COLUMN zugewiesen_an TEXT DEFAULT ''");
        }
        // Dashboard: weitere Spalten für sehr alte DBs nachrüsten (idempotent).
        // Fehlt eine dieser Spalten (DB vor v2.4-Vollschema), schlägt das INSERT in
        // saveItem() mit HTTP 500 fehl. ADD COLUMN nur, wenn tatsächlich nicht vorhanden.
        $dashDefaults = [
            'beschreibung' => "TEXT DEFAULT ''",
            'faelligAm'    => "TEXT DEFAULT ''",
            'prioritaet'   => "TEXT NOT NULL DEFAULT 'normal'",
            'linkTyp'      => "TEXT DEFAULT ''",
            'linkId'       => "INTEGER DEFAULT NULL",
            'ersteller'    => "TEXT DEFAULT ''",
            'erstelltAm'   => "TEXT DEFAULT ''",
            'farbe'        => "TEXT DEFAULT '#00B4D8'",
            'sortPos'      => "INTEGER DEFAULT 0",
        ];
        foreach ($dashDefaults as $col => $ddl) {
            if (!in_array($col, $dbColNames, true)) {
                $db->exec("ALTER TABLE dashboard_items ADD COLUMN $col $ddl");
            }
        }

        // Zeiterfassung: erweiterte Felder (von, bis, pause)
        $zcols = $db->query("PRAGMA table_info(zeiterfassung)")->fetchAll();
        $zcolNames = array_column($zcols, 'name');
        if (!in_array('von', $zcolNames, true)) {
            $db->exec("ALTER TABLE zeiterfassung ADD COLUMN von TEXT DEFAULT ''");
        }
        if (!in_array('bis', $zcolNames, true)) {
            $db->exec("ALTER TABLE zeiterfassung ADD COLUMN bis TEXT DEFAULT ''");
        }
        if (!in_array('pause', $zcolNames, true)) {
            $db->exec("ALTER TABLE zeiterfassung ADD COLUMN pause REAL DEFAULT 0");
        }
        // Stundenkategorie pro Zeiteintrag (für serverseitige Projektbuchung/Auto-Import)
        if (!in_array('stundenKatId', $zcolNames, true)) {
            $db->exec("ALTER TABLE zeiterfassung ADD COLUMN stundenKatId INTEGER");
        }

        // ── Härtung Zeitbuchung: stabile Identität, Status, Fehlergrund ──────
        // clientUuid ist die geräteübergreifend stabile Identität eines Eintrags und
        // ersetzt die kollisionsanfällige, clientseitig vergebene entryId.
        // baustelleName wird denormalisiert, damit die Zuordnung ein Archivieren oder
        // Löschen des Projekts überlebt (Zeiteintrag bleibt gültige Arbeitsleistung).
        $zeitHardening = [
            'clientUuid'    => "TEXT    NOT NULL DEFAULT ''",
            'status'        => "TEXT    NOT NULL DEFAULT 'booked_valid'",
            'errorCode'     => "TEXT    NOT NULL DEFAULT ''",
            'errorMessage'  => "TEXT    NOT NULL DEFAULT ''",
            'baustelleName' => "TEXT    NOT NULL DEFAULT ''",
            'createdAt'     => "TEXT    NOT NULL DEFAULT ''",
            'updatedAt'     => "TEXT    NOT NULL DEFAULT ''",
            'quelle'        => "TEXT    NOT NULL DEFAULT ''",
        ];
        foreach ($zeitHardening as $col => $ddl) {
            if (!in_array($col, $zcolNames, true)) {
                $db->exec("ALTER TABLE zeiterfassung ADD COLUMN $col $ddl");
            }
        }

        // Backfill (idempotent, nur unbefüllte Zeilen). pk ist AUTOINCREMENT und wird
        // nie wiederverwendet -> die Legacy-UUID ist garantiert eindeutig.
        $db->exec("UPDATE zeiterfassung SET clientUuid = 'legacy:' || pk WHERE clientUuid = ''");
        $db->exec("UPDATE zeiterfassung SET status = 'booked_valid' WHERE status = ''");
        $db->exec("
            UPDATE zeiterfassung
               SET baustelleName = COALESCE((SELECT b.name FROM baustellen b WHERE b.id = zeiterfassung.baustelleId), '')
             WHERE baustelleName = '' AND baustelleId IS NOT NULL
        ");
        $nowTs = date('Y-m-d H:i:s');
        $db->prepare("UPDATE zeiterfassung SET createdAt = ? WHERE createdAt = ''")->execute([$nowTs]);
        $db->prepare("UPDATE zeiterfassung SET updatedAt = ? WHERE updatedAt = ''")->execute([$nowTs]);

        $db->exec("CREATE INDEX IF NOT EXISTS idx_zeit_status ON zeiterfassung(username, status)");
        // Idempotenz-Schlüssel für das Delta-Upsert. Schlägt bei Altbestands-Duplikaten
        // fehl; dann bleibt der Legacy-Pfad aktiv und der Index wird beim nächsten
        // Start erneut versucht (siehe diagnose_fehlbuchungen -> entryIdDuplikate).
        try {
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_zeit_uuid ON zeiterfassung(username, clientUuid)");
        } catch (\Throwable $e) {
            error_log('[bk] idx_zeit_uuid nicht anlegbar (Duplikate im Bestand): ' . $e->getMessage());
        }

        // Optimistic Locking pro Benutzer für save_zeiterfassung.
        $db->exec("
            CREATE TABLE IF NOT EXISTS zeiterfassung_meta (
                username  TEXT    PRIMARY KEY,
                rev       INTEGER NOT NULL DEFAULT 0,
                updatedAt TEXT    NOT NULL DEFAULT '',
                updatedBy TEXT    NOT NULL DEFAULT ''
            )
        ");

        // ── Soft-Delete für Baustellen ───────────────────────────────────────
        // Archivieren löschte die Zeile bisher hart -> zeiterfassung.baustelleId zeigte
        // ins Leere und alle betroffenen Buchungen galten als Fehlbuchung.
        $bcolNames = array_column($db->query("PRAGMA table_info(baustellen)")->fetchAll(), 'name');
        $bauArchiv = [
            'archiviert'   => "INTEGER NOT NULL DEFAULT 0",
            'archivFile'   => "TEXT    NOT NULL DEFAULT ''",
            'archiviertAm' => "TEXT    NOT NULL DEFAULT ''",
            'archiviertVon'=> "TEXT    NOT NULL DEFAULT ''",
        ];
        foreach ($bauArchiv as $col => $ddl) {
            if (!in_array($col, $bcolNames, true)) {
                $db->exec("ALTER TABLE baustellen ADD COLUMN $col $ddl");
            }
        }
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bau_archiviert ON baustellen(archiviert)");

        // Audit-Trail für Zeiterfassung
        $db->exec("
            CREATE TABLE IF NOT EXISTS zeiterfassung_log (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT    NOT NULL,
                entryId      INTEGER,
                aktion       TEXT    NOT NULL DEFAULT '',
                alteWerte    TEXT    DEFAULT '',
                neueWerte    TEXT    DEFAULT '',
                geaendertVon TEXT    NOT NULL DEFAULT '',
                geaendertAm  TEXT    NOT NULL DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_zeitlog_user ON zeiterfassung_log(username)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_zeitlog_user_entry ON zeiterfassung_log(username, entryId)");

        // Kalender-Tokens (Migration für bestehende DBs)
        $db->exec("
            CREATE TABLE IF NOT EXISTS cal_tokens (
                token    TEXT PRIMARY KEY,
                username TEXT NOT NULL,
                role     TEXT DEFAULT 'normal',
                created  TEXT DEFAULT '',
                filter   TEXT DEFAULT ''
            )
        ");
        // filter (JSON, z. B. {"users":[...]}) für Personen-Auswahl beim Abo (Migration)
        $ctcols = array_column($db->query("PRAGMA table_info(cal_tokens)")->fetchAll(), 'name');
        if (!in_array('filter', $ctcols, true)) {
            $db->exec("ALTER TABLE cal_tokens ADD COLUMN filter TEXT DEFAULT ''");
        }

        // OCI Punchout (v2.6.9): Lieferanten-Konfiguration
        $db->exec("
            CREATE TABLE IF NOT EXISTS oci_lieferanten (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL DEFAULT '',
                url        TEXT    NOT NULL DEFAULT '',
                username   TEXT    DEFAULT '',
                password   TEXT    DEFAULT '',
                aktiv      INTEGER DEFAULT 1,
                notizen    TEXT    DEFAULT '',
                erstelltAm TEXT    DEFAULT ''
            )
        ");

        // OCI Punchout (v2.6.9): Einmalige Nonces (TTL 30 min, Einmal-Verwendung)
        $db->exec("
            CREATE TABLE IF NOT EXISTS oci_nonces (
                nonce        TEXT    PRIMARY KEY,
                lieferantId  INTEGER NOT NULL,
                erstelltAm   TEXT    NOT NULL DEFAULT '',
                verwendetAm  TEXT    DEFAULT NULL
            )
        ");

        // betriebTyp-Spalte in wochenplanung (v2.9.0 – Betriebseintrag für alle MA)
        $wpcols    = $db->query("PRAGMA table_info(wochenplanung)")->fetchAll();
        $wpcolNames = array_column($wpcols, 'name');
        if (!in_array('betriebTyp', $wpcolNames, true)) {
            $db->exec("ALTER TABLE wochenplanung ADD COLUMN betriebTyp TEXT DEFAULT ''");
        }

        // v2.10.0: Gruppen-Tabellen
        $db->exec("
            CREATE TABLE IF NOT EXISTS gruppen (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL DEFAULT '',
                farbe       TEXT    DEFAULT '#6366f1',
                erstelltAm  TEXT    DEFAULT '',
                erstelltVon TEXT    DEFAULT ''
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS gruppen_mitglieder (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                gruppen_id  INTEGER NOT NULL,
                username    TEXT    NOT NULL,
                UNIQUE(gruppen_id, username)
            )
        ");

        // v2.10.0: zugewiesen_an für Schnellnotizen
        $sncols    = $db->query("PRAGMA table_info(schnellnotizen)")->fetchAll();
        $sncolNames = array_column($sncols, 'name');
        if (!in_array('zugewiesen_an', $sncolNames, true)) {
            $db->exec("ALTER TABLE schnellnotizen ADD COLUMN zugewiesen_an TEXT DEFAULT ''");
        }
    }

    private const SCHEMA = <<<'SQL'

    -- ── Benutzer ─────────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS users (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        username             TEXT    UNIQUE NOT NULL COLLATE NOCASE,
        password             TEXT    NOT NULL,
        role                 TEXT    NOT NULL DEFAULT 'normal',
        kuerzel              TEXT    DEFAULT '',
        visibleBaustellen    TEXT    DEFAULT 'all',
        mustChangePassword   INTEGER DEFAULT 0,
        isSubunternehmer     INTEGER DEFAULT 0,
        dienstleisterId      INTEGER,
        stundenKategorie     TEXT    DEFAULT '',
        showInZeitverwaltung INTEGER DEFAULT 1,
        showInWochenplanung  INTEGER DEFAULT 1,
        sollstunden          REAL    DEFAULT 0,
        sollstundenTag       REAL    DEFAULT 8,
        sollTageWoche        REAL    DEFAULT 5,
        urlaubstageProJahr   TEXT    DEFAULT '{}',
        anschrift            TEXT    DEFAULT '',
        email                TEXT    DEFAULT '',
        telefon              TEXT    DEFAULT '',
        isLocked             INTEGER DEFAULT 0,
        failedLoginAttempts  INTEGER DEFAULT 0,
        mobileLightOnly      INTEGER DEFAULT 0,
        personalnummer       TEXT    DEFAULT ''
    );

    -- ── Baustellen (Hybrid: Spalten + JSON-Blob) ────────────
    CREATE TABLE IF NOT EXISTS baustellen (
        id       INTEGER PRIMARY KEY,
        name     TEXT    NOT NULL DEFAULT '',
        kundeId  INTEGER,
        data     TEXT    NOT NULL DEFAULT '{}',
        archiviert    INTEGER NOT NULL DEFAULT 0,
        archivFile    TEXT    NOT NULL DEFAULT '',
        archiviertAm  TEXT    NOT NULL DEFAULT '',
        archiviertVon TEXT    NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_bau_kunde ON baustellen(kundeId);
    CREATE INDEX IF NOT EXISTS idx_bau_archiviert ON baustellen(archiviert);

    -- ── Globale Pauschalen ───────────────────────────────────
    CREATE TABLE IF NOT EXISTS pauschalen (
        id    INTEGER PRIMARY KEY,
        name  TEXT    NOT NULL DEFAULT '',
        preis REAL    DEFAULT 0
    );

    -- ── Stundenkatalog ───────────────────────────────────────
    CREATE TABLE IF NOT EXISTS stunden_katalog (
        id        INTEGER PRIMARY KEY,
        kategorie TEXT    DEFAULT '',
        preis     REAL    DEFAULT 0,
        fixkosten REAL    DEFAULT 0
    );

    -- ── Materialkatalog ──────────────────────────────────────
    CREATE TABLE IF NOT EXISTS material_katalog (
        id          INTEGER PRIMARY KEY,
        bezeichnung TEXT    DEFAULT '',
        einheit     TEXT    DEFAULT 'Stk',
        ek          REAL    DEFAULT 0,
        aufschlag   REAL    DEFAULT 0,
        artikelNr   TEXT    DEFAULT ''
    );

    -- ── Zeiterfassung ────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS zeiterfassung (
        pk          INTEGER PRIMARY KEY AUTOINCREMENT,
        username    TEXT    NOT NULL,
        entryId     INTEGER,
        datum       TEXT    DEFAULT '',
        typ         TEXT    DEFAULT '',
        baustelleId INTEGER,
        stunden     REAL    DEFAULT 0,
        bemerkung   TEXT    DEFAULT '',
        von         TEXT    DEFAULT '',
        bis         TEXT    DEFAULT '',
        pause       REAL    DEFAULT 0,
        stundenKatId INTEGER,
        clientUuid    TEXT NOT NULL DEFAULT '',
        status        TEXT NOT NULL DEFAULT 'booked_valid',
        errorCode     TEXT NOT NULL DEFAULT '',
        errorMessage  TEXT NOT NULL DEFAULT '',
        baustelleName TEXT NOT NULL DEFAULT '',
        createdAt     TEXT NOT NULL DEFAULT '',
        updatedAt     TEXT NOT NULL DEFAULT '',
        quelle        TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_zeit_user  ON zeiterfassung(username);
    CREATE INDEX IF NOT EXISTS idx_zeit_datum ON zeiterfassung(datum);
    CREATE INDEX IF NOT EXISTS idx_zeit_baustelle ON zeiterfassung(baustelleId);
    CREATE INDEX IF NOT EXISTS idx_zeit_status ON zeiterfassung(username, status);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_zeit_uuid ON zeiterfassung(username, clientUuid);

    -- ── Gleitzeitkonto-Buchungen ─────────────────────────────
    CREATE TABLE IF NOT EXISTS gleitzeitkonto_buchungen (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        username    TEXT    NOT NULL,
        datum       TEXT    NOT NULL DEFAULT '',
        betrag      REAL    NOT NULL DEFAULT 0,
        kommentar   TEXT    NOT NULL DEFAULT '',
        erstellt_von TEXT   NOT NULL DEFAULT '',
        erstellt_am  TEXT   NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_gleitzeit_user ON gleitzeitkonto_buchungen(username);

    -- ── Kunden ───────────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS kunden (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        firma    TEXT DEFAULT '',
        anrede   TEXT DEFAULT '',
        vorname  TEXT DEFAULT '',
        nachname TEXT DEFAULT '',
        strasse  TEXT DEFAULT '',
        plz      TEXT DEFAULT '',
        ort      TEXT DEFAULT '',
        telefon  TEXT DEFAULT '',
        mobil    TEXT DEFAULT '',
        email    TEXT DEFAULT '',
        notizen  TEXT DEFAULT '',
        erstellt  TEXT DEFAULT '',
        geaendert TEXT DEFAULT '',
        kundennummer TEXT DEFAULT ''
    );

    -- ── Dienstleister ────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS dienstleister (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        firma   TEXT    NOT NULL DEFAULT '',
        kontakt TEXT    DEFAULT '',
        telefon TEXT    DEFAULT '',
        email   TEXT    DEFAULT '',
        notizen TEXT    DEFAULT ''
    );

    -- ── Wochenplanung ────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS wochenplanung (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        username    TEXT    NOT NULL,
        datum       TEXT    NOT NULL,
        typ         TEXT    NOT NULL DEFAULT '',
        baustelleId INTEGER,
        bemerkung   TEXT    DEFAULT '',
        betriebTyp  TEXT    DEFAULT '',
        position    INTEGER DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_wp_user_datum ON wochenplanung(username, datum);
    CREATE INDEX IF NOT EXISTS idx_wp_baustelle ON wochenplanung(baustelleId);

    -- ── Schnellnotizen ───────────────────────────────────────
    CREATE TABLE IF NOT EXISTS schnellnotizen (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        text          TEXT    NOT NULL DEFAULT '',
        baustelleId   INTEGER,
        baustelleName TEXT    DEFAULT '',
        ersteller     TEXT    DEFAULT '',
        kuerzel       TEXT    DEFAULT '',
        datum         TEXT    DEFAULT '',
        archiviert    INTEGER DEFAULT 0,
        archiviertAm  TEXT    DEFAULT '',
        archiviertVon TEXT    DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_sn_baustelle ON schnellnotizen(baustelleId);

    -- ── Rechnungen & Angebote ────────────────────────────────
    CREATE TABLE IF NOT EXISTS rechnungen (
        id          TEXT PRIMARY KEY,
        typ         TEXT NOT NULL DEFAULT 'rechnung',
        nummer      TEXT UNIQUE NOT NULL,
        kundeId     INTEGER,
        baustelleId INTEGER,
        datum       TEXT DEFAULT '',
        faelligAm   TEXT DEFAULT '',
        status      TEXT DEFAULT 'offen',
        absender    TEXT DEFAULT '{}',
        positionen  TEXT DEFAULT '[]',
        notizen     TEXT DEFAULT '',
        beschreibung TEXT DEFAULT '',
        zahlungsziel TEXT DEFAULT '',
        createdAt   TEXT DEFAULT '',
        createdBy   TEXT DEFAULT '',
        updatedAt   TEXT DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_rech_kunde ON rechnungen(kundeId);
    CREATE INDEX IF NOT EXISTS idx_rech_bau   ON rechnungen(baustelleId);

    -- ── Einstellungen (Single-Row JSON) ──────────────────────
    CREATE TABLE IF NOT EXISTS settings (
        id   INTEGER PRIMARY KEY CHECK(id = 1),
        data TEXT    DEFAULT '{}'
    );
    INSERT OR IGNORE INTO settings (id, data) VALUES (1, '{}');

    -- ── Berechtigungen (Single-Row JSON) ─────────────────────
    CREATE TABLE IF NOT EXISTS permissions_config (
        id   INTEGER PRIMARY KEY CHECK(id = 1),
        data TEXT    DEFAULT '{}'
    );
    INSERT OR IGNORE INTO permissions_config (id, data) VALUES (1, '{}');

    -- ── Metallzuschlag (Single-Row JSON) ─────────────────────
    CREATE TABLE IF NOT EXISTS metallzuschlag (
        id   INTEGER PRIMARY KEY CHECK(id = 1),
        data TEXT    DEFAULT '{}'
    );
    INSERT OR IGNORE INTO metallzuschlag (id, data) VALUES (1, '{}');

    -- ── Metall-Profil/Halbzeug Katalog (Single-Row JSON) ───
    CREATE TABLE IF NOT EXISTS metall_profile_catalog (
        id   INTEGER PRIMARY KEY CHECK(id = 1),
        data TEXT    DEFAULT '{}'
    );
    INSERT OR IGNORE INTO metall_profile_catalog (id, data) VALUES (1, '{}');

    -- ── Kalender-Tokens ──────────────────────────────────────
    CREATE TABLE IF NOT EXISTS cal_tokens (
        token    TEXT PRIMARY KEY,
        username TEXT NOT NULL,
        role     TEXT DEFAULT 'normal',
        created  TEXT DEFAULT ''
    );

    -- ── Erinnerung-Einstellungen (Single-Row JSON) ──────────
    CREATE TABLE IF NOT EXISTS erinnerung_settings (
        id   INTEGER PRIMARY KEY CHECK(id = 1),
        data TEXT    DEFAULT '{}'
    );
    INSERT OR IGNORE INTO erinnerung_settings (id, data) VALUES (1, '{}');

    -- ── Termine ──────────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS termine (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        titel        TEXT    NOT NULL DEFAULT '',
        beschreibung TEXT    DEFAULT '',
        datum        TEXT    NOT NULL,
        zeitVon      TEXT    DEFAULT '',
        zeitBis      TEXT    DEFAULT '',
        ganztags     INTEGER DEFAULT 0,
        ort          TEXT    DEFAULT '',
        baustelleId  INTEGER,
        zugewiesen   TEXT    DEFAULT '[]',
        ersteller    TEXT    DEFAULT '',
        erstelltAm   TEXT    DEFAULT '',
        farbe        TEXT    DEFAULT '#00B4D8',
        wiederholung TEXT    DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_termine_datum ON termine(datum);

    -- ── Dashboard-Einträge (v2.4/v2.5) ───────────────────────
    CREATE TABLE IF NOT EXISTS dashboard_items (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        typ           TEXT    NOT NULL DEFAULT 'aufgabe',
        titel         TEXT    NOT NULL DEFAULT '',
        beschreibung  TEXT    DEFAULT '',
        faelligAm     TEXT    DEFAULT '',
        status        TEXT    NOT NULL DEFAULT 'offen',
        prioritaet    TEXT    NOT NULL DEFAULT 'normal',
        linkTyp       TEXT    DEFAULT '',
        linkId        INTEGER DEFAULT NULL,
        ersteller     TEXT    DEFAULT '',
        erstelltAm    TEXT    DEFAULT '',
        farbe         TEXT    DEFAULT '#00B4D8',
        sortPos       INTEGER DEFAULT 0,
        zugewiesen_an TEXT    DEFAULT ''
    );

    SQL;
}
