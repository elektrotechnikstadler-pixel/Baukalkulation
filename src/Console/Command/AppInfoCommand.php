<?php
namespace App\Console\Command;

use App\Database\ConnectionConfig;
use App\Services\SystemInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:info', description: 'Version, Schema und Systemdaten anzeigen (wie Update-Fenster)')]
final class AppInfoCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $info = (new SystemInfo(ConnectionConfig::open(), DATA_DIR, BACKUP_DIR))->sammeln();

        $extensions = [];
        foreach ($info['php']['extensions'] as $name => $geladen) {
            $extensions[] = $name . ($geladen ? ' ja' : ' nein');
        }
        $sicherung = $info['letzte_sicherung'];
        $daten = $info['datenverzeichnis'];
        $lizenz = $info['lizenz'];
        $module = array_map(static fn(array $m): string => $m['name'] . ' ' . $m['version'], $info['module']);

        (new Table($output))->setHeaders(['Feld', 'Wert'])->setRows([
            ['Version', $info['version']],
            ['Schema', sprintf('%d / %d (offen: %d)', $info['schema']['aktuell'], $info['schema']['neueste'], $info['schema']['offen'])],
            ['DB-Treiber', $info['db_treiber']],
            ['PHP', $info['php']['version']],
            ['Extensions', implode(', ', $extensions)],
            ['Betriebsart', $info['betriebsart']],
            ['Letzte Sicherung', $sicherung === null ? '–' : $sicherung['name'] . ' (' . $sicherung['zeit'] . ')'],
            ['Datenverzeichnis', sprintf('%s%d Dateien, %.1f MB', $daten['begrenzt'] ? '> ' : '', $daten['dateien'], $daten['bytes'] / 1048576)],
            ['Lizenz', $lizenz['tier'] . ($lizenz['expires'] !== null ? ' bis ' . $lizenz['expires'] : '')],
            ['Module', $module === [] ? '–' : implode(', ', $module)],
        ])->render();

        return Command::SUCCESS;
    }
}
