<?php
namespace App\Backup;

use App\Database\Migrator;
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
        $this->db->exec('PRAGMA foreign_keys = OFF');
        $this->db->beginTransaction();
        try {
            if ($inTransaction !== null) $inTransaction();
            $oldZeitRev = (int)$this->db->query('SELECT COALESCE(MAX(rev), 0) FROM zeiterfassung_meta')->fetchColumn();

            $res = DataService::saveAllData($this->db, $data, DataService::currentRev($this->db), false, $user);
            if (!isset($res['rev'])) {
                throw new ImportConflictException();
            }
            $result['rev'] = $res['rev'];

            if ($source !== null) {
                foreach ($source['tables'] as $table) {
                    if (!$this->shouldCopy($table, $mode)) {
                        continue;
                    }
                    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                        $result['skipped'][] = $table;
                        continue;
                    }
                    try {
                        $result['tables'][$table] = $this->copyTable($source['pdo'], $table);
                    } catch (\PDOException $e) {
                        throw new InvalidBackupException("Tabelle {$table} konnte nicht übernommen werden: " . $e->getMessage());
                    }
                }
            }

            // Offene Clients mit altem Stand müssen neu laden statt die Sicherung zu überschreiben.
            $this->db->prepare('UPDATE zeiterfassung_meta SET rev = rev + ?')->execute([$oldZeitRev + 1]);

            $violations = $this->db->query('PRAGMA foreign_key_check')->fetchAll();
            if ($violations) {
                throw new InvalidBackupException('Sicherung enthält ungültige Verknüpfungen (' . count($violations) . ' Datensätze).');
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            $this->db->exec('PRAGMA foreign_keys = ON');
            if ($source !== null) {
                $path = $source['path'];
                $source = null;
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
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            if (strtolower((string)$pdo->query('PRAGMA integrity_check')->fetchColumn()) !== 'ok') {
                throw new InvalidBackupException('Die Datenbank in der Sicherung ist beschädigt.');
            }
            if (Migrator::currentVersion($pdo) > Migrator::latestVersion()) {
                throw new InvalidBackupException('Die Sicherung stammt aus einer neueren App-Version und kann hier nicht eingespielt werden.');
            }
            // Nur Tabellen übernehmen, die die Sicherung selbst enthielt – nicht die beim Anheben ergänzten.
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
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

    private function copyTable(\PDO $src, string $table): int
    {
        $qt = self::quote($table);
        if (!$this->tableExists($table)) {
            // Tabelle fehlt im Ziel (z. B. Modul noch nie geladen): Definition aus der Sicherung übernehmen.
            $ddl = (string)$src->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $src->quote($table))->fetchColumn();
            if (!preg_match('/^\s*CREATE TABLE\b/i', $ddl) || str_contains($ddl, ';')) {
                throw new InvalidBackupException("Unerwartete Tabellendefinition für {$table}.");
            }
            $this->db->exec($ddl);
        }

        $cols = array_values(array_intersect($this->columns($src, $table), $this->columns($this->db, $table)));
        $this->db->exec("DELETE FROM {$qt}");
        if (!$cols) return 0;

        $list = implode(', ', array_map([self::class, 'quote'], $cols));
        $insert = $this->db->prepare("INSERT INTO {$qt} ({$list}) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $count = 0;
        foreach ($src->query("SELECT {$list} FROM {$qt}", \PDO::FETCH_NUM) as $row) {
            $insert->execute($row);
            $count++;
        }
        return $count;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return list<string> */
    private function columns(\PDO $pdo, string $table): array
    {
        return array_column($pdo->query('PRAGMA table_info(' . self::quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC), 'name');
    }

    private static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
