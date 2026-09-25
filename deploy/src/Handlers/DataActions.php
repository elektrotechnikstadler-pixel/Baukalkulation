<?php
namespace App\Handlers;

use App\Auth;
use App\DataService;
use App\Services\AuditService;
use App\Services\PriceFilter;

class DataActions
{
    public function __construct(private \PDO $db, private array $body) {}

    /** load – Komplette Projektdaten laden */
    public function load(): void
    {
        Auth::requireAuth();
        $data = DataService::loadAllData($this->db);

        // Sichtbarkeitsfilter (mit Cascade Ober-/Unterprojekt)
        $vis = Auth::getEffectiveVisibility($this->db);
        if ($vis !== 'all' && is_array($vis)) {
            $allowed = array_flip($vis);
            $data['baustellen'] = array_values(array_filter(
                $data['baustellen'] ?? [],
                fn($b) => isset($allowed[(int)($b['id'] ?? 0)])
            ));
        }

        // Preisfelder strippen, wenn User keine Preise sehen darf
        $data = PriceFilter::apply($this->db, $data);

        jsonOut(['ok' => true, 'data' => $data]);
    }

    /** save – Komplette Projektdaten speichern + Backup */
    public function save(): void
    {
        Auth::requireAuth();
        $data = $this->body['data'] ?? null;
        if ($data === null) jsonOut(['error' => 'Keine Daten übermittelt.'], 400);
        if (!is_array($data)) jsonOut(['error' => 'Ungültiges Datenformat.'], 400);

        // =====================================================================
        // SCHUTZBLOCK: Verhindert Datenverlust bei eingeschränkten Benutzern.
        // Hintergrund: load() filtert/strippt Daten nach Sichtbarkeit + Preisen.
        // Ohne diesen Block würde save() die gefilterten (= fehlenden) Daten
        // dauerhaft löschen oder auf 0 setzen.
        // =====================================================================

        // ── Schutz 1: Kataloge – Preis- und Strukturschutz ───────────────────
        // Zwei unabhängige Szenarien schützen verschiedene Felder:
        //
        // a) !canManageStundenKatalog → kompletter DB-Replace (User darf Katalog
        //    gar nicht ändern – Einträge + Preise kommen 1:1 aus DB)
        //
        // b) canManageStundenKatalog=true ABER !canSeePrices → User darf Einträge
        //    anlegen/umbenennen, aber Preisfelder werden durch PriceFilter aus der
        //    API-Antwort entfernt. Beim Speichern fehlen preis/fixkosten → DataService
        //    schreibt 0 (?? 0). Fix: Preisfelder per Eintrag-ID aus DB restaurieren,
        //    strukturelle Änderungen (neue/gelöschte Einträge) bleiben erhalten.
        if (!Auth::canDo($this->db, 'canManageStundenKatalog')) {
            // (a) Kein Verwaltungsrecht → vollständiger DB-Replace
            $rows = $this->db->query(
                "SELECT id, kategorie, preis, fixkosten FROM stunden_katalog ORDER BY id"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $data['stundenKatalog'] = array_map(fn($r) => [
                'id'        => (int)$r['id'],
                'kategorie' => $r['kategorie'],
                'preis'     => money_from_cents((int)$r['preis']),
                'fixkosten' => money_from_cents((int)$r['fixkosten']),
            ], $rows);
        } elseif (!Auth::canDo($this->db, 'canSeePrices')) {
            // (b) Verwaltungsrecht vorhanden, aber kein Preisblick → nur Preisfelder
            //     für bestehende Einträge aus DB wiederherstellen
            $rows = $this->db->query(
                "SELECT id, preis, fixkosten FROM stunden_katalog"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $dbPrices = [];
            foreach ($rows as $r) $dbPrices[(int)$r['id']] = $r;
            foreach ($data['stundenKatalog'] ?? [] as $i => $entry) {
                $id = (int)($entry['id'] ?? 0);
                if (isset($dbPrices[$id])) {
                    $data['stundenKatalog'][$i]['preis']     = money_from_cents((int)$dbPrices[$id]['preis']);
                    $data['stundenKatalog'][$i]['fixkosten'] = money_from_cents((int)$dbPrices[$id]['fixkosten']);
                }
                // Neuer Eintrag (ID noch nicht in DB): preis=0 bleibt, Admin setzt Preis später
            }
        }

        // Schutz 1b: Material-Katalog + Pauschalen (analog)
        if (!Auth::canDo($this->db, 'canManageKatalog')) {
            // (a) Kein Verwaltungsrecht → vollständiger DB-Replace
            $rows = $this->db->query(
                "SELECT id, bezeichnung, einheit, ek, aufschlag, artikelNr FROM material_katalog ORDER BY id"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $data['materialKatalog'] = array_map(fn($r) => [
                'id'          => (int)$r['id'],
                'bezeichnung' => $r['bezeichnung'],
                'einheit'     => $r['einheit'],
                'ek'          => money_from_cents((int)$r['ek']),
                'aufschlag'   => money_from_cents((int)$r['aufschlag']),
                'artikelNr'   => $r['artikelNr'],
            ], $rows);

            $rows = $this->db->query(
                "SELECT id, name, preis FROM pauschalen ORDER BY id"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $data['pauschalen'] = array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'name'  => $r['name'],
                'preis' => money_from_cents((int)$r['preis']),
            ], $rows);
        } elseif (!Auth::canDo($this->db, 'canSeePrices')) {
            // (b) Verwaltungsrecht vorhanden, aber kein Preisblick → nur Preisfelder restaurieren
            $rows = $this->db->query(
                "SELECT id, ek, aufschlag FROM material_katalog"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $dbMatPrices = [];
            foreach ($rows as $r) $dbMatPrices[(int)$r['id']] = $r;
            foreach ($data['materialKatalog'] ?? [] as $i => $entry) {
                $id = (int)($entry['id'] ?? 0);
                if (isset($dbMatPrices[$id])) {
                    $data['materialKatalog'][$i]['ek']        = money_from_cents((int)$dbMatPrices[$id]['ek']);
                    $data['materialKatalog'][$i]['aufschlag'] = money_from_cents((int)$dbMatPrices[$id]['aufschlag']);
                }
            }

            $rows = $this->db->query(
                "SELECT id, preis FROM pauschalen"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $dbPauPrices = [];
            foreach ($rows as $r) $dbPauPrices[(int)$r['id']] = $r;
            foreach ($data['pauschalen'] ?? [] as $i => $entry) {
                $id = (int)($entry['id'] ?? 0);
                if (isset($dbPauPrices[$id])) {
                    $data['pauschalen'][$i]['preis'] = money_from_cents((int)$dbPauPrices[$id]['preis']);
                }
            }
        }

        // ── Schutz 2: Preisfelder in Baustellen-Blobs ─────────────────────────
        // Benutzer ohne canSeePrices bekommen Preisfelder per PriceFilter nicht
        // zurück. In arbeitszeit / material / abschlaege werden sie aus dem
        // DB-Blob wiederhergestellt, damit sie nicht dauerhaft verloren gehen.
        if (!Auth::canDo($this->db, 'canSeePrices')) {
            $priceKeys = [
                'ek','vk','ep','gp','aufschlag','preis','einzelpreis','gesamtpreis',
                'matEk','matVk','materialAufschlagGlobal',
                'stundenpreis','stundensatz','stundensatzFk','stundensatzEin',
                'fixkosten','azEin','azFk',
                'betrag','pSum','gesamt','gewinn','umsatz','kosten',
                'netto','brutto','mwst','rabatt','skonto','abSum','offen','summe','zwischensumme',
            ];
            $bsStmt = $this->db->prepare("SELECT data FROM baustellen WHERE id = ?");
            foreach ($data['baustellen'] ?? [] as $bi => $bs) {
                $bsStmt->execute([(int)($bs['id'] ?? 0)]);
                $row = $bsStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) continue;
                $dbBlob = json_decode($row['data'], true) ?: [];

                foreach (['arbeitszeit', 'material', 'abschlaege'] as $section) {
                    if (empty($dbBlob[$section])) continue;
                    $dbMap = [];
                    foreach ($dbBlob[$section] as $dbEntry) {
                        if (isset($dbEntry['id'])) $dbMap[(int)$dbEntry['id']] = $dbEntry;
                    }
                    foreach ($data['baustellen'][$bi][$section] ?? [] as $ei => $entry) {
                        $eid = (int)($entry['id'] ?? -1);
                        if (!isset($dbMap[$eid])) continue;
                        $dbEntry = $dbMap[$eid];
                        foreach ($priceKeys as $pk) {
                            if (!array_key_exists($pk, $entry) && array_key_exists($pk, $dbEntry)) {
                                $data['baustellen'][$bi][$section][$ei][$pk] = $dbEntry[$pk];
                            }
                        }
                    }
                }

                foreach ($priceKeys as $pk) {
                    if (!array_key_exists($pk, $bs) && array_key_exists($pk, $dbBlob)) {
                        $data['baustellen'][$bi][$pk] = $dbBlob[$pk];
                    }
                }
            }
        }

        // ── Schutz 3: Baustellen vor unberechtigter Löschung ─────────────────
        // saveAllData() löscht Baustellen, die NICHT im Incoming sind.
        // Schutz a) Sichtbarkeit: nicht-sichtbare Baustellen fehlen im Payload
        //           → sie müssen aus der DB ergänzt werden.
        // Schutz b) canDeleteBaustelle=false: Benutzer darf keine Baustelle
        //           löschen, auch wenn sie in seiner Sichtbarkeit liegt.
        $vis = Auth::getEffectiveVisibility($this->db);
        $canDelete = Auth::canDo($this->db, 'canDeleteBaustelle');
        if ($vis !== 'all' || !$canDelete) {
            $allowed      = ($vis !== 'all' && is_array($vis)) ? array_flip($vis) : null;
            $allBsRows    = $this->db->query(
                "SELECT id, name, kundeId, data FROM baustellen WHERE archiviert = 0"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $incomingIds  = array_map('intval', array_column($data['baustellen'] ?? [], 'id'));
            foreach ($allBsRows as $row) {
                $id = (int)$row['id'];
                if (in_array($id, $incomingIds, true)) continue; // schon im Payload
                $isInvisible = ($allowed !== null && !isset($allowed[$id]));
                if ($isInvisible || !$canDelete) {
                    $blob = json_decode($row['data'], true) ?: [];
                    $blob['id']      = $id;
                    $blob['name']    = $row['name'];
                    $blob['kundeId'] = $row['kundeId'] !== null ? (int)$row['kundeId'] : null;
                    $data['baustellen'][] = $blob;
                }
            }
        }
        // =====================================================================

        // ── SICHERHEITS-GUARD gegen versehentliche Massen-Löschung ────────
        // Grundsatz: Die Datenbank darf nur durch eine AKTIVE Handlung geleert
        // werden. Ein durch Glitch/Race/Bug leerer oder drastisch geschrumpfter
        // Payload darf NICHT die Baustellen-Tabelle (teil-)löschen.
        // Der Guard läuft NACH den Schutzblöcken – eingeschränkte Benutzer haben
        // ihre unsichtbaren Baustellen dann bereits zurück im Payload und lösen
        // ihn nicht aus. Eine bewusste Großlöschung ist mit confirmBulkDelete=true
        // weiterhin möglich (= aktive Bestätigung).
        if (array_key_exists('baustellen', $data) && is_array($data['baustellen'])) {
            $dbCount       = (int)$this->db->query("SELECT COUNT(*) FROM baustellen WHERE archiviert = 0")->fetchColumn();
            $incomingCount = count($data['baustellen']);
            $confirmWipe   = !empty($this->body['confirmBulkDelete']);
            if ($dbCount > 0 && !$confirmWipe) {
                if ($incomingCount === 0) {
                    jsonOut([
                        'error'       => "Speichern abgebrochen: Der Datensatz enthält keine Baustellen, würde aber $dbCount vorhandene löschen. Das ist vermutlich ein Fehler – es wurde NICHTS verändert.",
                        'needsConfirm'=> true,
                    ], 409);
                }
                $deletes = $dbCount - $incomingCount;
                if ($deletes >= 5 && $incomingCount < $dbCount / 2) {
                    jsonOut([
                        'error'       => "Speichern abgebrochen: $deletes von $dbCount Baustellen würden gelöscht. Falls beabsichtigt, bitte die Löschung bestätigen – es wurde NICHTS verändert.",
                        'needsConfirm'=> true,
                    ], 409);
                }
            }
        }

        // ── Optimistic Locking: Lost-Update-Schutz bei gleichzeitiger Bearbeitung ──
        // Der Client sendet die Revision, auf der seine Änderungen basieren (baseRev).
        // Hat zwischenzeitlich ein anderer Nutzer gespeichert, wird NICHT überschrieben,
        // sondern ein Konflikt gemeldet (außer der Nutzer bestätigt via forceOverwrite).
        $baseRev = array_key_exists('baseRev', $this->body) && $this->body['baseRev'] !== null
            ? (int)$this->body['baseRev'] : null;
        $force   = !empty($this->body['forceOverwrite']);
        $user    = (string)($_SESSION['username'] ?? '');

        $result = DataService::saveAllData($this->db, $data, $baseRev, $force, $user);

        if (!empty($result['conflict'])) {
            jsonOut([
                'error'      => 'Ein anderer Benutzer hat zwischenzeitlich gespeichert. '
                              . 'Deine Änderungen wurden NICHT gespeichert.',
                'conflict'   => true,
                'currentRev' => $result['currentRev'] ?? 0,
                'updatedBy'  => $result['updatedBy'] ?? '',
                'updatedAt'  => $result['updatedAt'] ?? '',
            ], 409);
        }

        // Tägliches Backup
        DataService::createDailyBackup($this->db, $data);

        // Audit: entfernte Abschlagsrechnungen protokollieren (Verlust-Nachverfolgung).
        // Nicht-blockierend – legitime Löschungen bleiben möglich, aber jeder Verlust ist
        // im Audit-Log samt der entfernten Daten wiederherstellbar dokumentiert.
        foreach ($result['abschlagRemovals'] ?? [] as $rm) {
            $details = 'Baustelle #' . ($rm['baustelleId'] ?? '?') . ' „' . ($rm['name'] ?? '') . '": '
                     . count($rm['removed'] ?? []) . ' Abschlag(e) entfernt: '
                     . json_encode($rm['removed'] ?? [], JSON_UNESCAPED_UNICODE);
            AuditService::log('abschlag_removed', $details);
        }

        AuditService::log('data_save', 'Projektdaten gespeichert, Baustellen=' . count($data['baustellen'] ?? []));
        jsonOut(['ok' => true, 'ts' => date('c'), 'rev' => $result['rev'] ?? null]);
    }

    /** backups – Liste aller Backups (nur Admin/Master, da Sicherungen
     *  vollständige SQLite-Dumps inkl. aller Preise sind). */
    public function listBackups(): void
    {
        Auth::requireRole('admin', 'master');
        $dirs = glob(BACKUP_DIR . '????-??-??*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $result = [];
        foreach (array_slice($dirs, 0, BACKUP_MAX) as $d) {
            $mainFile = $d . '/baukalkulation.json';
            if (!file_exists($mainFile)) continue;
            $snap = json_decode(file_get_contents($mainFile), true);
            if (!$snap) continue;
            $backedUpFiles = array_map('basename', glob($d . '/*.json') ?: []);
            $result[] = [
                'date'  => basename($d),
                'ts'    => $snap['ts'] ?? '',
                'count' => count($snap['data']['baustellen'] ?? []),
                'files' => $backedUpFiles,
            ];
        }
        jsonOut(['ok' => true, 'backups' => $result]);
    }

    /** restore – Backup wiederherstellen (nur Admin/Master). */
    public function restore(): void
    {
        Auth::requireRole('admin', 'master');
        $date  = preg_replace('/[^0-9\-_a-zA-Z]/', '', $_GET['date'] ?? '');
        $bDir  = BACKUP_DIR . $date . '/';
        $bFile = $bDir . 'baukalkulation.json';
        if (!$date || !file_exists($bFile)) {
            $bFile = BACKUP_DIR . $date . '.json';
        }
        if (!file_exists($bFile)) jsonOut(['error' => 'Sicherung nicht gefunden.'], 404);

        $snap = json_decode(file_get_contents($bFile), true);
        if (!$snap || !isset($snap['data'])) jsonOut(['error' => 'Sicherung ist beschädigt.'], 500);

        // M8 (v1.8.0, gelockert in v1.9.1): Schema-Validierung
        // Verhindert, dass präparierte JSON-Dateien die DB überschreiben,
        // bricht aber NICHT ab, wenn ältere Sicherungen einzelne Top-Level-
        // Keys nicht enthalten (z. B. 'rechnungen' wurde später eingeführt).
        // Auto-Defaults sorgen für Abwärtskompatibilität bis v1.0.
        $data = $snap['data'];
        if (!is_array($data)) {
            jsonOut(['error' => 'Sicherung beschädigt: data ist kein Objekt.'], 400);
        }
        // Pflicht: 'baustellen' muss vorhanden sein – sonst ist es kein Backup.
        if (!isset($data['baustellen']) || !is_array($data['baustellen'])) {
            jsonOut(['error' => "Sicherung beschädigt: 'baustellen' fehlt oder ist kein Array."], 400);
        }
        // Optionale Top-Level-Keys: fehlende werden mit [] aufgefüllt
        // (alte Backups vor v1.5.0 hatten teils kein 'kunden'/'rechnungen').
        foreach (['kunden', 'rechnungen', 'pauschalen', 'stundenKatalog', 'materialKatalog'] as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = [];
            } elseif (!is_array($data[$key])) {
                jsonOut(['error' => "Sicherung beschädigt: '$key' muss Array sein."], 400);
            }
        }
        // Plausibilität: Baustellen brauchen mindestens eine ID.
        // 'name' kann in sehr alten Sicherungen fehlen – wir füllen mit
        // Platzhalter auf, damit der Restore nicht scheitert.
        foreach ($data['baustellen'] as $i => $b) {
            if (!is_array($b) || !isset($b['id'])) {
                jsonOut(['error' => "Sicherung beschädigt: Baustelle #$i ohne id."], 400);
            }
            if (!isset($b['name']) || $b['name'] === '') {
                $data['baustellen'][$i]['name'] = 'Baustelle #' . (int)$b['id'];
            }
        }
        $snap['data'] = $data;

        // Sicherheits-Snapshot des AKTUELLEN Standes – der Restore bleibt damit
        // umkehrbar. Scheitert die Sicherung, wird NICHTS überschrieben.
        try {
            $safety = $this->_createSafetySnapshot('restore');
        } catch (\Throwable $e) {
            error_log('Pre-Restore-Snapshot fehlgeschlagen: ' . $e->getMessage());
            jsonOut(['error' => 'Wiederherstellung abgebrochen: Der aktuelle Stand konnte nicht gesichert werden. Es wurden KEINE Daten verändert.'], 500);
        }

        // Hauptdaten wiederherstellen
        DataService::saveAllData($this->db, $snap['data']);

        // SQLite-Tabellen wiederherstellen (alle Module: VDE, DIN1090, Aufmaß, Lager …)
        // _restoreFromSqlite() prüft intern ob die Datei existiert und überspringt
        // fehlende Tabellen (Abwärtskompatibilität alter Backups).
        $this->_restoreFromSqlite($bDir . 'database.sqlite');

        AuditService::log('data_restore', 'Backup wiederhergestellt: Datum=' . $date . ', Baustellen=' . count($snap['data']['baustellen'] ?? []) . ', Sicherheitskopie=' . $safety);
        jsonOut(['ok' => true, 'data' => $snap['data'], 'safetyBackup' => $safety]);
    }

    // ── Manuelle Sicherung / Download / Upload ────────────────────────────

    // ── Projekt-Presence: zeigt an, wer gerade ein Projekt bearbeitet ──────
    private const PRESENCE_FILE = DATA_DIR . 'project_presence.json';
    private const PRESENCE_TTL  = 120; // Sekunden bis ein Heartbeat als veraltet gilt

    /** project_heartbeat – Client meldet alle 60s welches Projekt offen ist. */
    public function projectHeartbeat(): void
    {
        Auth::requireAuth();
        $baustelleId = isset($this->body['baustelleId']) ? (int)$this->body['baustelleId'] : 0;
        $username = (string)($_SESSION['username'] ?? '');
        if (!$username) { \jsonOut(['ok' => true]); return; }

        $data = self::_loadPresence();
        if ($baustelleId > 0) {
            $data[$username] = ['baustelleId' => $baustelleId, 'ts' => time()];
        } else {
            unset($data[$username]);
        }
        self::_savePresence($data);
        \jsonOut(['ok' => true]);
    }

    /** project_presence – Gibt zurück, wer gerade welches Projekt offen hat. */
    public function projectPresence(): void
    {
        Auth::requireAuth();
        $data = self::_loadPresence();
        $now  = time();
        $result = [];
        foreach ($data as $user => $info) {
            if ($now - ($info['ts'] ?? 0) > self::PRESENCE_TTL) continue;
            $result[] = ['username' => $user, 'baustelleId' => (int)($info['baustelleId'] ?? 0)];
        }
        \jsonOut(['ok' => true, 'presence' => $result]);
    }

    private static function _loadPresence(): array
    {
        if (!file_exists(self::PRESENCE_FILE)) return [];
        $d = json_decode((string)file_get_contents(self::PRESENCE_FILE), true);
        return is_array($d) ? $d : [];
    }

    private static function _savePresence(array $data): void
    {
        // Veraltete Einträge entfernen
        $now = time();
        foreach ($data as $u => $info) {
            if ($now - ($info['ts'] ?? 0) > self::PRESENCE_TTL * 3) unset($data[$u]);
        }
        @file_put_contents(self::PRESENCE_FILE, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** Erstellt einen benannten Wiederherstellungspunkt in BACKUP_DIR. */
    public function backupCreate(): void
    {
        Auth::requireRole('admin', 'master');
        $name = trim($this->body['name'] ?? '');
        $slug = $name !== ''
            ? '_' . substr(preg_replace('/[^a-zA-Z0-9\-]/', '', strtr($name, [
                ' ' => '-', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
              ])), 0, 40)
            : '';
        $dirName = date('Y-m-d_H-i') . $slug;
        $destDir = BACKUP_DIR . $dirName . '/';

        // Verifiziertes Schreiben: JSON + SQLite werden geprüft. Schlägt ein
        // Schritt fehl, wird die UNVOLLSTÄNDIGE Sicherung entfernt und ein klarer
        // Fehler gemeldet (kein „false success“, kein nichtssagender HTTP 500).
        try {
            $this->_writeVerifiedSnapshot($destDir);
        } catch (\Throwable $e) {
            $this->_removeBackupDir($destDir);
            error_log('backupCreate fehlgeschlagen: ' . $e->getMessage());
            jsonOut(['error' => 'Sicherung fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        // Nur alte MANUELLE Sicherungen bereinigen – Tages- und Pre-Restore-
        // Sicherungen bleiben unangetastet.
        DataService::pruneBackupCategory('manual', BACKUP_MAX * 2);

        AuditService::log('backup_create', 'Manuelle Sicherung erstellt: ' . $dirName);
        jsonOut(['ok' => true, 'date' => $dirName]);
    }

    /**
     * Schreibt einen vollständigen, VERIFIZIERTEN Snapshot (JSON + SQLite) nach
     * $destDir. Wirft bei jedem Problem eine Exception, damit niemals eine
     * unvollständige oder korrupte Sicherung als gültig zurückbleibt.
     */
    private function _writeVerifiedSnapshot(string $destDir): void
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new \RuntimeException('Sicherungsverzeichnis konnte nicht erstellt werden (Schreibrechte?).');
        }

        // 1) JSON-Snapshot der Hauptdaten – atomar via .tmp + rename schreiben.
        $data = DataService::loadAllData($this->db);
        $snap = ['ts' => date('c'), 'v' => APP_VERSION, 'data' => $data];
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Daten konnten nicht als JSON kodiert werden: ' . json_last_error_msg());
        }
        $jsonPath = $destDir . 'baukalkulation.json';
        $tmpPath  = $jsonPath . '.tmp';
        if (file_put_contents($tmpPath, $json) === false || !@rename($tmpPath, $jsonPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Sicherungsdatei konnte nicht geschrieben werden (Schreibrechte?).');
        }
        if (@filesize($jsonPath) === 0) {
            throw new \RuntimeException('Sicherungsdatei ist leer.');
        }

        // 2) SQLite-Datei sichern. WAL-Checkpoint erzwingt, dass alle committed
        //    Transaktionen in der Hauptdatei stehen (sonst fehlen Modul-Daten).
        $sqliteSrc = DATA_DIR . 'database.sqlite';
        if (file_exists($sqliteSrc)) {
            try { $this->db->exec('PRAGMA wal_checkpoint(FULL)'); }
            catch (\Throwable $e) { error_log('WAL-Checkpoint fehlgeschlagen: ' . $e->getMessage()); }

            $sqliteDest = $destDir . 'database.sqlite';
            if (!@copy($sqliteSrc, $sqliteDest) || !file_exists($sqliteDest) || @filesize($sqliteDest) === 0) {
                throw new \RuntimeException('Datenbank-Datei konnte nicht gesichert werden.');
            }

            // Integritätsprüfung der Kopie. Eine korrupte Sicherung ist gefährlicher
            // als gar keine, weil sie beim Restore gültige Daten überschreiben würde.
            try {
                $check = new \PDO('sqlite:' . $sqliteDest);
                $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $res = $check->query('PRAGMA integrity_check')->fetchColumn();
                $check = null;
                if (strtolower((string)$res) !== 'ok') {
                    throw new \RuntimeException('Integritätsprüfung der Datenbank-Sicherung fehlgeschlagen.');
                }
            } catch (\PDOException $e) {
                throw new \RuntimeException('Datenbank-Sicherung ist nicht lesbar: ' . $e->getMessage());
            }
        }
    }

    /** Entfernt ein (ggf. unvollständiges) Sicherungsverzeichnis vollständig. */
    private function _removeBackupDir(string $destDir): void
    {
        if (!is_dir($destDir)) return;
        foreach (glob($destDir . '*') ?: [] as $f) @unlink($f);
        @rmdir($destDir);
    }

    /**
     * Sichert den AKTUELLEN Datenbestand, BEVOR ein Restore/Upload ihn
     * überschreibt. Dadurch bleibt jede Wiederherstellung umkehrbar – Schutz
     * vor unbeabsichtigtem Datenverlust. Wirft, wenn die Sicherung scheitert,
     * damit der aufrufende Restore abgebrochen werden kann.
     */
    private function _createSafetySnapshot(string $reason): string
    {
        $dirName = date('Y-m-d_H-i-s') . '_pre-' . $reason;
        $destDir = BACKUP_DIR . $dirName . '/';
        try {
            $this->_writeVerifiedSnapshot($destDir);
        } catch (\Throwable $e) {
            $this->_removeBackupDir($destDir);
            throw $e;
        }
        // Eigene Aufbewahrung für Sicherheits-Snapshots (neueste 20 behalten).
        DataService::pruneBackupCategory('pre-restore', 20);
        return $dirName;
    }

    /** Erzeugt ein ZIP der aktuellen (oder einer gespeicherten) Sicherung und streamt es. */
    public function backupDownload(): void
    {
        Auth::requireRole('admin', 'master');
        $date = preg_replace('/[^0-9\-_a-zA-Z]/', '', $_GET['date'] ?? '');

        $cleanupTmp = false;
        if ($date && is_dir(BACKUP_DIR . $date)) {
            $bDir       = BACKUP_DIR . $date . '/';
            $jsonFile   = $bDir . 'baukalkulation.json';
            $sqliteFile = file_exists($bDir . 'database.sqlite') ? $bDir . 'database.sqlite' : null;
        } else {
            // Live-Daten on-the-fly sichern
            $tmpDir = sys_get_temp_dir() . '/bk_dl_' . uniqid('', true) . '/';
            mkdir($tmpDir, 0750, true);
            $data     = DataService::loadAllData($this->db);
            $snap     = ['ts' => date('c'), 'v' => APP_VERSION, 'data' => $data];
            $jsonFile = $tmpDir . 'baukalkulation.json';
            file_put_contents($jsonFile, json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $sqliteSrc  = DATA_DIR . 'database.sqlite';
            $sqliteFile = file_exists($sqliteSrc) ? $sqliteSrc : null;
            $cleanupTmp = true;
        }

        if (!file_exists($jsonFile)) {
            jsonOut(['error' => 'Keine Sicherungsdaten gefunden.'], 404);
        }

        $zipName = 'baukalkulation_backup_' . date('Y-m-d_H-i') . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            jsonOut(['error' => 'ZIP konnte nicht erstellt werden.'], 500);
        }
        $zip->addFile($jsonFile, 'baukalkulation.json');
        if ($sqliteFile) {
            $zip->addFile($sqliteFile, 'database.sqlite');
        }
        $zip->close();

        if ($cleanupTmp) {
            @unlink($jsonFile);
            @rmdir($tmpDir);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: private, no-cache');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /** Nimmt ein hochgeladenes ZIP-Archiv entgegen, validiert es und stellt es wieder her. */
    public function backupUpload(): void
    {
        Auth::requireRole('admin', 'master');
        $uploaded = $_FILES['backup'] ?? null;
        if (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Kein gültiges ZIP hochgeladen (Upload-Fehler).'], 400);
        }

        $tmpDir = sys_get_temp_dir() . '/bk_up_' . uniqid('', true) . '/';
        mkdir($tmpDir, 0750, true);
        $snap = null;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($uploaded['tmp_name']) !== true) {
                jsonOut(['error' => 'ZIP konnte nicht geöffnet werden.'], 400);
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            $jsonFile = $tmpDir . 'baukalkulation.json';
            if (!file_exists($jsonFile)) {
                jsonOut(['error' => 'baukalkulation.json fehlt im ZIP-Archiv.'], 400);
            }

            $snap = json_decode(file_get_contents($jsonFile), true);
            if (!$snap || !isset($snap['data']) || !is_array($snap['data'])) {
                jsonOut(['error' => 'Sicherung beschädigt (kein data-Block).'], 400);
            }

            $data = $snap['data'];
            if (!isset($data['baustellen']) || !is_array($data['baustellen'])) {
                jsonOut(['error' => "Sicherung beschädigt: 'baustellen' fehlt."], 400);
            }
            foreach (['kunden', 'rechnungen', 'pauschalen', 'stundenKatalog', 'materialKatalog'] as $k) {
                if (!array_key_exists($k, $data)) $data[$k] = [];
            }
            $snap['data'] = $data;

            // Sicherheits-Snapshot des aktuellen Standes vor dem Überschreiben.
            // Scheitert die Sicherung, wird NICHTS überschrieben.
            try {
                $safety = $this->_createSafetySnapshot('upload');
            } catch (\Throwable $e) {
                error_log('Pre-Upload-Snapshot fehlgeschlagen: ' . $e->getMessage());
                jsonOut(['error' => 'Wiederherstellung abgebrochen: Der aktuelle Stand konnte nicht gesichert werden. Es wurden KEINE Daten verändert.'], 500);
            }

            // Hauptdaten wiederherstellen
            DataService::saveAllData($this->db, $snap['data']);

            // SQLite-Tabellen wiederherstellen (falls im ZIP enthalten)
            $this->_restoreFromSqlite($tmpDir . 'database.sqlite');

        } finally {
            // Temp-Verzeichnis immer bereinigen
            foreach (glob($tmpDir . '*') ?: [] as $f) @unlink($f);
            @rmdir($tmpDir);
        }

        AuditService::log('backup_restore_upload', 'Backup-ZIP wiederhergestellt, Baustellen=' . count($snap['data']['baustellen'] ?? []) . ', Sicherheitskopie=' . $safety);
        jsonOut(['ok' => true, 'data' => $snap['data'], 'safetyBackup' => $safety]);
    }

    /**
     * backup_upload_replace – Ziel-Instanz 1:1 durch die aktiven Daten eines
     * hochgeladenen Backups ersetzen (kein Merge).
     *
     * Unterschied zu backupUpload():
     *  - Aktive Baustellen des Ziels, die NICHT im Backup enthalten sind, werden
     *    ins Archiv verschoben (Export-JSON + Soft-Delete), NICHT gelöscht.
     *  - saveAllData läuft mit force, damit auch bei bestehender Revision ersetzt wird.
     *  - Zeiterfassungs-Historie (log/meta) wird zusätzlich 1:1 gespiegelt.
     *  - Bereits archivierte Baustellen/Dateien des Ziels bleiben unangetastet.
     */
    public function backupUploadReplace(): void
    {
        Auth::requireRole('admin', 'master');
        $uploaded = $_FILES['backup'] ?? null;
        if (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Kein gültiges ZIP hochgeladen (Upload-Fehler).'], 400);
        }

        $tmpDir = sys_get_temp_dir() . '/bk_rp_' . uniqid('', true) . '/';
        mkdir($tmpDir, 0750, true);
        $snap = null; $archivedCount = 0; $safety = '';
        try {
            $zip = new \ZipArchive();
            if ($zip->open($uploaded['tmp_name']) !== true) {
                jsonOut(['error' => 'ZIP konnte nicht geöffnet werden.'], 400);
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            $jsonFile = $tmpDir . 'baukalkulation.json';
            if (!file_exists($jsonFile)) {
                jsonOut(['error' => 'baukalkulation.json fehlt im ZIP-Archiv.'], 400);
            }

            $snap = json_decode(file_get_contents($jsonFile), true);
            if (!$snap || !isset($snap['data']) || !is_array($snap['data'])) {
                jsonOut(['error' => 'Sicherung beschädigt (kein data-Block).'], 400);
            }

            $data = $snap['data'];
            if (!isset($data['baustellen']) || !is_array($data['baustellen'])) {
                jsonOut(['error' => "Sicherung beschädigt: 'baustellen' fehlt."], 400);
            }
            foreach (['kunden', 'rechnungen', 'pauschalen', 'stundenKatalog', 'materialKatalog'] as $k) {
                if (!array_key_exists($k, $data)) $data[$k] = [];
            }
            $snap['data'] = $data;

            // Backup-IDs (aktive Baustellen des Backups).
            $backupIds = [];
            foreach ($data['baustellen'] as $b) {
                if (isset($b['id'])) $backupIds[] = (int)$b['id'];
            }

            // Schutz: Ein leeres Backup darf NICHT alle aktiven Baustellen ins Archiv schieben.
            $curActive = (int)$this->db->query("SELECT COUNT(*) FROM baustellen WHERE archiviert = 0")->fetchColumn();
            if (empty($backupIds) && $curActive > 0) {
                jsonOut(['error' => 'Abgebrochen: Das Backup enthält keine aktiven Baustellen – „Komplett ersetzen" würde alle aktiven Projekte archivieren.'], 400);
            }

            // Sicherheits-Snapshot des aktuellen Standes vor dem Überschreiben.
            try {
                $safety = $this->_createSafetySnapshot('replace');
            } catch (\Throwable $e) {
                error_log('Pre-Replace-Snapshot fehlgeschlagen: ' . $e->getMessage());
                jsonOut(['error' => 'Ersetzen abgebrochen: Der aktuelle Stand konnte nicht gesichert werden. Es wurden KEINE Daten verändert.'], 500);
            }

            // 1) Aktive Baustellen des Ziels, die NICHT im Backup stehen → ins Archiv.
            $targetActive = array_map('intval', $this->db->query("SELECT id FROM baustellen WHERE archiviert = 0")->fetchAll(\PDO::FETCH_COLUMN));
            $toArchive    = array_values(array_diff($targetActive, $backupIds));
            $archivedCount = $this->_archiveBaustellenForReplace($toArchive);

            // 2) Aktive Daten 1:1 schreiben (force, da Ziel-Revision bereits > 0 sein kann).
            $res = DataService::saveAllData($this->db, $snap['data'], null, true, (string)($_SESSION['username'] ?? ''));
            if (isset($res['error'])) {
                jsonOut(['error' => 'Ersetzen abgebrochen: ' . $res['error'] . ' Sicherheitskopie: ' . $safety], 409);
            }

            // 3) Modul-Tabellen inkl. Zeiterfassungs-Historie 1:1 spiegeln.
            $this->_restoreFromSqlite($tmpDir . 'database.sqlite', true);

        } finally {
            foreach (glob($tmpDir . '*') ?: [] as $f) @unlink($f);
            @rmdir($tmpDir);
        }

        AuditService::log('backup_replace_upload', 'Backup-ZIP komplett ersetzt, Baustellen=' . count($snap['data']['baustellen'] ?? []) . ', archiviert=' . $archivedCount . ', Sicherheitskopie=' . $safety);
        jsonOut(['ok' => true, 'data' => $snap['data'], 'archived' => $archivedCount, 'safetyBackup' => $safety]);
    }

    /**
     * Verschiebt die übergebenen aktiven Baustellen ins Archiv (Export-JSON + Soft-Delete),
     * analog zu BaustelleActions::archive(). Gibt die Anzahl archivierter Baustellen zurück.
     */
    private function _archiveBaustellenForReplace(array $ids): int
    {
        if (empty($ids)) return 0;

        $pauschalen = $this->db->query("SELECT id, name, preis FROM pauschalen ORDER BY id")->fetchAll();
        $pList = array_map(
            fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'preis' => money_from_cents($p['preis'])],
            $pauschalen
        );

        $upd = $this->db->prepare(
            "UPDATE baustellen SET archiviert = 1, archivFile = ?, archiviertAm = ?, archiviertVon = ? WHERE id = ?"
        );
        $user  = (string)($_SESSION['username'] ?? '');
        $count = 0;
        foreach (array_values($ids) as $idx => $bId) {
            $bId = (int)$bId;
            $b = DataService::loadBaustelle($this->db, $bId);
            if (!$b) continue;

            $safeName    = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $b['name'] ?? ('id' . $bId));
            $archiveName = date('Y-m-d_His') . '_' . sprintf('%02d', $idx) . '_' . $safeName;

            $payload = [
                'archivedAt' => date('c'),
                'archivedBy' => ($user !== '' ? $user : 'System') . ' (Backup-Ersetzung)',
                'baustelle'  => $b,
                'pauschalen' => $pList,
            ];
            file_put_contents(
                ARCHIVE_DIR . $archiveName . '.json',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
            $upd->execute([$archiveName, date('Y-m-d H:i:s'), $user, $bId]);
            $count++;
        }
        return $count;
    }

    /** Stellt alle SQLite-Tabellen aus einer Backup-DB-Datei wieder her.
     *  Jede Tabelle wird isoliert behandelt – Fehler blockieren nicht den Restore der übrigen.
     *  $full=true (nur "Komplett ersetzen") spiegelt zusätzlich die Zeiterfassungs-Historie
     *  (zeiterfassung_log/-meta) 1:1; audit_log und cal_tokens bleiben serverlokal. */
    private function _restoreFromSqlite(string $sqlitePath, bool $full = false): void
    {
        if (!file_exists($sqlitePath)) return;
        try {
            $bakDb = new \PDO('sqlite:' . $sqlitePath);
            $bakDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $bakDb->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $sr = function (string $name, string $sel, string $del, string $ins, callable $map) use ($bakDb) {
                try {
                    $rows = $bakDb->query($sel)->fetchAll();
                    if (!$rows) return;
                    $this->db->beginTransaction();
                    $this->db->exec($del);
                    $stmt = $this->db->prepare($ins);
                    foreach ($rows as $r) { $stmt->execute($map($r)); }
                    $this->db->commit();
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    error_log("_restoreFromSqlite '$name' übersprungen: " . $e->getMessage());
                }
            };

            // Restore überträgt auch die erweiterten Felder; fehlt clientUuid im Backup,
            // wird sie aus dem pk abgeleitet (eindeutig, erfüllt idx_zeit_uuid).
            $sr('zeiterfassung', "SELECT * FROM zeiterfassung", "DELETE FROM zeiterfassung",
                "INSERT INTO zeiterfassung (pk,username,entryId,datum,typ,baustelleId,stunden,bemerkung,von,bis,pause,stundenKatId,clientUuid,status,errorCode,errorMessage,baustelleName,createdAt,updatedAt,quelle) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['pk']??null,$r['username']??'',$r['entryId']??null,$r['datum']??'',$r['typ']??'',$r['baustelleId']??null,$r['stunden']??0,$r['bemerkung']??'',
                           $r['von']??'',$r['bis']??'',$r['pause']??0,$r['stundenKatId']??null,
                           ($r['clientUuid']??'') !== '' ? $r['clientUuid'] : ('legacy:' . ($r['pk'] ?? uniqid('r', true))),
                           ($r['status']??'') !== '' ? $r['status'] : 'booked_valid',
                           $r['errorCode']??'',$r['errorMessage']??'',$r['baustelleName']??'',
                           $r['createdAt']??'',$r['updatedAt']??'',$r['quelle']??'']);

            $sr('wochenplanung', "SELECT * FROM wochenplanung", "DELETE FROM wochenplanung",
                "INSERT INTO wochenplanung (id,username,datum,typ,baustelleId,bemerkung,position) VALUES (?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['username']??'',$r['datum']??'',$r['typ']??'',$r['baustelleId']??null,$r['bemerkung']??'',$r['position']??0]);

            $sr('schnellnotizen', "SELECT * FROM schnellnotizen", "DELETE FROM schnellnotizen",
                "INSERT INTO schnellnotizen (id,text,baustelleId,baustelleName,ersteller,kuerzel,datum,archiviert,archiviertAm,archiviertVon) VALUES (?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['text']??'',$r['baustelleId']??null,$r['baustelleName']??'',$r['ersteller']??'',$r['kuerzel']??'',$r['datum']??'',$r['archiviert']??0,$r['archiviertAm']??null,$r['archiviertVon']??null]);

            $sr('users', "SELECT * FROM users", "DELETE FROM users",
                "INSERT OR REPLACE INTO users (id,username,password,role,kuerzel,visibleBaustellen,mustChangePassword,isSubunternehmer,dienstleisterId,stundenKategorie,showInZeitverwaltung,showInWochenplanung,sollstunden,sollstundenTag,sollTageWoche,urlaubstageProJahr,anschrift,email,telefon,isLocked,failedLoginAttempts,mobileLightOnly,personalnummer,arbeitstage) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['username']??'',$r['password']??'',$r['role']??'normal',$r['kuerzel']??'',$r['visibleBaustellen']??'all',$r['mustChangePassword']??0,$r['isSubunternehmer']??0,$r['dienstleisterId']??null,$r['stundenKategorie']??'',$r['showInZeitverwaltung']??1,$r['showInWochenplanung']??1,$r['sollstunden']??0,$r['sollstundenTag']??8,$r['sollTageWoche']??5,$r['urlaubstageProJahr']??'{}',$r['anschrift']??'',$r['email']??'',$r['telefon']??'',$r['isLocked']??0,$r['failedLoginAttempts']??0,$r['mobileLightOnly']??0,$r['personalnummer']??'',$r['arbeitstage']??'1,2,3,4,5']);

            $sr('kunden', "SELECT * FROM kunden", "DELETE FROM kunden",
                "INSERT OR REPLACE INTO kunden (id,firma,anrede,vorname,nachname,strasse,plz,ort,telefon,mobil,email,notizen,erstellt,geaendert,kundennummer) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['firma']??'',$r['anrede']??'',$r['vorname']??'',$r['nachname']??'',$r['strasse']??'',$r['plz']??'',$r['ort']??'',$r['telefon']??'',$r['mobil']??'',$r['email']??'',$r['notizen']??'',$r['erstellt']??'',$r['geaendert']??'',$r['kundennummer']??'']);

            $sr('rechnungen', "SELECT * FROM rechnungen", "DELETE FROM rechnungen",
                "INSERT OR REPLACE INTO rechnungen (id,typ,nummer,kundeId,baustelleId,datum,faelligAm,status,absender,positionen,notizen,beschreibung,zahlungsziel,createdAt,createdBy,updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['typ']??'rechnung',$r['nummer']??'',$r['kundeId']??null,$r['baustelleId']??null,$r['datum']??'',$r['faelligAm']??'',$r['status']??'offen',$r['absender']??'{}',$r['positionen']??'[]',$r['notizen']??'',$r['beschreibung']??'',$r['zahlungsziel']??'',$r['createdAt']??'',$r['createdBy']??'',$r['updatedAt']??'']);

            $sr('dienstleister', "SELECT * FROM dienstleister", "DELETE FROM dienstleister",
                "INSERT OR REPLACE INTO dienstleister (id,firma,kontakt,telefon,email,notizen) VALUES (?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['firma']??'',$r['kontakt']??'',$r['telefon']??'',$r['email']??'',$r['notizen']??'']);

            $sr('termine', "SELECT * FROM termine", "DELETE FROM termine",
                "INSERT OR REPLACE INTO termine (id,titel,beschreibung,datum,zeitVon,zeitBis,ganztags,ort,baustelleId,zugewiesen,ersteller,erstelltAm,farbe,wiederholung) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['titel']??'',$r['beschreibung']??'',$r['datum']??'',$r['zeitVon']??'',$r['zeitBis']??'',$r['ganztags']??0,$r['ort']??'',$r['baustelleId']??null,$r['zugewiesen']??'[]',$r['ersteller']??'',$r['erstelltAm']??'',$r['farbe']??'#00B4D8',$r['wiederholung']??'']);

            $sr('gleitzeitkonto_buchungen', "SELECT * FROM gleitzeitkonto_buchungen", "DELETE FROM gleitzeitkonto_buchungen",
                "INSERT OR REPLACE INTO gleitzeitkonto_buchungen (id,username,datum,betrag,kommentar,erstellt_von,erstellt_am) VALUES (?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['username']??'',$r['datum']??'',$r['betrag']??0,$r['kommentar']??'',$r['erstellt_von']??'',$r['erstellt_am']??'']);

            $sr('zeiterfassung_wochenpruefung', "SELECT * FROM zeiterfassung_wochenpruefung", "DELETE FROM zeiterfassung_wochenpruefung",
                "INSERT OR REPLACE INTO zeiterfassung_wochenpruefung (id,username,kw,geprueft,geprueftVon,geprueftAm,kommentar) VALUES (?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['username']??'',$r['kw']??'',$r['geprueft']??0,$r['geprueftVon']??'',$r['geprueftAm']??'',$r['kommentar']??'']);

            $sr('zeiterfassung_tagespruefung', "SELECT * FROM zeiterfassung_tagespruefung", "DELETE FROM zeiterfassung_tagespruefung",
                "INSERT OR REPLACE INTO zeiterfassung_tagespruefung (id,username,datum,geprueft,geprueftVon,geprueftAm,kommentar) VALUES (?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['username']??'',$r['datum']??'',$r['geprueft']??0,$r['geprueftVon']??'',$r['geprueftAm']??'',$r['kommentar']??'']);

            $sr('settings', "SELECT data FROM settings WHERE id=1", "DELETE FROM settings",
                "INSERT OR REPLACE INTO settings (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            $sr('permissions_config', "SELECT data FROM permissions_config WHERE id=1", "DELETE FROM permissions_config",
                "INSERT OR REPLACE INTO permissions_config (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            $sr('metallzuschlag', "SELECT data FROM metallzuschlag WHERE id=1", "DELETE FROM metallzuschlag",
                "INSERT OR REPLACE INTO metallzuschlag (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            $sr('lager_orte', "SELECT * FROM lager_orte", "DELETE FROM lager_orte",
                "INSERT OR REPLACE INTO lager_orte (id,name,beschreibung,aktiv,erstellt_am) VALUES (?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['name']??'',$r['beschreibung']??'',$r['aktiv']??1,$r['erstellt_am']??'']);

            $sr('lager_artikel', "SELECT * FROM lager_artikel", "DELETE FROM lager_artikel",
                "INSERT OR REPLACE INTO lager_artikel (id,artikelnr,bezeichnung,menge,einheit,ek_preis,vk_preis,lagerort_id,mindestbestand,kategorie,notiz,erstellt_von,erstellt_am,geaendert_am) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['artikelnr']??'',$r['bezeichnung']??'',$r['menge']??0,$r['einheit']??'',$r['ek_preis']??null,$r['vk_preis']??null,$r['lagerort_id']??null,$r['mindestbestand']??0,$r['kategorie']??'',$r['notiz']??'',$r['erstellt_von']??'',$r['erstellt_am']??'',$r['geaendert_am']??null]);

            $sr('dashboard_items', "SELECT * FROM dashboard_items", "DELETE FROM dashboard_items",
                "INSERT OR REPLACE INTO dashboard_items (id,typ,titel,beschreibung,faelligAm,status,prioritaet,linkTyp,linkId,ersteller,erstelltAm,farbe,sortPos,zugewiesen_an) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['typ']??'aufgabe',$r['titel']??'',$r['beschreibung']??'',$r['faelligAm']??null,$r['status']??'offen',$r['prioritaet']??'normal',$r['linkTyp']??null,$r['linkId']??null,$r['ersteller']??'',$r['erstelltAm']??'',$r['farbe']??'#3B82F6',$r['sortPos']??0,$r['zugewiesen_an']??'']);

            $sr('aufmass', "SELECT * FROM aufmass", "DELETE FROM aufmass",
                "INSERT OR REPLACE INTO aufmass (id,baustelle_id,titel,status,erstellt_von,erstellt_am,geprueft_von,geprueft_am,uebernommen_am,notiz) VALUES (?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['baustelle_id']??null,$r['titel']??'',$r['status']??'entwurf',$r['erstellt_von']??'',$r['erstellt_am']??'',$r['geprueft_von']??null,$r['geprueft_am']??null,$r['uebernommen_am']??null,$r['notiz']??'']);

            $sr('aufmass_abschnitt', "SELECT * FROM aufmass_abschnitt", "DELETE FROM aufmass_abschnitt",
                "INSERT OR REPLACE INTO aufmass_abschnitt (id,aufmass_id,name,sortier) VALUES (?,?,?,?)",
                fn($r) => [$r['id']??null,$r['aufmass_id']??null,$r['name']??'',$r['sortier']??0]);

            $sr('aufmass_position', "SELECT * FROM aufmass_position", "DELETE FROM aufmass_position",
                "INSERT OR REPLACE INTO aufmass_position (id,aufmass_id,abschnitt_id,bezeichnung,formel,menge,einheit,einzelpreis,ek,ref_typ,ref_id,ausgewaehlt,sortier,notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['aufmass_id']??null,$r['abschnitt_id']??null,$r['bezeichnung']??'',$r['formel']??'',$r['menge']??0,$r['einheit']??'',$r['einzelpreis']??null,$r['ek']??null,$r['ref_typ']??null,$r['ref_id']??null,$r['ausgewaehlt']??1,$r['sortier']??0,$r['notiz']??'']);

            $sr('vde0100_templates', "SELECT * FROM vde0100_templates", "DELETE FROM vde0100_templates",
                "INSERT OR REPLACE INTO vde0100_templates (id,name,beschreibung,daten,erstellt_von,erstellt_am) VALUES (?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['name']??'',$r['beschreibung']??'',$r['daten']??'{}',$r['erstellt_von']??'',$r['erstellt_am']??'']);

            $sr('vde0100_protokolle', "SELECT * FROM vde0100_protokolle", "DELETE FROM vde0100_protokolle",
                "INSERT OR REPLACE INTO vde0100_protokolle (id,titel,kundeId,baustelleId,status,anlagenart,schutzmassnahme,nennspannung,nennfrequenz,nennstrom,erstellt_von,erstellt_am,fixiert_am,unterschrift,bemerkung,norm,projektnummer,pruef_datum,sichtpruefung_status,sichtpruefung_bem,funktionspruefung_status,funktionspruefung_bem,messgeraet_hersteller,messgeraet_typ,messgeraet_kalibrierung,anlagenanschrift,kundenanschrift,netzbetreiber) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['titel']??'',$r['kundeId']??null,$r['baustelleId']??null,$r['status']??'entwurf',$r['anlagenart']??'',$r['schutzmassnahme']??'',$r['nennspannung']??'230',$r['nennfrequenz']??'50',$r['nennstrom']??'',$r['erstellt_von']??'',$r['erstellt_am']??'',$r['fixiert_am']??'',$r['unterschrift']??'',$r['bemerkung']??'',$r['norm']??'vde0100_600',$r['projektnummer']??'',$r['pruef_datum']??'',$r['sichtpruefung_status']??'',$r['sichtpruefung_bem']??'',$r['funktionspruefung_status']??'',$r['funktionspruefung_bem']??'',$r['messgeraet_hersteller']??'',$r['messgeraet_typ']??'',$r['messgeraet_kalibrierung']??'',$r['anlagenanschrift']??'',$r['kundenanschrift']??'',$r['netzbetreiber']??'']);

            $sr('vde0100_gebaeude', "SELECT * FROM vde0100_gebaeude", "DELETE FROM vde0100_gebaeude",
                "INSERT OR REPLACE INTO vde0100_gebaeude (id,protokollId,bezeichnung,sortPos) VALUES (?,?,?,?)",
                fn($r) => [$r['id']??null,$r['protokollId']??null,$r['bezeichnung']??'',$r['sortPos']??0]);

            $sr('vde0100_verteiler', "SELECT * FROM vde0100_verteiler", "DELETE FROM vde0100_verteiler",
                "INSERT OR REPLACE INTO vde0100_verteiler (id,gebaeudeId,bezeichnung,nennstrom,rcd_vorhanden,rcd_nennstrom,rcd_nennfehler,rcd_typ,sortPos,bemerkung) VALUES (?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['gebaeudeId']??null,$r['bezeichnung']??'',$r['nennstrom']??null,$r['rcd_vorhanden']??0,$r['rcd_nennstrom']??null,$r['rcd_nennfehler']??null,$r['rcd_typ']??'',$r['sortPos']??0,$r['bemerkung']??'']);

            $sr('vde0100_rcd', "SELECT * FROM vde0100_rcd", "DELETE FROM vde0100_rcd",
                "INSERT OR REPLACE INTO vde0100_rcd (id,verteilerId,bezeichnung,nennstrom,nennfehlerstrom,typ,sortPos,bemerkung) VALUES (?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['verteilerId']??null,$r['bezeichnung']??'RCD',$r['nennstrom']??null,$r['nennfehlerstrom']??null,$r['typ']??'',$r['sortPos']??0,$r['bemerkung']??'']);

            $sr('vde0100_sicherungen', "SELECT * FROM vde0100_sicherungen", "DELETE FROM vde0100_sicherungen",
                "INSERT OR REPLACE INTO vde0100_sicherungen (id,verteilerId,bezeichnung,typ,nennstrom,leiterquerschnitt,messung_iso,messung_zs,messung_rcd_id,messung_rcd_tt,messung_rb,messung_re,pruefstatus,sortPos,bemerkung,rcdId,messung_zi,messung_polaritaet) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['verteilerId']??null,$r['bezeichnung']??'',$r['typ']??'',$r['nennstrom']??null,$r['leiterquerschnitt']??'',$r['messung_iso']??null,$r['messung_zs']??null,$r['messung_rcd_id']??null,$r['messung_rcd_tt']??null,$r['messung_rb']??null,$r['messung_re']??null,$r['pruefstatus']??'',$r['sortPos']??0,$r['bemerkung']??'',$r['rcdId']??null,$r['messung_zi']??null,$r['messung_polaritaet']??'']);

            $sr('din1090_welders', "SELECT * FROM din1090_welders", "DELETE FROM din1090_welders",
                "INSERT OR REPLACE INTO din1090_welders (id,name,stempel_nr,qualifikation_nr,norm,verfahren,position,werkstoff_gruppe,dicke_bereich,gueltig_bis,bemerkung,erstellt_am) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['name']??'',$r['stempel_nr']??'',$r['qualifikation_nr']??'',$r['norm']??'EN ISO 9606-1',$r['verfahren']??'',$r['position']??'',$r['werkstoff_gruppe']??'',$r['dicke_bereich']??'',$r['gueltig_bis']??'',$r['bemerkung']??'',$r['erstellt_am']??'']);

            $sr('din1090_projects', "SELECT * FROM din1090_projects", "DELETE FROM din1090_projects",
                "INSERT OR REPLACE INTO din1090_projects (id,baustelleId,bezeichnung,ausfuehrungsklasse,werkstoff,normen,verantwortlicher,schweissaufsicht,pruefstelle,status,notizen,erstellt_am,erstellt_von,aktualisiert_am) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['baustelleId']??null,$r['bezeichnung']??'',$r['ausfuehrungsklasse']??'EXC2',$r['werkstoff']??'S235JR',$r['normen']??'DIN EN 1090-2',$r['verantwortlicher']??'',$r['schweissaufsicht']??'',$r['pruefstelle']??'',$r['status']??'aktiv',$r['notizen']??'',$r['erstellt_am']??'',$r['erstellt_von']??'',$r['aktualisiert_am']??'']);

            $sr('din1090_wps', "SELECT * FROM din1090_wps", "DELETE FROM din1090_wps",
                "INSERT OR REPLACE INTO din1090_wps (id,projectId,wps_nr,verfahren,grundwerkstoff,zusatzwerkstoff,schutzgas,position,nahtart,blechdicke_von,blechdicke_bis,vorwaermung,wpqr_nr,status,bemerkung,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['wps_nr']??'',$r['verfahren']??'',$r['grundwerkstoff']??'',$r['zusatzwerkstoff']??'',$r['schutzgas']??'',$r['position']??'',$r['nahtart']??'',$r['blechdicke_von']??0,$r['blechdicke_bis']??0,$r['vorwaermung']??'',$r['wpqr_nr']??'',$r['status']??'freigegeben',$r['bemerkung']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            $sr('din1090_materials', "SELECT * FROM din1090_materials", "DELETE FROM din1090_materials",
                "INSERT OR REPLACE INTO din1090_materials (id,projectId,bezeichnung,werkstoff,abmessung,charge_nr,schmelz_nr,zeugnis_typ,zeugnis_nr,lieferant,menge,einheit,pruef_status,bemerkung,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['bezeichnung']??'',$r['werkstoff']??'',$r['abmessung']??'',$r['charge_nr']??'',$r['schmelz_nr']??'',$r['zeugnis_typ']??'3.1',$r['zeugnis_nr']??'',$r['lieferant']??'',$r['menge']??0,$r['einheit']??'Stk',$r['pruef_status']??'offen',$r['bemerkung']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            $sr('din1090_weld_log', "SELECT * FROM din1090_weld_log", "DELETE FROM din1090_weld_log",
                "INSERT OR REPLACE INTO din1090_weld_log (id,projectId,naht_nr,bauteil,zeichnung_nr,wps_id,schweisser_id,datum,position,nahtart,a_mass,laenge,vorwaermung,zwischenlagen_temp,pruef_status,vt_ergebnis,bemerkung,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['naht_nr']??'',$r['bauteil']??'',$r['zeichnung_nr']??'',$r['wps_id']??null,$r['schweisser_id']??null,$r['datum']??'',$r['position']??'',$r['nahtart']??'',$r['a_mass']??0,$r['laenge']??0,$r['vorwaermung']??'',$r['zwischenlagen_temp']??'',$r['pruef_status']??'offen',$r['vt_ergebnis']??'',$r['bemerkung']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            $sr('din1090_inspections', "SELECT * FROM din1090_inspections", "DELETE FROM din1090_inspections",
                "INSERT OR REPLACE INTO din1090_inspections (id,projectId,pruef_art,bauteil,naht_nr,pruef_datum,pruefer,pruef_norm,ergebnis,report_nr,umfang,bemerkung,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['pruef_art']??'VT',$r['bauteil']??'',$r['naht_nr']??'',$r['pruef_datum']??'',$r['pruefer']??'',$r['pruef_norm']??'',$r['ergebnis']??'bestanden',$r['report_nr']??'',$r['umfang']??'',$r['bemerkung']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            $sr('din1090_ncr', "SELECT * FROM din1090_ncr", "DELETE FROM din1090_ncr",
                "INSERT OR REPLACE INTO din1090_ncr (id,projectId,ncr_nr,datum,bauteil,beschreibung,ursache,massnahme,verantwortlicher,frist,status,abgeschlossen_am,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['ncr_nr']??'',$r['datum']??'',$r['bauteil']??'',$r['beschreibung']??'',$r['ursache']??'',$r['massnahme']??'',$r['verantwortlicher']??'',$r['frist']??'',$r['status']??'offen',$r['abgeschlossen_am']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            $sr('din1090_checklists', "SELECT * FROM din1090_checklists", "DELETE FROM din1090_checklists",
                "INSERT OR REPLACE INTO din1090_checklists (id,projectId,kategorie,punkt,erforderlich_ab,erledigt,erledigt_am,erledigt_von,bemerkung) VALUES (?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['kategorie']??'',$r['punkt']??'',$r['erforderlich_ab']??1,$r['erledigt']??0,$r['erledigt_am']??'',$r['erledigt_von']??'',$r['bemerkung']??'']);

            $sr('din1090_surface', "SELECT * FROM din1090_surface", "DELETE FROM din1090_surface",
                "INSERT OR REPLACE INTO din1090_surface (id,projectId,bauteil,system,vorbehandlung,grundierung,schicht_1,schicht_2,soll_dicke,ist_dicke,pruef_datum,pruefer,ergebnis,bemerkung,erstellt_am,erstellt_von) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['bauteil']??'',$r['system']??'',$r['vorbehandlung']??'',$r['grundierung']??'',$r['schicht_1']??'',$r['schicht_2']??'',$r['soll_dicke']??0,$r['ist_dicke']??0,$r['pruef_datum']??'',$r['pruefer']??'',$r['ergebnis']??'bestanden',$r['bemerkung']??'',$r['erstellt_am']??'',$r['erstellt_von']??'']);

            // v2.10.0: Gruppen
            $sr('gruppen', "SELECT * FROM gruppen", "DELETE FROM gruppen",
                "INSERT OR REPLACE INTO gruppen (id,name,farbe,erstelltAm,erstelltVon) VALUES (?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['name']??'',$r['farbe']??'#6366f1',$r['erstelltAm']??'',$r['erstelltVon']??'']);
            $sr('gruppen_mitglieder', "SELECT * FROM gruppen_mitglieder", "DELETE FROM gruppen_mitglieder",
                "INSERT OR REPLACE INTO gruppen_mitglieder (id,gruppen_id,username) VALUES (?,?,?)",
                fn($r) => [$r['id']??null,$r['gruppen_id']??null,$r['username']??'']);

            // v2.10.16: Erinnerung-/Backup-E-Mail-Einstellungen (Single-Row JSON)
            // Abwärtskompatibel: fehlt die Tabelle im alten Backup, wird sie übersprungen.
            $sr('erinnerung_settings', "SELECT data FROM erinnerung_settings WHERE id=1", "DELETE FROM erinnerung_settings",
                "INSERT OR REPLACE INTO erinnerung_settings (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            // v2.10.16: Metallprofil-Katalog (Single-Row JSON)
            $sr('metall_profile_catalog', "SELECT data FROM metall_profile_catalog WHERE id=1", "DELETE FROM metall_profile_catalog",
                "INSERT OR REPLACE INTO metall_profile_catalog (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            // v2.10.16: HiCAD-Profil-Status (Single-Row JSON)
            $sr('hicad_profile_state', "SELECT data FROM hicad_profile_state WHERE id=1", "DELETE FROM hicad_profile_state",
                "INSERT OR REPLACE INTO hicad_profile_state (id,data) VALUES (1,?)", fn($r) => [$r['data']??'{}']);

            // v2.10.16: HiCAD-Profilbibliothek (importierter Katalog)
            $sr('hicad_profile', "SELECT * FROM hicad_profile", "DELETE FROM hicad_profile",
                "INSERT OR REPLACE INTO hicad_profile (id,kategorie_pfad,kategorie,tabelle,bezeichnung,typ,kg_pro_m,material,quelle,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['kategorie_pfad']??'',$r['kategorie']??'',$r['tabelle']??'',$r['bezeichnung']??'',$r['typ']??'profil',$r['kg_pro_m']??null,$r['material']??'S235',$r['quelle']??'hicad-ipl',$r['updated_at']??'']);

            // v2.10.16: OCI-Lieferanten-Konfiguration
            $sr('oci_lieferanten', "SELECT * FROM oci_lieferanten", "DELETE FROM oci_lieferanten",
                "INSERT OR REPLACE INTO oci_lieferanten (id,name,url,username,password,aktiv,notizen,erstelltAm) VALUES (?,?,?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['name']??'',$r['url']??'',$r['username']??'',$r['password']??'',$r['aktiv']??1,$r['notizen']??'',$r['erstelltAm']??'']);

            // v2.10.16: DIN1090-Audit-Log (Compliance-Pflichtdokumentation – kein System-Log)
            $sr('din1090_audit_log', "SELECT * FROM din1090_audit_log", "DELETE FROM din1090_audit_log",
                "INSERT OR REPLACE INTO din1090_audit_log (id,projectId,aktion,details,benutzer,zeitpunkt) VALUES (?,?,?,?,?,?)",
                fn($r) => [$r['id']??null,$r['projectId']??null,$r['aktion']??'',$r['details']??'',$r['benutzer']??'',$r['zeitpunkt']??'']);

            // Nur bei "Komplett ersetzen": Zeiterfassungs-Historie 1:1 spiegeln.
            if ($full) {
                $sr('zeiterfassung_log', "SELECT * FROM zeiterfassung_log", "DELETE FROM zeiterfassung_log",
                    "INSERT INTO zeiterfassung_log (id,username,entryId,aktion,alteWerte,neueWerte,geaendertVon,geaendertAm) VALUES (?,?,?,?,?,?,?,?)",
                    fn($r) => [$r['id']??null,$r['username']??'',$r['entryId']??null,$r['aktion']??'',$r['alteWerte']??'',$r['neueWerte']??'',$r['geaendertVon']??'',$r['geaendertAm']??'']);

                $sr('zeiterfassung_meta', "SELECT * FROM zeiterfassung_meta", "DELETE FROM zeiterfassung_meta",
                    "INSERT INTO zeiterfassung_meta (username,rev,updatedAt,updatedBy) VALUES (?,?,?,?)",
                    fn($r) => [$r['username']??'',$r['rev']??0,$r['updatedAt']??'',$r['updatedBy']??'']);
            }

            $bakDb = null;
        } catch (\Exception $e) {
            error_log('_restoreFromSqlite konnte Backup-DB nicht öffnen: ' . $e->getMessage());
        }
    }
}
