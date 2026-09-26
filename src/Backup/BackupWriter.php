<?php
namespace App\Backup;

use App\Core\ModuleLoader;
use App\Database\ConnectionConfig;
use App\Database\Dialect;
use App\Database\Migrator;
use App\Database\TableCopier;
use App\DataService;

/**
 * Schreibt Sicherungen im Format 2: baukalkulation.json (lesbar auch für ältere
 * App-Versionen), database.sqlite (konsistenter Snapshot) und manifest.json.
 * Auch bei PostgreSQL enthält die Sicherung eine SQLite-Datei – so bleibt jede
 * Sicherung in beiden Betriebsarten einlesbar.
 */
final class BackupWriter
{
    public const FORMAT = 2;

    /** @return array Manifest der geschriebenen Sicherung */
    public static function writeDir(\PDO $db, string $destDir): array
    {
        $destDir = rtrim($destDir, '/\\') . '/';
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new \RuntimeException('Sicherungsverzeichnis konnte nicht erstellt werden (Schreibrechte?).');
        }

        $snap = ['ts' => date('c'), 'v' => APP_VERSION, 'data' => DataService::loadAllData($db)];
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Daten konnten nicht als JSON kodiert werden: ' . json_last_error_msg());
        }
        self::writeAtomic($destDir . BackupArchive::FILE_JSON, $json);

        $dbFile = $destDir . BackupArchive::FILE_DB;
        if (is_file($dbFile)) unlink($dbFile);
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            self::exportToSqlite($db, $dbFile);
        } else {
            self::snapshotSqlite($db, $dbFile);
        }
        self::verifySqlite($dbFile);

        $manifest = [
            'format'        => self::FORMAT,
            'app'           => 'baukalkulation',
            'appVersion'    => APP_VERSION,
            'schemaVersion' => Migrator::currentVersion($db),
            'driver'        => $db->getAttribute(\PDO::ATTR_DRIVER_NAME),
            'createdAt'     => $snap['ts'],
            'files'         => [],
        ];
        foreach ([BackupArchive::FILE_JSON, BackupArchive::FILE_DB] as $name) {
            $manifest['files'][$name] = [
                'sha256' => hash_file('sha256', $destDir . $name),
                'size'   => filesize($destDir . $name),
            ];
        }
        self::writeAtomic(
            $destDir . BackupArchive::FILE_MANIFEST,
            (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        return $manifest;
    }

    /** Packt ein Sicherungsverzeichnis als ZIP, optional AES-256-verschlüsselt. */
    public static function zipDir(string $srcDir, string $zipPath, ?string $password = null): void
    {
        $srcDir = rtrim($srcDir, '/\\') . '/';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZIP konnte nicht erstellt werden.');
        }
        foreach ([BackupArchive::FILE_JSON, BackupArchive::FILE_DB, BackupArchive::FILE_MANIFEST] as $name) {
            if (!is_file($srcDir . $name)) continue;
            $zip->addFile($srcDir . $name, $name);
            if ($password !== null && $password !== '') {
                $zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password);
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('ZIP konnte nicht geschrieben werden.');
        }
    }

    private static function snapshotSqlite(\PDO $db, string $dbFile): void
    {
        // VACUUM INTO liefert einen konsistenten Snapshot inkl. WAL-Inhalt, auch bei laufenden Schreibzugriffen.
        // Eigene Verbindung, weil VACUUM bei offenen Statements der Request-Verbindung scheitert.
        $source = (string)$db->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC)['file'];
        $snapshotDb = new \PDO('sqlite:' . $source);
        $snapshotDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $snapshotDb->exec('PRAGMA busy_timeout=15000');
        $snapshotDb->exec('VACUUM INTO ' . $snapshotDb->quote($dbFile));
        $snapshotDb = null;
    }

    /** PostgreSQL → neue SQLite-Datei mit gleichem Schema-Stand, aus einem konsistenten Lese-Snapshot. */
    private static function exportToSqlite(\PDO $db, string $dbFile): void
    {
        $tmp = $dbFile . '.tmp';
        if (is_file($tmp)) unlink($tmp);
        $lite = ConnectionConfig::openSqlite($tmp);
        $ownTx = false;
        try {
            $lite->exec('PRAGMA journal_mode=DELETE');
            Migrator::migrate($lite);
            (new ModuleLoader($lite))->migrateAll(false);

            $tables = [];
            foreach (Dialect::for($db)->tableNames($db) as $table) {
                if ($table === Migrator::TABLE) continue;
                if (Dialect::for($lite)->tableExists($lite, $table)) {
                    $tables[] = $table;
                } else {
                    error_log("[Backup] Tabelle {$table} ist in SQLite unbekannt und wird nicht gesichert.");
                }
            }

            // Tabellen werden alphabetisch kopiert; Fremdschlüssel dürfen erst am Ende stimmen.
            Dialect::for($lite)->beforeBulkImport($lite);
            $ownTx = !$db->inTransaction();
            if ($ownTx) Dialect::for($db)->beginSnapshot($db);
            $lite->beginTransaction();
            (new TableCopier($db, $lite))->copyAll($tables);
            $lite->commit();
        } finally {
            if ($ownTx && $db->inTransaction()) $db->commit();
            // Windows: Datei erst nach dem Schließen aller Verbindungen (auch in Zyklen, z. B. Phinx) umbenenn-/löschbar.
            unset($lite);
            gc_collect_cycles();
        }
        if (!rename($tmp, $dbFile)) {
            throw new \RuntimeException('Datenbank-Sicherung konnte nicht abgelegt werden.');
        }
    }

    private static function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) unlink($tmp);
            throw new \RuntimeException('Sicherungsdatei konnte nicht geschrieben werden (Schreibrechte?).');
        }
    }

    private static function verifySqlite(string $file): void
    {
        if (!is_file($file) || filesize($file) === 0) {
            throw new \RuntimeException('Datenbank-Datei konnte nicht gesichert werden.');
        }
        try {
            $check = new \PDO('sqlite:' . $file);
            $check->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $res = $check->query('PRAGMA integrity_check')->fetchColumn();
        } catch (\PDOException $e) {
            throw new \RuntimeException('Datenbank-Sicherung ist nicht lesbar: ' . $e->getMessage());
        }
        if (strtolower((string)$res) !== 'ok') {
            throw new \RuntimeException('Integritätsprüfung der Datenbank-Sicherung fehlgeschlagen.');
        }
    }
}
