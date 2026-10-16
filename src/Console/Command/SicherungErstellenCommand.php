<?php
namespace App\Console\Command;

use App\Database;
use App\Services\Jobs\JobProtokoll;
use App\Services\Jobs\Tagessicherung;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Manueller Sofort-Lauf der Tagessicherung (Auslöser "konsole"), protokolliert wie der Planer. */
#[AsCommand(name: 'sicherung:erstellen', description: 'Tagessicherung sofort erstellen (protokolliert als Job "sicherung")')]
final class SicherungErstellenCommand extends Command
{
    /** Wie beim Planer: bis zu 30 s auf die Sperre warten statt sofort aufzugeben. */
    private const WARTE_SEKUNDEN = 30;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = Database::connect();
        $protokoll = new JobProtokoll($db);
        $laufId = $protokoll->start('sicherung', 'konsole');

        try {
            $ergebnis = Tagessicherung::erstelle($db, new \DateTimeImmutable(), self::WARTE_SEKUNDEN);
            if ($ergebnis === Tagessicherung::GESPERRT) {
                $protokoll->fehler($laufId, 'SICHERUNG_GESPERRT', 'Tagessicherung war durch einen anderen Vorgang gesperrt.');
                $output->writeln('<error>Tagessicherung war durch einen anderen Vorgang gesperrt.</error>');
                return Command::FAILURE;
            }
            $protokoll->erfolg($laufId, ['ergebnis' => $ergebnis]);
            $output->writeln("Tagessicherung: {$ergebnis}");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            error_log('[sicherung:erstellen] ' . $e->getMessage());
            $protokoll->fehler($laufId, 'SICHERUNG_FEHLER', 'Tagessicherung fehlgeschlagen.');
            $output->writeln('<error>Tagessicherung fehlgeschlagen.</error>');
            return Command::FAILURE;
        }
    }
}
