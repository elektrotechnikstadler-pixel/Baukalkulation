<?php
namespace App\Services;

use App\Database;
use PDO;

/**
 * Audit-Log mit HMAC-SHA256-Hash-Chain (H4 v1.8.0).
 *
 * Persistiert jeden Eintrag in zwei Speichern:
 *  1. data/audit.log (klassische Textzeile, Backwards-Compat)
 *  2. SQLite-Tabelle audit_log mit verketteter HMAC-Signatur
 *
 * hash_n = HMAC-SHA256(pepper, hash_{n-1} || ts || user || action || details)
 *
 * Dadurch kann nachträgliche Manipulation einzelner Einträge erkannt werden:
 * AuditService::verifyChain() prüft die komplette Kette.
 */
class AuditService
{
    private static ?string $pepper = null;

    private static function pepper(): string
    {
        if (self::$pepper !== null) return self::$pepper;
        $path = DATA_DIR . 'audit_pepper.txt';
        if (!file_exists($path)) {
            $val = bin2hex(random_bytes(32));
            file_put_contents($path, $val, LOCK_EX);
            @chmod($path, 0600);
            self::$pepper = $val;
        } else {
            self::$pepper = trim((string)file_get_contents($path));
        }
        return self::$pepper;
    }

    public static function log(string $action, string $details): void
    {
        $ts       = time();
        $username = $_SESSION['username'] ?? 'system';

        // 1) Textlog (Backwards-Compat)
        $logFile = DATA_DIR . 'audit.log';
        $entry = date('c', $ts) . ' | ' . $username . ' | ' . $action . ' | ' . $details . "\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        // 2) SQLite + Hash-Chain
        try {
            $db = Database::connect();
            $row = $db->query("SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $prev = $row['hash'] ?? str_repeat('0', 64);
            $payload = $prev . '|' . $ts . '|' . $username . '|' . $action . '|' . $details;
            $hash = hash_hmac('sha256', $payload, self::pepper());

            $stmt = $db->prepare("INSERT INTO audit_log(ts, username, action, details, prev_hash, hash) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ts, $username, $action, $details, $prev, $hash]);
        } catch (\Throwable $e) {
            // Audit darf Hauptaktion nicht blockieren – Fehler in Textlog notieren
            @file_put_contents($logFile, date('c', $ts) . " | system | audit_error | " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Prüft die Hash-Chain auf Manipulation.
     * @return array{ok:bool, total:int, broken_at:?int}
     */
    public static function verifyChain(): array
    {
        $db = Database::connect();
        $rows = $db->query("SELECT id, ts, username, action, details, prev_hash, hash FROM audit_log ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $prev = str_repeat('0', 64);
        $pepper = self::pepper();
        foreach ($rows as $r) {
            if ($r['prev_hash'] !== $prev) {
                return ['ok' => false, 'total' => count($rows), 'broken_at' => (int)$r['id']];
            }
            $payload = $prev . '|' . $r['ts'] . '|' . $r['username'] . '|' . $r['action'] . '|' . $r['details'];
            $expected = hash_hmac('sha256', $payload, $pepper);
            if (!hash_equals($expected, $r['hash'])) {
                return ['ok' => false, 'total' => count($rows), 'broken_at' => (int)$r['id']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'total' => count($rows), 'broken_at' => null];
    }
}
