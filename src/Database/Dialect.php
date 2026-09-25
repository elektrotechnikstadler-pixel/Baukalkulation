<?php
namespace App\Database;

/**
 * Unterschiede zwischen SQLite und PostgreSQL an einer Stelle.
 * Der übrige Code schreibt SQL im gemeinsamen Dialekt (bzw. im bisherigen SQLite-Stil,
 * den PgsqlDialect::translate() überträgt).
 */
abstract class Dialect
{
    public static function for(\PDO $pdo): self
    {
        return match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'pgsql'  => new PgsqlDialect(),
            'sqlite' => new SqliteDialect(),
            default  => throw new \RuntimeException('Nicht unterstützter Datenbanktreiber.'),
        };
    }

    /** Überträgt SQL im SQLite-Stil in diesen Dialekt. */
    public function translate(string $sql): string
    {
        return $sql;
    }

    /** @return list<string> */
    abstract public function tableNames(\PDO $pdo): array;

    /** @return array<string, 'int'|'float'|'text'> Spaltenname => grober Typ, in Tabellenreihenfolge */
    abstract public function columnTypes(\PDO $pdo, string $table): array;

    /** @return list<string> */
    public function columns(\PDO $pdo, string $table): array
    {
        return array_keys($this->columnTypes($pdo, $table));
    }

    public function tableExists(\PDO $pdo, string $table): bool
    {
        return in_array($table, $this->tableNames($pdo), true);
    }

    /** Aggregiert Texte einer Gruppe; $separator ist ein SQL-Stringliteral. */
    abstract public function groupConcat(string $expr, string $separator): string;

    /** Schreibtransaktion, die sofort gegen parallele Schreiber sperrt (z. B. fortlaufende Nummern). */
    abstract public function beginExclusive(\PDO $pdo, string $lockName): void;

    /** Lesetransaktion mit gleichbleibendem Datenstand, z. B. für Sicherungen und Übertragungen. */
    public function beginSnapshot(\PDO $pdo): void
    {
        $pdo->beginTransaction();
    }

    /** Fremdschlüssel für einen Massenimport lockern; innerhalb der Transaktion aufrufen. */
    abstract public function relaxForeignKeys(\PDO $pdo): void;

    /** Nach einem Massenimport: verletzte Fremdschlüssel zählen (vor dem Commit). */
    abstract public function foreignKeyViolations(\PDO $pdo): int;

    /** Vor dem Massenimport, außerhalb der Transaktion (SQLite kann FKs nur dort abschalten). */
    public function beforeBulkImport(\PDO $pdo): void {}

    public function afterBulkImport(\PDO $pdo): void {}

    /** Identity-Zähler nach dem Einfügen expliziter IDs nachziehen. */
    public function resetSequences(\PDO $pdo, array $tables): void {}

    /** Einmalige Vorbereitung vor Migrationen (z. B. Erweiterungen). */
    public function prepareSchema(\PDO $pdo): void {}

    public static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
