<?php
declare(strict_types=1);

namespace App\Console\Command;

use App\Database;
use App\Services\ZeitErinnerung;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'zeit:erinnerung', description: 'Prüft fehlende Stundenbuchungen aus der Wochenplanung')]
final class ZeitErinnerungCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('datum', null, InputOption::VALUE_REQUIRED, 'Prüfdatum im Format JJJJ-MM-TT')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Tagessperre überspringen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $datumOption = $input->getOption('datum');
        $datum = is_string($datumOption) && $datumOption !== ''
            ? $datumOption
            : (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        if (!$this->istGueltigesDatum($datum)) {
            $output->writeln('<error>Ungültiges Datum, erwartet JJJJ-MM-TT.</error>');
            return Command::INVALID;
        }

        $db = Database::connect();
        $settings = $this->erinnerungSettings($db);
        if (($settings['stunden_aktiv'] ?? true) === false) {
            $this->log('Stunden-Erinnerung ist deaktiviert.');
            $output->writeln('Stunden-Erinnerung ist deaktiviert.');
            return Command::SUCCESS;
        }

        $force = (bool)$input->getOption('force');
        $hasDatum = is_string($datumOption) && $datumOption !== '';
        $lockFile = DATA_DIR . 'stunden_erinnerung_last.txt';
        if (!$force && !$hasDatum && is_file($lockFile) && trim((string)file_get_contents($lockFile)) === $datum) {
            $this->log("Für {$datum} bereits geprüft.");
            $output->writeln("Für {$datum} bereits geprüft.");
            return Command::SUCCESS;
        }

        $this->log('=== Stunden-Erinnerung gestartet' . ($force ? ' [FORCE]' : '') . ' ===');
        $this->log('Pruefe Stunden fuer: ' . $datum);

        $result = ZeitErinnerung::pruefe($db, $datum);
        $result['erstellt'] = date('Y-m-d H:i:s');
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents(DATA_DIR . 'stunden_erinnerung_result.json', $json !== false ? $json : '{}');

        foreach ($result['fehlend'] as $row) {
            $baustellen = isset($row['baustellen']) && is_array($row['baustellen'])
                ? array_map('strval', $row['baustellen'])
                : [];
            $this->log('  ' . (string)($row['username'] ?? '') . ': KEINE Stunden fuer ' . $datum . ' (geplant: ' . implode(', ', $baustellen) . ')');
        }
        if ($result['fehlend'] === []) {
            $this->log('Alle eingeplanten Benutzer haben Stunden gebucht.');
        } else {
            $this->log(count($result['fehlend']) . ' Benutzer ohne Stundenbuchung fuer ' . $datum . '.');
        }
        $this->log('=== Fertig: ' . count($result['fehlend']) . " fehlende Buchung(en) ===\n");

        if (!$hasDatum) {
            file_put_contents($lockFile, $datum);
        }
        $compactJson = json_encode($result, JSON_UNESCAPED_UNICODE);
        $output->writeln($compactJson !== false ? $compactJson : '{}');
        return Command::SUCCESS;
    }

    private function istGueltigesDatum(string $datum): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $datum;
    }

    /** @return array<string,mixed> */
    private function erinnerungSettings(\PDO $db): array
    {
        $stmt = $db->query('SELECT data FROM erinnerung_settings WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch() : false;
        $settings = is_array($row) ? json_decode((string)$row['data'], true) : [];
        return is_array($settings) ? $settings : [];
    }

    private function log(string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        $line = '[' . $ts . '] ' . $msg . "\n";
        echo $line;
        file_put_contents(DATA_DIR . 'erinnerung.log', $line, FILE_APPEND);
    }
}
