<?php
namespace App\Console\Command;

use App\Core\ModuleLoader;
use App\Database;
use App\Database\ConnectionConfig;
use App\Database\Dialect;
use App\Database\Migrator;
use App\Database\TableCopier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Überträgt den kompletten Datenbestand zwischen SQLite und PostgreSQL
 * (Umstieg und Rückweg). Beide Seiten werden vorher auf den aktuellen Schema-Stand gebracht.
 */
#[AsCommand(name: 'db:transfer', description: 'Datenbestand zwischen SQLite und PostgreSQL übertragen')]
final class DbTransferCommand extends Command
{
    private const SKIP = [Migrator::TABLE, 'sqlite_sequence'];

    protected function configure(): void
    {
        $this
            ->addArgument('richtung', InputArgument::REQUIRED, 'to-pgsql (SQLite → PostgreSQL) oder to-sqlite (PostgreSQL → SQLite)')
            ->addOption('sqlite', null, InputOption::VALUE_REQUIRED, 'SQLite-Datei (Standard: DATA_DIR/database.sqlite)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Vorhandene Daten im Ziel überschreiben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $direction = (string)$input->getArgument('richtung');
        if (!in_array($direction, ['to-pgsql', 'to-sqlite'], true)) {
            $output->writeln('<error>Richtung muss to-pgsql oder to-sqlite sein.</error>');
            return Command::INVALID;
        }
        $sqlitePath = (string)($input->getOption('sqlite') ?: DATA_DIR . 'database.sqlite');
        if ($direction === 'to-pgsql' && !is_file($sqlitePath)) {
            $output->writeln("<error>SQLite-Datei nicht gefunden: {$sqlitePath}</error>");
            return Command::FAILURE;
        }

        $sqlite = Database::open($sqlitePath);
        $pgsql  = ConnectionConfig::openPgsql();
        [$src, $dst] = $direction === 'to-pgsql' ? [$sqlite, $pgsql] : [$pgsql, $sqlite];

        if ($direction === 'to-pgsql' && strtolower((string)$sqlite->query('PRAGMA integrity_check')->fetchColumn()) !== 'ok') {
            $output->writeln('<error>Integritätsprüfung der SQLite-Datei fehlgeschlagen.</error>');
            return Command::FAILURE;
        }
        foreach ([$src, $dst] as $db) {
            if (Migrator::currentVersion($db) > Migrator::latestVersion()) {
                $output->writeln('<error>Eine Datenbank hat ein neueres Schema als dieser Code. Abbruch.</error>');
                return Command::FAILURE;
            }
            Migrator::migrate($db, $db === $sqlite ? null : DATA_DIR . 'migrate.lock');
            (new ModuleLoader($db))->migrateAll(false);
        }

        $dstDialect = Dialect::for($dst);
        if (!$input->getOption('force') && (int)$dst->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            $output->writeln('<error>Das Ziel enthält bereits Daten. Mit --force überschreiben.</error>');
            return Command::FAILURE;
        }

        $srcTables = array_values(array_diff(Dialect::for($src)->tableNames($src), self::SKIP));
        $tables = [];
        foreach ($srcTables as $table) {
            if ($dstDialect->tableExists($dst, $table)) {
                $tables[] = $table;
            } elseif ($direction === 'to-pgsql' && preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                $ddl = (string)$sqlite->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $sqlite->quote($table))->fetchColumn();
                if (!preg_match('/^\s*CREATE TABLE\b/i', $ddl) || str_contains($ddl, ';')) {
                    $output->writeln("<comment>Übersprungen (unbekannte Definition): {$table}</comment>");
                    continue;
                }
                $dst->exec($ddl);
                $tables[] = $table;
            } else {
                $output->writeln("<comment>Übersprungen (im Ziel unbekannt): {$table}</comment>");
            }
        }

        $copier = new TableCopier($src, $dst);
        $counts = [];
        $dstDialect->beforeBulkImport($dst);
        $src->beginTransaction();
        if ($src === $pgsql) $src->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $dst->beginTransaction();
        try {
            $dstDialect->relaxForeignKeys($dst);
            foreach ($tables as $table) {
                $copier->clear($table);
            }
            foreach ($tables as $table) {
                $counts[$table] = $copier->copyRows($table);
            }
            $copier->finish($tables);
            if ($violations = $dstDialect->foreignKeyViolations($dst)) {
                throw new \RuntimeException("{$violations} ungültige Verknüpfungen im Ziel.");
            }
            $dst->commit();
        } catch (\Throwable $e) {
            if ($dst->inTransaction()) $dst->rollBack();
            $output->writeln('<error>Übertragung abgebrochen, Ziel unverändert: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            if ($src->inTransaction()) $src->commit();
            $dstDialect->afterBulkImport($dst);
        }

        $rows = [];
        $mismatch = false;
        foreach ($tables as $table) {
            $q = 'SELECT COUNT(*) FROM ' . Dialect::quoteIdentifier($table);
            $n = (int)$dst->query($q)->fetchColumn();
            $ok = $n === $counts[$table] && $n === (int)$src->query($q)->fetchColumn();
            $mismatch = $mismatch || !$ok;
            $rows[] = [$table, $counts[$table], $ok ? 'ok' : "ABWEICHUNG ({$n})"];
        }
        (new Table($output))->setHeaders(['Tabelle', 'Zeilen', 'Prüfung'])->setRows($rows)->render();
        if ($mismatch) {
            $output->writeln('<error>Zeilenzahlen weichen ab.</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Übertragung abgeschlossen: ' . count($tables) . ' Tabellen, ' . array_sum($counts) . ' Zeilen.</info>');
        if ($direction === 'to-pgsql') {
            $output->writeln('Jetzt BK_DB_DRIVER=pgsql setzen und die App neu starten. Die SQLite-Datei bleibt als Rückfallebene erhalten.');
        }
        return Command::SUCCESS;
    }
}
