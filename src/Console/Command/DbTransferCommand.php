<?php
namespace App\Console\Command;

use App\Core\ModuleLoader;
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

        $sqlite = ConnectionConfig::openSqlite($sqlitePath);
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

        if (!$input->getOption('force') && (int)$dst->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            $output->writeln('<error>Das Ziel enthält bereits Daten. Mit --force überschreiben.</error>');
            return Command::FAILURE;
        }

        $dstDialect = Dialect::for($dst);
        $tables = [];
        foreach (Dialect::for($src)->tableNames($src) as $table) {
            if ($table === Migrator::TABLE) continue;
            // Nach SQLite nur bekannte Tabellen; nach PostgreSQL legt createMissingTables() fehlende an.
            if ($direction === 'to-sqlite' && !$dstDialect->tableExists($dst, $table)) {
                $output->writeln("<comment>Übersprungen (im Ziel unbekannt): {$table}</comment>");
                continue;
            }
            $tables[] = $table;
        }

        $copier = new TableCopier($src, $dst);
        $dstDialect->beforeBulkImport($dst);
        Dialect::for($src)->beginSnapshot($src);
        $dst->beginTransaction();
        try {
            $dstDialect->relaxForeignKeys($dst);
            if ($direction === 'to-pgsql') $copier->createMissingTables($tables);
            $counts = $copier->copyAll($tables);
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
        foreach ($counts as $table => $count) {
            $n = (int)$dst->query('SELECT COUNT(*) FROM ' . Dialect::quoteIdentifier($table))->fetchColumn();
            $mismatch = $mismatch || $n !== $count;
            $rows[] = [$table, $count, $n === $count ? 'ok' : "ABWEICHUNG ({$n})"];
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
