<?php
namespace App\Database;

final class SqliteDialect extends Dialect
{
    public function tableNames(\PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $types = [];
        foreach ($pdo->query('PRAGMA table_info(' . self::quoteIdentifier($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            $t = strtoupper((string)$col['type']);
            $types[$col['name']] = str_contains($t, 'INT') ? 'int' : (preg_match('/REAL|FLOA|DOUB|NUM/', $t) ? 'float' : 'text');
        }
        return $types;
    }

    public function groupConcat(string $expr, string $separator): string
    {
        return "GROUP_CONCAT({$expr}, {$separator})";
    }

    public function beginExclusive(\PDO $pdo, string $lockName): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
    }

    public function beforeBulkImport(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
    }

    public function afterBulkImport(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function relaxForeignKeys(\PDO $pdo): void {}

    public function foreignKeyViolations(\PDO $pdo): int
    {
        return count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
    }
}
