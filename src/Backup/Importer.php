<?php
namespace App\Backup;

use App\Database\ConnectionConfig;
use App\Database\Dialect;
use App\Database\Migrator;
use App\Database\TableCopier;
use App\DataService;

/**
 * Spielt eine Sicherung in die aktuelle Datenbank ein – ganz oder gar nicht.
 *
 * Hauptdaten (Baustellen, Pauschalen, Kataloge) kommen aus baukalkulation.json,
 * alle übrigen Tabellen aus database.sqlite. Die SQLite-Datei wird vorher als Kopie
 * auf den aktuellen Schema-Stand migriert, damit auch Sicherungen alter Versionen passen.
 */
final class Importer
{
    public const MODE_MERGE   = 'merge';
    public const MODE_REPLACE = 'replace';

    /** Serverlokale bzw. über baukalkulation.json übernommene Tabellen. */
    private const SKIP_TABLES = [
        'schema_migrations', 'sqlite_sequence', 'audit_log', 'login_attempts', 'cal_tokens',
        'oci_nonces', 'data_meta', 'baustellen', 'pauschalen', 'stunden_katalog', 'material_katalog',
    ];
    /** Zeiterfassungs-Historie nur bei "Komplett ersetzen". */
    private const REPLACE_ONLY_TABLES = ['zeiterfassung_log', 'zeiterfassung_meta'];

    public function __construct(private readonly \PDO $db) {}

    /**
     * @param callable|null $inTransaction wird nach Beginn der Transaktion ausgeführt (z. B. Baustellen archivieren)
     * @return array{rev:int, tables:array<string,int>, skipped:list<string>}
     */
    public function import(BackupArchive $archive, string $mode, string $user = '', ?callable $inTransaction = null): array
    {
        $data = $archive->data();
        $source = $archive->sqlitePath !== null ? $this->prepareSource($archive->sqlitePath) : null;

        $result = ['rev' => 0, 'tables' => [], 'skipped' => []];
        $dialect = Dialect::for($this->db);
        $dialect->beforeBulkImport($this->db);
        $this->db->beginTransaction();
        try {
            $dialect->relaxForeignKeys($this->db);
            if ($inTransaction !== null) $inTransaction();
            $oldZeitRev = (int)$this->db->query('SELECT COALESCE(MAX(rev), 0) FROM zeiterfassung_meta')->fetchColumn();

            $res = DataService::saveAllData($this->db, $data, DataService::currentRev($this->db), false, $user);
            if (!isset($res['rev'])) {
                throw new ImportConflictException();
            }
            $result['rev'] = $res['rev'];

            if ($source !== null) {
                $this->restoreArchivedBaustellen($source['pdo']);
                $tables = [];
                foreach ($source['tables'] as $table) {
                    if (!$this->shouldCopy($table, $mode)) {
                        continue;
                    }
                    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                        $result['skipped'][] = $table;
                        continue;
                    }
                    $tables[] = $table;
                }
                $copier = new TableCopier($source['pdo'], $this->db);
                try {
                    $copier->createMissingTables($tables);
                    $result['tables'] = $copier->copyAll($tables);
                } catch (\RuntimeException $e) {
                    throw new InvalidBackupException($e->getMessage());
                }
            }

            // Offene Clients mit altem Stand müssen neu laden statt die Sicherung zu überschreiben.
            $this->db->prepare('UPDATE zeiterfassung_meta SET rev = rev + ?')->execute([$oldZeitRev + 1]);

            $violations = $dialect->foreignKeyViolations($this->db);
            if ($violations) {
                throw new InvalidBackupException('Sicherung enthält ungültige Verknüpfungen (' . $violations . ' Datensätze).');
            }
            try {
                $this->db->commit();
            } catch (\PDOException $e) {
                // PostgreSQL prüft verzögerte Fremdschlüssel erst hier.
                throw new InvalidBackupException('Sicherung enthält ungültige Verknüpfungen: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            $dialect->afterBulkImport($this->db);
            if ($source !== null) {
                $path = $source['path'];
                $source = $copier = null;
                gc_collect_cycles();
                self::removeFile($path);
            }
        }
        return $result;
    }

    private static function removeFile(string $path): void
    {
        try {
            if (is_file($path)) unlink($path);
        } catch (\Throwable $e) {
            error_log('[Import] Temp-Datei nicht entfernt: ' . $e->getMessage());
        }
    }

    /**
     * Kopiert die Sicherungs-DB und hebt die Kopie auf den aktuellen Schema-Stand.
     * @return array{pdo:\PDO, path:string, tables:list<string>}
     */
    private function prepareSource(string $sqlitePath): array
    {
        $path = sys_get_temp_dir() . '/bk_import_' . bin2hex(random_bytes(6)) . '.sqlite';
        if (!copy($sqlitePath, $path)) {
            throw new \RuntimeException('Sicherungs-DB konnte nicht kopiert werden.');
        }
        try {
            $pdo = ConnectionConfig::openSqlite($path);
            if (strtolower((string)$pdo->query('PRAGMA integrity_check')->fetchColumn()) !== 'ok') {
                throw new InvalidBackupException('Die Datenbank in der Sicherung ist beschädigt.');
            }
            if (Migrator::currentVersion($pdo) > Migrator::latestVersion()) {
                throw new InvalidBackupException('Die Sicherung stammt aus einer neueren App-Version und kann hier nicht eingespielt werden.');
            }
            // Nur Tabellen übernehmen, die die Sicherung selbst enthielt – nicht die beim Anheben ergänzten.
            $tables = Dialect::for($pdo)->tableNames($pdo);
            Migrator::migrate($pdo);
            return ['pdo' => $pdo, 'path' => $path, 'tables' => array_values($tables)];
        } catch (\PDOException $e) {
            $pdo = null;
            self::removeFile($path);
            throw new InvalidBackupException('Die Datenbank in der Sicherung ist nicht lesbar: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $pdo = null;
            self::removeFile($path);
            throw $e;
        }
    }

    private function shouldCopy(string $table, string $mode): bool
    {
        if (in_array($table, self::SKIP_TABLES, true)) return false;
        if (in_array($table, self::REPLACE_ONLY_TABLES, true)) return $mode === self::MODE_REPLACE;
        return true;
    }

    /**
     * Archivierte Baustellen fehlen in baukalkulation.json; ohne ihre Zeilen würden ihre IDs neu vergeben
     * und alte Stunden/Rechnungen einer neuen Baustelle zugeordnet.
     */
    private function restoreArchivedBaustellen(\PDO $src): void
    {
        $existing = array_flip($this->db->query('SELECT id FROM baustellen')->fetchAll(\PDO::FETCH_COLUMN));
        $cols = 'id, name, kundeId, data, archiviert, archivFile, archiviertAm, archiviertVon';
        $insert = $this->db->prepare("INSERT INTO baustellen ({$cols}) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($src->query("SELECT {$cols} FROM baustellen WHERE archiviert = 1", \PDO::FETCH_NUM) as $row) {
            if (isset($existing[(int)$row[0]])) continue;
            if ($row[2] === '') $row[2] = null; // kundeId: SQLite erlaubt '' in INTEGER-Spalten
            $insert->execute($row);
        }
        Dialect::for($this->db)->resetSequences($this->db, ['baustellen']);
    }
}
