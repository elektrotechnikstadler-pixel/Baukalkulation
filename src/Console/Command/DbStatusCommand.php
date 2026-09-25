<?php
namespace App\Console\Command;

use App\Database;
use App\Database\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:status', description: 'Ausgeführte und offene Migrationen anzeigen')]
final class DbStatusCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = Database::open(DATA_DIR . 'database.sqlite');
        $rows = array_map(
            fn(array $r) => [$r['version'], $r['name'], $r['applied'] ? 'ausgeführt' : 'offen'],
            Migrator::status($db),
        );
        (new Table($output))->setHeaders(['Version', 'Migration', 'Status'])->setRows($rows)->render();

        $current = Migrator::currentVersion($db);
        $latest  = Migrator::latestVersion();
        if ($current > $latest) {
            $output->writeln("<error>Datenbank ({$current}) ist neuer als der Code ({$latest}).</error>");
            return Command::FAILURE;
        }
        return $current === $latest ? Command::SUCCESS : 2;
    }
}
