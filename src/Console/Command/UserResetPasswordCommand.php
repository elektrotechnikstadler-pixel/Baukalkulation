<?php
namespace App\Console\Command;

use App\Database;
use App\Services\AuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:reset-password', description: 'Passwort eines Benutzers neu setzen und Konto entsperren')]
final class UserResetPasswordCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('benutzer', InputArgument::REQUIRED, 'Benutzername, z. B. Systemadmin')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Neues Passwort (sonst verdeckte Abfrage)')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Keinen Passwortwechsel bei der nächsten Anmeldung erzwingen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = (string)$input->getArgument('benutzer');
        $password = $input->getOption('password');
        if (!is_string($password) || $password === '') {
            $question = (new Question('Neues Passwort: '))->setHidden(true)->setHiddenFallback(false);
            $password = (string)(new QuestionHelper())->ask($input, $output, $question);
        }
        if (mb_strlen($password) < 12) {
            $output->writeln('<error>Passwort mindestens 12 Zeichen.</error>');
            return Command::INVALID;
        }

        $stmt = Database::connect()->prepare(
            'UPDATE users SET password = ?, mustChangePassword = ?, isLocked = 0, failedLoginAttempts = 0, sessionInvalidatedAt = ? WHERE username = ?'
        );
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $input->getOption('keep') ? 0 : 1, time(), $username]);
        if ($stmt->rowCount() === 0) {
            $output->writeln("<error>Benutzer {$username} nicht gefunden.</error>");
            return Command::FAILURE;
        }
        AuditService::log('password_reset_cli', 'Benutzer=' . $username);
        $output->writeln("<info>Passwort für {$username} gesetzt, Konto entsperrt, bestehende Sitzungen beendet.</info>");
        return Command::SUCCESS;
    }
}
