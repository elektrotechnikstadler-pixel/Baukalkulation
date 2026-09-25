<?php
namespace App;

use App\Database\Connection;
use App\Database\ConnectionConfig;
use App\Database\Migrator;

/**
 * Datenbankverbindung (Singleton, SQLite oder PostgreSQL per BK_DB_DRIVER).
 * Das Schema verwalten die Migrationen in migrations/.
 */
class Database
{
    private static ?Connection $pdo = null;

    public static function connect(): Connection
    {
        if (self::$pdo !== null) return self::$pdo;

        $pdo = ConnectionConfig::open();
        Migrator::ensureCurrent($pdo, DATA_DIR . 'migrate.lock');
        return self::$pdo = $pdo;
    }

    // ── Hilfs-Queries ────────────────────────────────────────
    public static function fetchOne(\PDO $db, string $sql, array $params = []): ?array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function fetchAll(\PDO $db, string $sql, array $params = []): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(\PDO $db, string $sql, array $params = []): int
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
