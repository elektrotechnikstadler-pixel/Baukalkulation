<?php
namespace App\Console\Command;

use App\Backup\BackupArchive;
use App\Backup\ImportConflictException;
use App\Backup\Importer;
use App\Backup\InvalidBackupException;
use App\Backup\RestoreService;
use App\Database;
use App\Services\AuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'backup:import', description: 'Sicherung einspielen (ZIP, Sicherungsordner oder alte JSON-Tagessicherung)')]
final class BackupImportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('quelle', InputArgument::REQUIRED, 'Pfad zu ZIP, Sicherungsordner oder JSON-Datei')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'merge oder replace (fehlende Baustellen archivieren, Zeit-Historie spiegeln)', Importer::MODE_MERGE)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Passwort für verschlüsselte ZIPs (alternativ Env BK_BACKUP_PASSWORD)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Ohne Rückfrage einspielen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = (string)$input->getArgument('quelle');
        $mode   = (string)$input->getOption('mode');
        if (!in_array($mode, [Importer::MODE_MERGE, Importer::MODE_REPLACE], true)) {
            $output->writeln('<error>--mode muss merge oder replace sein.</error>');
            return Command::INVALID;
        }

        try {
            $archive = match (true) {
                is_dir($source)                                          => BackupArchive::fromDir($source),
                strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'json' => BackupArchive::fromJsonFile($source),
                is_file($source)                                         => BackupArchive::fromZip($source, $input->getOption('password') ?? (getenv('BK_BACKUP_PASSWORD') ?: null)),
                default => throw new InvalidBackupException("Quelle nicht gefunden: {$source}"),
            };
        } catch (InvalidBackupException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $count = count($archive->data()['baustellen']);
            $output->writeln("Sicherung: Format {$archive->format}, App-Version " . ($archive->appVersion() ?: 'unbekannt') . ", {$count} Baustellen, Modus {$mode}");
            if (!$input->getOption('yes')) {
                $question = new ConfirmationQuestion('Aktuellen Datenbestand ersetzen? Vorher wird eine Sicherheitskopie angelegt. [j/N] ', false, '/^(j|y)/i');
                if (!(new QuestionHelper())->ask($input, $output, $question)) {
                    $output->writeln('Abgebrochen.');
                    return Command::FAILURE;
                }
            }
            $res = (new RestoreService(Database::connect(), 'cli'))->restore($archive, $mode, 'cli');
        } catch (ImportConflictException|InvalidBackupException $e) {
            $output->writeln('<error>Abgebrochen: ' . $e->getMessage() . ' Es wurden KEINE Daten verändert.</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Wiederherstellung abgebrochen: ' . $e->getMessage() . ' Es wurden KEINE Daten verändert.</error>');
            return Command::FAILURE;
        } finally {
            $archive->cleanup();
        }

        AuditService::log('backup_import_cli', "Sicherung eingespielt ({$mode}): {$source}, Sicherheitskopie=" . $res['safetyBackup']);
        foreach ($res['import']['tables'] as $table => $rows) {
            $output->writeln(sprintf('  %-32s %6d', $table, $rows), OutputInterface::VERBOSITY_VERBOSE);
        }
        if ($res['import']['skipped']) {
            $output->writeln('<comment>Übersprungen: ' . implode(', ', $res['import']['skipped']) . '</comment>');
        }
        if ($res['archived'] !== null) {
            $output->writeln("Ins Archiv verschoben: {$res['archived']} Baustelle(n)");
        }
        $output->writeln('<info>Eingespielt. Sicherheitskopie des vorherigen Standes: ' . $res['safetyBackup'] . '</info>');
        return Command::SUCCESS;
    }
}
