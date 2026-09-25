<?php
namespace App\Database;

/**
 * Kopiert Tabelleninhalte zwischen zwei Verbindungen (SQLite ↔ PostgreSQL).
 * Übernommen werden nur Spalten, die es in Quelle und Ziel gibt.
 *
 * SQLite speichert Werte typlos (z. B. '' in INTEGER-Spalten); für PostgreSQL werden
 * sie passend zum Spaltentyp umgewandelt: nicht-numerische Werte in Zahlenspalten
 * werden NULL bzw. 0 bei NOT NULL.
 */
final class TableCopier
{
    private readonly Dialect $srcDialect;
    private readonly Dialect $dstDialect;

    public function __construct(private readonly \PDO $src, private readonly \PDO $dst)
    {
        $this->srcDialect = Dialect::for($src);
        $this->dstDialect = Dialect::for($dst);
    }

    public function clear(string $table): void
    {
        $this->dst->exec('DELETE FROM ' . Dialect::quoteIdentifier($table));
    }

    /** Fügt alle Zeilen der Quelltabelle ins Ziel ein; gibt die Anzahl zurück. */
    public function copyRows(string $table): int
    {
        $types = $this->dstDialect->columnTypes($this->dst, $table);
        $cols = array_values(array_intersect($this->srcDialect->columns($this->src, $table), array_keys($types)));
        if (!$cols) return 0;

        $convert = $this->dstDialect instanceof PgsqlDialect;
        $notNull = $convert ? $this->dstDialect->notNullColumns($this->dst, $table) : [];
        $colTypes = array_map(static fn(string $c): string => $types[$c], $cols);
        $colNotNull = array_map(static fn(string $c): bool => in_array($c, $notNull, true), $cols);

        $qt = Dialect::quoteIdentifier($table);
        $list = implode(', ', array_map([Dialect::class, 'quoteIdentifier'], $cols));
        $insert = $this->dst->prepare("INSERT INTO {$qt} ({$list}) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $count = 0;
        foreach ($this->src->query("SELECT {$list} FROM {$qt}", \PDO::FETCH_NUM) as $row) {
            if ($convert) {
                foreach ($row as $i => $v) {
                    $row[$i] = self::convert($v, $colTypes[$i], $colNotNull[$i]);
                }
            }
            $insert->execute($row);
            $count++;
        }
        return $count;
    }

    /** Identity-Zähler im Ziel nach dem Kopieren nachziehen. */
    public function finish(array $tables): void
    {
        $this->dstDialect->resetSequences($this->dst, $tables);
    }

    private static function convert(mixed $v, string $type, bool $notNull): mixed
    {
        if ($v === null) return null;
        if ($type === 'text') {
            // PostgreSQL-Text darf kein NUL-Byte enthalten.
            return is_string($v) ? str_replace("\0", '', $v) : (string)$v;
        }
        if (is_string($v)) $v = trim($v);
        if (!is_numeric($v)) return $notNull ? 0 : null;
        if ($type === 'float') return (float)$v;
        return is_int($v) || preg_match('/^-?\d+$/', (string)$v) ? (int)$v : (int)round((float)$v);
    }
}
