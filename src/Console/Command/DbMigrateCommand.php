<?php
namespace App\Console\Command;

use App\Database\ConnectionConfig;
use App\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:migrate', description: 'Datenbankschema auf den Stand des Codes bringen')]
final class DbMigrateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = ConnectionConfig::open();
        $before = Migrator::currentVersion($db);
        $latest = Migrator::latestVersion();

        if ($before > $latest) {
            $output->writeln("<error>Datenbank-Schema {$before} ist neuer als der Code ({$latest}). Abbruch.</error>");
            return Command::FAILURE;
        }
        if ($before === $latest) {
            $output->writeln("Schema aktuell ({$latest}).");
            return Command::SUCCESS;
        }

        Migrator::migrate($db, DATA_DIR . 'migrate.lock', $output);
        $output->writeln('<info>Schema aktualisiert: ' . ($before ?: 'ohne Version') . " → {$latest}</info>");
        return Command::SUCCESS;
    }
}
