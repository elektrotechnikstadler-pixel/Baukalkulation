<?php
namespace App\Database;

/**
 * PDO mit Dialekt-Übersetzung: Der App-Code schreibt SQL wie bisher, unter PostgreSQL
 * übersetzt der Dialekt es vor dem Ausführen.
 */
final class Connection extends \PDO
{
    public readonly Dialect $dialect;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options + [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->dialect = Dialect::for($this);
        if ($this->dialect instanceof PgsqlDialect) {
            $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [PgsqlStatement::class, []]);
        }
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($this->dialect->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $query = $this->dialect->translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->dialect->translate($statement));
    }
}
