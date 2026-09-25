<?php
namespace App\Console\Command;

use App\Backup\BackupArchive;
use App\Backup\BackupWriter;
use App\Database;
use App\DataService;
use App\Handlers\DataActions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:create', description: 'Sicherung (Format 2) in BACKUP_DIR anlegen, optional als ZIP')]
final class BackupCreateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Bezeichnung der Sicherung', '')
            ->addOption('zip', null, InputOption::VALUE_REQUIRED, 'Zusätzlich als ZIP an diesen Pfad schreiben')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'ZIP mit AES-256 verschlüsseln (alternativ Env BK_BACKUP_PASSWORD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = Database::connect();
        $dirName = DataActions::backupDirName((string)$input->getOption('name'));
        try {
            $manifest = BackupWriter::writeDir($db, BACKUP_DIR . $dirName);
        } catch (\Throwable $e) {
            BackupArchive::removeDir(BACKUP_DIR . $dirName . '/');
            $output->writeln('<error>Sicherung fehlgeschlagen: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        DataService::pruneBackupCategory('manual', BACKUP_MAX * 2);
        $output->writeln("Sicherung angelegt: {$dirName} (Schema {$manifest['schemaVersion']})");

        $zip = $input->getOption('zip');
        if (is_string($zip) && $zip !== '') {
            $password = $input->getOption('password') ?? (getenv('BK_BACKUP_PASSWORD') ?: null);
            BackupWriter::zipDir(BACKUP_DIR . $dirName, $zip, $password);
            $output->writeln('ZIP geschrieben: ' . $zip . ($password ? ' (AES-256 verschlüsselt)' : ''));
        }
        return Command::SUCCESS;
    }
}
