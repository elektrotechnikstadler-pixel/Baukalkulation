<?php
namespace App\Backup;

/**
 * Liest Sicherungen aller bisherigen Formate:
 *   2 = ZIP/Ordner mit manifest.json (+ baukalkulation.json, database.sqlite)
 *   1 = ZIP/Ordner mit baukalkulation.json und optional database.sqlite
 *   0 = einzelne JSON-Datei (sehr alte Tagessicherung)
 */
final class BackupArchive
{
    public const FILE_JSON     = 'baukalkulation.json';
    public const FILE_DB       = 'database.sqlite';
    public const FILE_MANIFEST = 'manifest.json';

    private const OPTIONAL_KEYS = ['kunden', 'rechnungen', 'pauschalen', 'stundenKatalog', 'materialKatalog'];

    private function __construct(
        public readonly int $format,
        private readonly array $snapshot,
        public readonly ?string $sqlitePath,
        public readonly array $manifest,
        private readonly ?string $tmpDir,
    ) {}

    public static function fromZip(string $zipPath, ?string $password = null): self
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new InvalidBackupException('ZIP konnte nicht geöffnet werden.');
        }
        $tmp = self::tempDir();
        try {
            if ($password !== null && $password !== '') {
                $zip->setPassword($password);
            }
            // Nur bekannte Dateien entpacken – keine Pfade aus dem Archiv übernehmen.
            foreach ([self::FILE_JSON, self::FILE_DB, self::FILE_MANIFEST] as $name) {
                $stat = $zip->statName($name);
                if ($stat === false) continue;
                $encrypted = ($stat['encryption_method'] ?? 0) !== \ZipArchive::EM_NONE;
                if ($encrypted && ($password === null || $password === '')) {
                    throw new InvalidBackupException('Die Sicherung ist verschlüsselt – bitte Passwort angeben.', true);
                }
                $content = $zip->getFromName($name);
                if ($content === false) {
                    throw $encrypted
                        ? new InvalidBackupException('Passwort falsch oder Sicherung beschädigt.', true)
                        : new InvalidBackupException("{$name} konnte nicht gelesen werden.");
                }
                file_put_contents($tmp . $name, $content);
            }
        } catch (\Throwable $e) {
            self::removeDir($tmp);
            throw $e;
        } finally {
            $zip->close();
        }
        return self::fromFiles($tmp, $tmp);
    }

    /** Gespeicherte Sicherung in BACKUP_DIR (Ordner). */
    public static function fromDir(string $dir): self
    {
        return self::fromFiles(rtrim($dir, '/\\') . '/', null);
    }

    /** Sehr alte Tagessicherung als einzelne JSON-Datei. */
    public static function fromJsonFile(string $file): self
    {
        return new self(0, self::decodeSnapshot($file), null, [], null);
    }

    private static function fromFiles(string $dir, ?string $tmpDir): self
    {
        try {
            $manifest = [];
            if (is_file($dir . self::FILE_MANIFEST)) {
                $manifest = json_decode((string)file_get_contents($dir . self::FILE_MANIFEST), true);
                if (!is_array($manifest)) throw new InvalidBackupException('manifest.json ist beschädigt.');
                foreach ($manifest['files'] ?? [] as $name => $info) {
                    $path = $dir . basename((string)$name);
                    if (!is_file($path) || hash_file('sha256', $path) !== ($info['sha256'] ?? '')) {
                        throw new InvalidBackupException("Prüfsumme von {$name} stimmt nicht – Sicherung beschädigt.");
                    }
                }
            }
            if (!is_file($dir . self::FILE_JSON)) {
                throw new InvalidBackupException(self::FILE_JSON . ' fehlt in der Sicherung.');
            }
            $sqlite = is_file($dir . self::FILE_DB) ? $dir . self::FILE_DB : null;
            $format = $manifest ? (int)($manifest['format'] ?? 2) : 1;
            return new self($format, self::decodeSnapshot($dir . self::FILE_JSON), $sqlite, $manifest, $tmpDir);
        } catch (\Throwable $e) {
            if ($tmpDir !== null) self::removeDir($tmpDir);
            throw $e;
        }
    }

    private static function decodeSnapshot(string $file): array
    {
        $snap = json_decode((string)file_get_contents($file), true);
        if (!is_array($snap) || !isset($snap['data']) || !is_array($snap['data'])) {
            throw new InvalidBackupException('Sicherung beschädigt (kein data-Block).');
        }
        return $snap;
    }

    /** Validierte Hauptdaten; fehlende optionale Bereiche älterer Sicherungen werden ergänzt. */
    public function data(): array
    {
        $data = $this->snapshot['data'];
        if (!isset($data['baustellen']) || !is_array($data['baustellen'])) {
            throw new InvalidBackupException("Sicherung beschädigt: 'baustellen' fehlt oder ist kein Array.");
        }
        foreach (self::OPTIONAL_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = [];
            } elseif (!is_array($data[$key])) {
                throw new InvalidBackupException("Sicherung beschädigt: '{$key}' muss ein Array sein.");
            }
        }
        foreach ($data['baustellen'] as $i => $b) {
            if (!is_array($b) || !isset($b['id'])) {
                throw new InvalidBackupException("Sicherung beschädigt: Baustelle #{$i} ohne id.");
            }
            if (!isset($b['name']) || $b['name'] === '') {
                $data['baustellen'][$i]['name'] = 'Baustelle #' . (int)$b['id'];
            }
        }
        return $data;
    }

    public function appVersion(): string
    {
        return (string)($this->manifest['appVersion'] ?? $this->snapshot['v'] ?? '');
    }

    public function cleanup(): void
    {
        if ($this->tmpDir !== null) self::removeDir($this->tmpDir);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/bk_backup_' . bin2hex(random_bytes(6)) . '/';
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Temporäres Verzeichnis konnte nicht angelegt werden.');
        }
        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        try {
            foreach (glob($dir . '*') ?: [] as $f) unlink($f);
            if (is_dir($dir)) rmdir($dir);
        } catch (\Throwable $e) {
            error_log('[Backup] Temp-Verzeichnis nicht entfernt: ' . $e->getMessage());
        }
    }
}
