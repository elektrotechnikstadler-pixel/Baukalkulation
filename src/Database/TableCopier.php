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

    /**
     * Ersetzt den Inhalt der Zieltabellen durch den der Quelle.
     * @param list<string> $tables
     * @return array<string,int> Zeilen je Tabelle
     */
    public function copyAll(array $tables): array
    {
        // Erst alles leeren, dann füllen: ON DELETE CASCADE darf bereits kopierte Zeilen nicht treffen.
        foreach ($tables as $table) {
            $this->dst->exec('DELETE FROM ' . Dialect::quoteIdentifier($table));
        }
        $counts = [];
        foreach ($tables as $table) {
            try {
                $counts[$table] = $this->copyRows($table);
            } catch (\PDOException $e) {
                throw new \RuntimeException("Tabelle {$table} konnte nicht übernommen werden: " . $e->getMessage(), 0, $e);
            }
        }
        $this->dstDialect->resetSequences($this->dst, $tables);
        return $counts;
    }

    /**
     * Legt im Ziel fehlende Tabellen nach der Definition der SQLite-Quelle an –
     * referenzierte Tabellen zuerst, weil PostgreSQL Fremdschlüssel sofort prüft.
     * @param list<string> $tables
     */
    public function createMissingTables(array $tables): void
    {
        $ddl = [];
        foreach ($tables as $table) {
            if ($this->dstDialect->tableExists($this->dst, $table)) continue;
            $sql = (string)$this->src->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $this->src->quote($table))->fetchColumn();
            if (!preg_match('/^\s*CREATE TABLE\b/i', $sql) || str_contains($sql, ';')) {
                throw new \RuntimeException("Unerwartete Tabellendefinition für {$table}.");
            }
            $ddl[$table] = $sql;
        }
        $done = [];
        $create = function (string $table, array $path) use (&$create, &$done, $ddl): void {
            if (isset($done[$table]) || isset($path[$table])) return;
            preg_match_all('/\bREFERENCES\s+"?([a-z][a-z0-9_]*)"?/i', $ddl[$table], $m);
            foreach ($m[1] as $dep) {
                if (isset($ddl[$dep])) $create($dep, $path + [$table => true]);
            }
            $this->dst->exec($ddl[$table]);
            $done[$table] = true;
        };
        foreach (array_keys($ddl) as $table) {
            $create($table, []);
        }
    }

    private function copyRows(string $table): int
    {
        $types = $this->dstDialect->columnTypes($this->dst, $table);
        $cols = array_values(array_intersect($this->srcDialect->columns($this->src, $table), array_keys($types)));
        if (!$cols) return 0;

        $convert = $this->dstDialect instanceof PgsqlDialect;
        $notNull = $convert ? array_flip($this->dstDialect->notNullColumns($this->dst, $table)) : [];

        $qt = Dialect::quoteIdentifier($table);
        $list = implode(', ', array_map([Dialect::class, 'quoteIdentifier'], $cols));
        $insert = $this->dst->prepare("INSERT INTO {$qt} ({$list}) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $count = 0;
        foreach ($this->src->query("SELECT {$list} FROM {$qt}", \PDO::FETCH_NUM) as $row) {
            if ($convert) {
                foreach ($cols as $i => $col) {
                    $row[$i] = self::convert($row[$i], $types[$col], isset($notNull[$col]));
                }
            }
            $insert->execute($row);
            $count++;
        }
        return $count;
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
