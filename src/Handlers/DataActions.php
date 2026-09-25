<?php
namespace App\Handlers;

use App\Auth;
use App\Backup\BackupArchive;
use App\Backup\BackupWriter;
use App\Backup\ImportConflictException;
use App\Backup\Importer;
use App\Backup\InvalidBackupException;
use App\Backup\RestoreService;
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
            $backedUpFiles = array_values(array_diff(
                array_map('basename', glob($d . '/*.json') ?: []),
                [BackupArchive::FILE_MANIFEST],
            ));
            $result[] = [
                'date'  => basename($d),
                'ts'    => $snap['ts'] ?? '',
                'count' => count($snap['data']['baustellen'] ?? []),
                'files' => $backedUpFiles,
            ];
        }
        jsonOut(['ok' => true, 'backups' => $result]);
    }

    /** restore – gespeicherte Sicherung aus BACKUP_DIR wiederherstellen (nur Admin/Master). */
    public function restore(): void
    {
        Auth::requireRole('admin', 'master');
        // Zustandsändernd – nicht per GET-Link auslösbar (CSRF).
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Wiederherstellen nur per POST.'], 405);
        }
        $date = preg_replace('/[^0-9\-_a-zA-Z]/', '', $_GET['date'] ?? '');
        try {
            if ($date !== '' && is_file(BACKUP_DIR . $date . '/' . BackupArchive::FILE_JSON)) {
                $archive = BackupArchive::fromDir(BACKUP_DIR . $date);
            } elseif ($date !== '' && is_file(BACKUP_DIR . $date . '.json')) {
                $archive = BackupArchive::fromJsonFile(BACKUP_DIR . $date . '.json');
            } else {
                jsonOut(['error' => 'Sicherung nicht gefunden.'], 404);
            }
        } catch (InvalidBackupException $e) {
            jsonOut(['error' => $e->getMessage()], 400);
        }
        $this->_runRestore($archive, Importer::MODE_MERGE, 'restore', 'data_restore', 'Datum=' . $date);
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
        $dirName = self::backupDirName(trim($this->body['name'] ?? ''));

        // Verifiziertes Schreiben: Schlägt ein Schritt fehl, wird die unvollständige
        // Sicherung entfernt und ein klarer Fehler gemeldet.
        try {
            BackupWriter::writeDir($this->db, BACKUP_DIR . $dirName);
        } catch (\Throwable $e) {
            BackupArchive::removeDir(BACKUP_DIR . $dirName . '/');
            error_log('backupCreate fehlgeschlagen: ' . $e->getMessage());
            jsonOut(['error' => 'Sicherung fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        // Nur alte MANUELLE Sicherungen bereinigen – Tages- und Pre-Restore-
        // Sicherungen bleiben unangetastet.
        DataService::pruneBackupCategory('manual', BACKUP_MAX * 2);

        AuditService::log('backup_create', 'Manuelle Sicherung erstellt: ' . $dirName);
        jsonOut(['ok' => true, 'date' => $dirName]);
    }

    /** Verzeichnisname einer manuellen Sicherung: JJJJ-MM-TT_HH-MM[_name]. */
    public static function backupDirName(string $name): string
    {
        $slug = $name !== ''
            ? '_' . substr((string)preg_replace('/[^a-zA-Z0-9\-]/', '', strtr($name, [
                ' ' => '-', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
              ])), 0, 40)
            : '';
        return date('Y-m-d_H-i') . $slug;
    }

    /** Spielt eine Sicherung ein und beendet den Request mit der JSON-Antwort. */
    private function _runRestore(BackupArchive $archive, string $mode, string $reason, string $auditAction, string $auditInfo): never
    {
        try {
            $res = (new RestoreService($this->db, (string)($_SESSION['username'] ?? '')))->restore($archive, $mode, $reason);
        } catch (ImportConflictException $e) {
            $archive->cleanup();
            jsonOut(['error' => $e->getMessage(), 'conflict' => true], 409);
        } catch (InvalidBackupException $e) {
            $archive->cleanup();
            jsonOut(['error' => 'Abgebrochen: ' . $e->getMessage() . ' Es wurden KEINE Daten verändert.', 'passwordRequired' => $e->passwordRequired], 400);
        } catch (\Throwable $e) {
            $archive->cleanup();
            error_log("Wiederherstellung ({$reason}) fehlgeschlagen: " . $e->getMessage());
            jsonOut(['error' => 'Wiederherstellung abgebrochen: ' . $e->getMessage() . ' Es wurden KEINE Daten verändert.'], 500);
        }
        $archive->cleanup();
        // Neue Revision mitgeben, damit das anschließende Speichern des Clients nicht als Konflikt gilt.
        $res['data']['rev'] = $res['import']['rev'];

        AuditService::log($auditAction, 'Sicherung eingespielt (' . $mode . '): ' . $auditInfo
            . ', Format=' . $archive->format . ', Baustellen=' . count($res['data']['baustellen'])
            . ', Sicherheitskopie=' . $res['safetyBackup']);
        jsonOut(['ok' => true, 'data' => $res['data'], 'safetyBackup' => $res['safetyBackup'], 'import' => $res['import']]
            + ($res['archived'] !== null ? ['archived' => $res['archived']] : []));
    }

    /** Erzeugt ein ZIP der aktuellen (oder einer gespeicherten) Sicherung und streamt es. */
    public function backupDownload(): void
    {
        Auth::requireRole('admin', 'master');
        $date = preg_replace('/[^0-9\-_a-zA-Z]/', '', $_GET['date'] ?? '');

        $tmpDir  = null;
        $zipPath = sys_get_temp_dir() . '/bk_dl_' . bin2hex(random_bytes(6)) . '.zip';
        try {
            if ($date && is_dir(BACKUP_DIR . $date)) {
                $srcDir = BACKUP_DIR . $date . '/';
                if (!is_file($srcDir . BackupArchive::FILE_JSON)) {
                    jsonOut(['error' => 'Keine Sicherungsdaten gefunden.'], 404);
                }
            } else {
                $tmpDir = sys_get_temp_dir() . '/bk_dl_' . bin2hex(random_bytes(6)) . '/';
                BackupWriter::writeDir($this->db, $tmpDir);
                $srcDir = $tmpDir;
            }
            BackupWriter::zipDir($srcDir, $zipPath);
        } catch (\Throwable $e) {
            error_log('backupDownload fehlgeschlagen: ' . $e->getMessage());
            if (is_file($zipPath)) unlink($zipPath);
            jsonOut(['error' => 'Sicherung fehlgeschlagen: ' . $e->getMessage()], 500);
        } finally {
            if ($tmpDir !== null) BackupArchive::removeDir($tmpDir);
        }

        $zipName = 'baukalkulation_backup_' . date('Y-m-d_H-i') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: private, no-cache');
        readfile($zipPath);
        unlink($zipPath);
        exit;
    }

    /** Hochgeladene Sicherung (ZIP aller Formate) einspielen – Baustellen des Ziels, die fehlen, werden entfernt. */
    public function backupUpload(): void
    {
        Auth::requireRole('admin', 'master');
        $this->_runRestore($this->_openUploadedArchive(), Importer::MODE_MERGE, 'upload', 'backup_restore_upload', 'Upload');
    }

    /**
     * backup_upload_replace – Ziel-Instanz 1:1 durch die aktiven Daten eines
     * hochgeladenen Backups ersetzen (kein Merge).
     *
     * Unterschied zu backupUpload():
     *  - Aktive Baustellen des Ziels, die NICHT im Backup enthalten sind, werden
     *    ins Archiv verschoben (Export-JSON + Soft-Delete), NICHT gelöscht.
     *  - Zeiterfassungs-Historie (log/meta) wird zusätzlich 1:1 gespiegelt.
     *  - Bereits archivierte Baustellen/Dateien des Ziels bleiben unangetastet.
     */
    public function backupUploadReplace(): void
    {
        Auth::requireRole('admin', 'master');
        $this->_runRestore($this->_openUploadedArchive(), Importer::MODE_REPLACE, 'replace', 'backup_replace_upload', 'Upload');
    }

    private function _openUploadedArchive(): BackupArchive
    {
        $uploaded = $_FILES['backup'] ?? null;
        if (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Kein gültiges ZIP hochgeladen (Upload-Fehler).'], 400);
        }
        try {
            return BackupArchive::fromZip($uploaded['tmp_name'], isset($_POST['password']) ? (string)$_POST['password'] : null);
        } catch (InvalidBackupException $e) {
            jsonOut(['error' => $e->getMessage(), 'passwordRequired' => $e->passwordRequired], 400);
        }
    }
}
