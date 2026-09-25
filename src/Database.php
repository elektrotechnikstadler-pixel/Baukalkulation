<?php
namespace App;

use App\Database\Migrator;

/**
 * SQLite-Datenbankverbindung (Singleton). Das Schema verwalten die Migrationen in migrations/.
 */
class Database
{
    private static ?\PDO $pdo = null;

    public static function connect(): \PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $pdo = self::open(DATA_DIR . 'database.sqlite');
        Migrator::ensureCurrent($pdo, DATA_DIR . 'migrate.lock');
        return self::$pdo = $pdo;
    }

    /** Öffnet eine SQLite-Datei mit den Standard-Einstellungen der App (ohne Migration). */
    public static function open(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec("PRAGMA journal_mode=WAL");
        // Datensicherheit: FULL fsync't das WAL bei JEDEM Commit auf das Volume.
        // Verhindert Verlust zuletzt gespeicherter Daten (neue Kunden/Projekte) bei
        // abruptem Container-Stop/Neustart (WAL-Frames sonst evtl. nicht persistiert).
        $pdo->exec("PRAGMA synchronous=FULL");
        $pdo->exec("PRAGMA foreign_keys=ON");
        // H6 (v1.8.0): busy_timeout von 5s auf 15s erhöht, da
        // gleichzeitige Schreiber (Backup, OCR-Job, Cron) bei Last
        // länger blockieren können als 5s.
        $pdo->exec("PRAGMA busy_timeout=15000");
        $pdo->exec("PRAGMA cache_size=-8000");
        $pdo->exec("PRAGMA temp_store=MEMORY");
        return $pdo;
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
