<?php
namespace App\Database;

/**
 * Verbindungsdaten aus der Umgebung:
 *   BK_DB_DRIVER   sqlite (Standard) | pgsql
 *   BK_DB_HOST, BK_DB_PORT (5432), BK_DB_NAME, BK_DB_USER,
 *   BK_DB_PASSWORD oder BK_DB_PASSWORD_FILE (z. B. Docker-Secret)
 * SQLite nutzt DATA_DIR/database.sqlite.
 */
final class ConnectionConfig
{
    public static function driver(): string
    {
        $driver = strtolower((string)(getenv('BK_DB_DRIVER') ?: 'sqlite'));
        if (!in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new \RuntimeException("BK_DB_DRIVER '{$driver}' wird nicht unterstützt (sqlite|pgsql).");
        }
        return $driver;
    }

    public static function open(): Connection
    {
        return self::driver() === 'pgsql' ? self::openPgsql() : self::openSqlite(DATA_DIR . 'database.sqlite');
    }

    public static function openSqlite(string $path): Connection
    {
        $pdo = new Connection('sqlite:' . $path);
        $pdo->exec('PRAGMA journal_mode=WAL');
        // Datensicherheit: FULL fsync't das WAL bei JEDEM Commit auf das Volume.
        // Verhindert Verlust zuletzt gespeicherter Daten (neue Kunden/Projekte) bei
        // abruptem Container-Stop/Neustart (WAL-Frames sonst evtl. nicht persistiert).
        $pdo->exec('PRAGMA synchronous=FULL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        // H6 (v1.8.0): busy_timeout 15s, da gleichzeitige Schreiber (Backup, OCR-Job,
        // Cron) bei Last länger blockieren können als 5s.
        $pdo->exec('PRAGMA busy_timeout=15000');
        $pdo->exec('PRAGMA cache_size=-8000');
        $pdo->exec('PRAGMA temp_store=MEMORY');
        return $pdo;
    }

    public static function openPgsql(): Connection
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;application_name=baukalkulation',
            self::env('BK_DB_HOST', 'localhost'),
            (int)self::env('BK_DB_PORT', '5432'),
            self::env('BK_DB_NAME', 'baukalkulation'),
        );
        $pdo = new Connection($dsn, self::env('BK_DB_USER', 'baukalkulation'), self::password());
        $pdo->exec("SET TIME ZONE '" . (date_default_timezone_get() ?: 'Europe/Berlin') . "'");
        return $pdo;
    }

    private static function password(): string
    {
        $file = getenv('BK_DB_PASSWORD_FILE');
        if (is_string($file) && $file !== '') {
            if (!is_readable($file)) throw new \RuntimeException("BK_DB_PASSWORD_FILE nicht lesbar: {$file}");
            return trim((string)file_get_contents($file));
        }
        return (string)getenv('BK_DB_PASSWORD');
    }

    private static function env(string $name, string $default): string
    {
        $v = getenv($name);
        return is_string($v) && $v !== '' ? $v : $default;
    }
}
