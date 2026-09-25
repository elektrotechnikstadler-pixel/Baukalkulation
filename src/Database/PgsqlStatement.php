<?php
namespace App\Database;

/**
 * Gleicht PostgreSQL an das Verhalten der App unter SQLite an:
 *  - PHP-Booleans werden als 0/1 gebunden (Spalten sind ganzzahlig wie unter SQLite).
 *  - Gleitkomma-/numeric-Spalten kommen als float bzw. int statt als String zurück
 *    (pdo_pgsql liefert sie sonst als "1.5"; im Browser würde daraus Text statt Zahl).
 */
class PgsqlStatement extends \PDOStatement
{
    /** @var array<int,array{0:string,1:bool}>|null Spaltenindex => [Name, immer float] */
    private ?array $numericColumns = null;

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            foreach ($params as $k => $v) {
                if (is_bool($v)) $params[$k] = (int)$v;
            }
        }
        $this->numericColumns = null;
        return parent::execute($params);
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->castRow(parent::fetch($mode, $cursorOrientation, $cursorOffset));
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if ($mode === \PDO::FETCH_COLUMN) {
            $col = $this->numericColumns()[(int)($args[0] ?? 0)] ?? null;
            return $col !== null ? array_map(fn($v) => self::castValue($v, $col[1]), $rows) : $rows;
        }
        return $this->numericColumns() ? array_map([$this, 'castRow'], $rows) : $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $value = parent::fetchColumn($column);
        $col = $this->numericColumns()[$column] ?? null;
        return $col !== null ? self::castValue($value, $col[1]) : $value;
    }

    public function getIterator(): \Iterator
    {
        foreach (parent::getIterator() as $key => $row) {
            yield $key => $this->castRow($row);
        }
    }

    private function castRow(mixed $row): mixed
    {
        if (!is_array($row)) return $row;
        foreach ($this->numericColumns() as $i => [$name, $float]) {
            if (array_key_exists($name, $row)) $row[$name] = self::castValue($row[$name], $float);
            if (array_key_exists($i, $row)) $row[$i] = self::castValue($row[$i], $float);
        }
        return $row;
    }

    /** @return array<int,array{0:string,1:bool}> */
    private function numericColumns(): array
    {
        if ($this->numericColumns !== null) return $this->numericColumns;
        $cols = [];
        for ($i = 0, $n = $this->columnCount(); $i < $n; $i++) {
            $meta = $this->getColumnMeta($i);
            $type = $meta === false ? '' : (string)($meta['native_type'] ?? '');
            if (in_array($type, ['float4', 'float8', 'numeric'], true)) {
                $cols[$i] = [(string)$meta['name'], $type !== 'numeric'];
            }
        }
        return $this->numericColumns = $cols;
    }

    /** float4/float8 → float (wie SQLite REAL); numeric (z. B. SUM ganzer Zahlen) → int, falls ganzzahlig. */
    private static function castValue(mixed $v, bool $float): mixed
    {
        if (!is_string($v) || !is_numeric($v)) return $v;
        return !$float && preg_match('/^-?\d+$/', $v) ? (int)$v : (float)$v;
    }
}
