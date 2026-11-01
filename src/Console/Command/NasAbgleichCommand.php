<?php
namespace App\Console\Command;

use App\Database;
use App\Services\Jobs\NasAbgleich;
use App\Services\Jobs\NasJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Jede Minute per Crontab aufgerufen (Dockerfile.app) – eigener Prozess statt Planer-Schritt
 * (T4-Vertrag Punkt 5, AP-20261012-datensicherung-nas): ein NAS-Lauf kann Stunden dauern und
 * darf Planer/Überwachung/Watchdog nicht blockieren. Eigene Sperre (LOCK_NB); ist sie belegt,
 * endet der Befehl mit Exit 0 ohne Aktion.
 */
#[AsCommand(name: 'nas:abgleich', description: 'NAS-Abgleich per rclone (eigener Prozess, siehe AP-20261012-datensicherung-nas T4)')]
final class NasAbgleichCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir) && !mkdir($runDir, 0750, true) && !is_dir($runDir)) {
            $output->writeln('<error>Sperrverzeichnis konnte nicht erstellt werden.</error>');
            return Command::FAILURE;
        }
        $lock = fopen($runDir . '/nas.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('NAS-Abgleich läuft bereits – übersprungen.');
            return Command::SUCCESS;
        }
        try {
            $db = Database::connect();
            $zeile = (new NasJob($db))->lauf(new NasAbgleich($db));
            $output->writeln($zeile ?? 'Kein fälliger Job.');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return Command::SUCCESS;
    }
}
