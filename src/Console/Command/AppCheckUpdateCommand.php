<?php
namespace App\Console\Command;

use App\Services\UpdatePruefung;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:check-update', description: 'Neueste Release-Version prüfen (Exit 0 aktuell, 2 Update verfügbar, 1 Fehler)')]
final class AppCheckUpdateCommand extends Command
{
    private const UPDATE_VERFUEGBAR = 2;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ergebnis = UpdatePruefung::ausUmgebung(DATA_DIR . 'update_check.json')->pruefen();

        $output->writeln('Aktuelle Version: ' . $ergebnis['aktuelle_version']);
        if ($ergebnis['neueste_version'] !== null) {
            $output->writeln('Neueste Version:  ' . $ergebnis['neueste_version'] . ($ergebnis['veroeffentlicht'] !== null ? ' (' . $ergebnis['veroeffentlicht'] . ')' : ''));
        }
        if ($ergebnis['link'] !== null) {
            $output->writeln('Release:          ' . $ergebnis['link']);
        }
        if ($ergebnis['status'] === 'update_verfuegbar') {
            $output->writeln('Update verfügbar: ' . $ergebnis['neueste_version']);
        } elseif ($ergebnis['status'] === 'aktuell') {
            $output->writeln('Version ist aktuell.');
        }
        if ($ergebnis['meldung'] !== null) {
            $output->writeln($ergebnis['meldung']);
        }

        return match ($ergebnis['status']) {
            'update_verfuegbar' => self::UPDATE_VERFUEGBAR,
            'aktuell', 'keine_releases' => Command::SUCCESS,
            default => Command::FAILURE,
        };
    }
}
