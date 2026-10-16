<?php
namespace App\Console\Command;

use App\Database;
use App\Services\Jobs\Planer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Jede Minute per Crontab aufgerufen (Dockerfile.app). Sperrt sich selbst gegen
 * überlappende Läufe (LOCK_NB) – bei bereits laufendem Planer Exit 0 ohne Aktion.
 */
#[AsCommand(name: 'system:planer', description: 'Tageslauf-Planer: Serversicherung, Herzschlag, Aufträge, Protokollpflege')]
final class SystemPlanerCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir) && !mkdir($runDir, 0750, true) && !is_dir($runDir)) {
            $output->writeln('<error>Sperrverzeichnis konnte nicht erstellt werden.</error>');
            return Command::FAILURE;
        }
        $lock = fopen($runDir . '/planer.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('Planer läuft bereits – übersprungen.');
            return Command::SUCCESS;
        }
        try {
            $db = Database::connect();
            $zeilen = (new Planer())->lauf($db, new \DateTimeImmutable());
            foreach ($zeilen as $zeile) {
                $output->writeln($zeile);
            }
            if ($zeilen === []) {
                $output->writeln('Kein fälliger Job.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return Command::SUCCESS;
    }
}
