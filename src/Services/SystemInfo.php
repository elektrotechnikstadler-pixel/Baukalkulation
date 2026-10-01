<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ModuleLoader;
use App\Database\Migrator;

/**
 * Anzeigedaten für das Update-Fenster. Nur lesend, treiberneutral; liefert weder Pfade noch Secrets.
 */
final class SystemInfo
{
    private const EXTENSIONS = ['pdo_sqlite', 'pdo_pgsql', 'sodium', 'zip', 'gd', 'mbstring'];

    public function __construct(
        private \PDO $db,
        private string $datenVerzeichnis,
        private string $sicherungsVerzeichnis,
        private int $dateiObergrenze = 50000,
    ) {
    }

    /** @return array<string, mixed> */
    public function sammeln(): array
    {
        $extensions = [];
        foreach (self::EXTENSIONS as $ext) {
            $extensions[$ext] = extension_loaded($ext);
        }
        $offen = 0;
        foreach (Migrator::status($this->db) as $migration) {
            $offen += $migration['applied'] ? 0 : 1;
        }

        return [
            'version'          => self::version(),
            'schema'           => [
                'aktuell' => Migrator::currentVersion($this->db),
                'neueste' => Migrator::latestVersion(),
                'offen'   => $offen,
            ],
            'db_treiber'       => (string) $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME),
            'php'              => ['version' => PHP_VERSION, 'extensions' => $extensions],
            'betriebsart'      => is_file('/.dockerenv') ? 'docker' : 'klassisch',
            'letzte_sicherung' => $this->letzteSicherung(),
            'datenverzeichnis' => $this->datenverzeichnis(),
            'lizenz'           => LicenseService::getPublicInfo(),
            'module'           => $this->module(),
        ];
    }

    private static function version(): string
    {
        $datei = dirname(__DIR__, 2) . '/VERSION';
        $inhalt = is_file($datei) ? file_get_contents($datei) : false;
        return is_string($inhalt) ? trim($inhalt) : '';
    }

    /** @return array{name: string, zeit: string}|null neuester Sicherungsordner nach Name */
    private function letzteSicherung(): ?array
    {
        if (!is_dir($this->sicherungsVerzeichnis)) {
            return null;
        }
        $basis = rtrim($this->sicherungsVerzeichnis, '/\\') . DIRECTORY_SEPARATOR;
        $neueste = null;
        foreach (scandir($basis) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($basis . $name)) {
                continue;
            }
            if ($neueste === null || strcmp($name, $neueste) > 0) {
                $neueste = $name;
            }
        }
        if ($neueste === null) {
            return null;
        }
        $mtime = filemtime($basis . $neueste);
        return ['name' => $neueste, 'zeit' => date('c', $mtime === false ? 0 : $mtime)];
    }

    /** @return array{dateien: int, bytes: int, begrenzt: bool} */
    private function datenverzeichnis(): array
    {
        $ergebnis = ['dateien' => 0, 'bytes' => 0, 'begrenzt' => false];
        if (!is_dir($this->datenVerzeichnis)) {
            return $ergebnis;
        }
        try {
            $dateien = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->datenVerzeichnis, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($dateien as $datei) {
                if (!$datei instanceof \SplFileInfo || !$datei->isFile()) {
                    continue;
                }
                if ($ergebnis['dateien'] >= $this->dateiObergrenze) {
                    $ergebnis['begrenzt'] = true;
                    break;
                }
                $ergebnis['dateien']++;
                $ergebnis['bytes'] += (int) $datei->getSize();
            }
        } catch (\Throwable $e) {
            error_log('[SystemInfo] Datenverzeichnis nicht vollständig lesbar: ' . $e->getMessage());
        }
        return $ergebnis;
    }

    /** @return list<array{name: string, title: string, version: string}> */
    private function module(): array
    {
        $module = [];
        foreach ((new ModuleLoader($this->db))->listAll() as $m) {
            $module[] = [
                'name'    => (string) $m['name'],
                'title'   => (string) ($m['title'] ?? $m['name']),
                'version' => (string) ($m['version'] ?? '0.0.0'),
            ];
        }
        return $module;
    }
}
