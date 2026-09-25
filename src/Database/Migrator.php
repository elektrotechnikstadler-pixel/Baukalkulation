<?php
namespace App\Database;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Versionierte Schema-Migrationen (Phinx) für eine bestehende PDO-Verbindung.
 * Tabelle schema_migrations enthält die Versionen aller ausgeführten Migrationen.
 */
final class Migrator
{
    public const TABLE = 'schema_migrations';

    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /** Höchste im Code vorhandene Migrationsversion (Dateiname JJJJMMTTHHMMSS_*.php). */
    public static function latestVersion(): int
    {
        $latest = 0;
        foreach (glob(self::migrationsDir() . '/*.php') ?: [] as $file) {
            if (preg_match('/^(\d{14})_/', basename($file), $m)) {
                $latest = max($latest, (int)$m[1]);
            }
        }
        return $latest;
    }

    /** Höchste in der DB ausgeführte Version; 0 bei DBs ohne Migrationstabelle (vor Phinx). */
    public static function currentVersion(\PDO $db): int
    {
        try {
            return (int)$db->query('SELECT MAX(version) FROM ' . self::TABLE)->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /**
     * Bringt das Schema auf den Stand des Codes. Verweigert den Betrieb, wenn die DB
     * neuer ist als der Code (Schutz vor Downgrade mit älterem Image).
     */
    public static function ensureCurrent(\PDO $db, ?string $lockFile = null): void
    {
        $latest  = self::latestVersion();
        $current = self::currentVersion($db);
        if ($current === $latest) return;
        if ($current > $latest) {
            throw new SchemaTooNewException($current, $latest);
        }
        self::migrate($db, $lockFile);
    }

    public static function migrate(\PDO $db, ?string $lockFile = null, ?OutputInterface $output = null): void
    {
        $lock = null;
        if ($lockFile !== null) {
            $lock = fopen($lockFile, 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Migrations-Sperre nicht verfügbar: {$lockFile}");
            }
        }
        $pg = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
        try {
            // Sperrt auch gegen weitere Container/Hosts an derselben PostgreSQL-Datenbank.
            if ($pg) $db->query("SELECT pg_advisory_lock(hashtext('bk_schema_migrate'))");
            // Nach dem Warten auf die Sperre kann ein anderer Prozess bereits migriert haben.
            if (self::currentVersion($db) >= self::latestVersion()) return;
            Dialect::for($db)->prepareSchema($db);
            self::manager($db, $output ?? new NullOutput())->migrate('app');
        } finally {
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            if ($pg) $db->query("SELECT pg_advisory_unlock(hashtext('bk_schema_migrate'))");
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return list<array{version:int, name:string, applied:bool}> */
    public static function status(\PDO $db): array
    {
        $applied = [];
        try {
            foreach ($db->query('SELECT version FROM ' . self::TABLE)->fetchAll(\PDO::FETCH_COLUMN) as $v) {
                $applied[(int)$v] = true;
            }
        } catch (\PDOException) {
        }
        $rows = [];
        foreach (glob(self::migrationsDir() . '/*.php') ?: [] as $file) {
            if (!preg_match('/^(\d{14})_(.+)\.php$/', basename($file), $m)) continue;
            $rows[] = ['version' => (int)$m[1], 'name' => $m[2], 'applied' => isset($applied[(int)$m[1]])];
        }
        usort($rows, fn($a, $b) => $a['version'] <=> $b['version']);
        return $rows;
    }

    private static function manager(\PDO $db, OutputInterface $output): Manager
    {
        $config = new Config([
            'paths' => ['migrations' => self::migrationsDir()],
            'environments' => [
                'default_migration_table' => self::TABLE,
                'default_environment'     => 'app',
                'app' => [
                    'connection' => $db,
                    'name'       => $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql'
                        ? (string)$db->query('SELECT current_database()')->fetchColumn()
                        : 'app',
                ],
            ],
            'version_order' => 'creation',
        ]);
        return new Manager($config, new ArrayInput([]), $output);
    }
}
