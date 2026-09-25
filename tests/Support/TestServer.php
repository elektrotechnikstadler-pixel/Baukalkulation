<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Startet api.php im PHP-Built-in-Server mit eigenem Datenverzeichnis (BK_DATA_DIR).
 * Ein Server pro PHPUnit-Prozess; resetData() sorgt für Isolation zwischen Tests.
 */
final class TestServer
{
    private static ?self $instance = null;

    /** @var resource */
    private $process;
    public readonly string $baseUrl;
    public readonly string $appRoot;
    public readonly string $dataDir;
    private readonly string $workDir;
    private readonly string $errorLog;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->appRoot  = dirname(__DIR__, 2);
        $this->workDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bk-test-' . bin2hex(random_bytes(4));
        $this->dataDir  = $this->workDir . DIRECTORY_SEPARATOR . 'data';
        $this->errorLog = $this->workDir . DIRECTORY_SEPARATOR . 'php_errors.log';
        $sessionDir     = $this->workDir . DIRECTORY_SEPARATOR . 'sessions';
        mkdir($this->dataDir, 0777, true);
        mkdir($sessionDir, 0777, true);

        $port = self::freePort();
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $env = getenv();
        $env['BK_DATA_DIR'] = $this->dataDir;

        $cmd = [
            PHP_BINARY,
            '-d', 'session.save_path=' . $sessionDir,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $this->errorLog,
            '-d', 'upload_max_filesize=50M',
            '-d', 'post_max_size=50M',
            '-S', "127.0.0.1:{$port}",
            '-t', $this->appRoot . DIRECTORY_SEPARATOR . 'public',
        ];
        $serverLog = $this->workDir . DIRECTORY_SEPARATOR . 'server.log';
        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
            $pipes,
            $this->appRoot,
            $env,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('PHP-Built-in-Server konnte nicht gestartet werden.');
        }
        $this->process = $process;
        register_shutdown_function([$this, 'stop']);
        $this->waitUntilReachable($port);
    }

    public function client(): ApiClient
    {
        return new ApiClient($this->baseUrl);
    }

    /** Leert das Datenverzeichnis; der nächste Request legt eine frische DB an. */
    public function resetData(): void
    {
        self::removeDir($this->dataDir);
        mkdir($this->dataDir, 0777, true);
        @file_put_contents($this->errorLog, '');
    }

    public function dataPath(string $relative = ''): string
    {
        return $this->dataDir . DIRECTORY_SEPARATOR . $relative;
    }

    public function errorLogTail(int $lines = 20): string
    {
        if (!is_file($this->errorLog)) {
            return '';
        }
        $all = file($this->errorLog, FILE_IGNORE_NEW_LINES) ?: [];
        return $all ? "\n--- PHP-Fehlerlog ---\n" . implode("\n", array_slice($all, -$lines)) : '';
    }

    /**
     * Führt ein CLI-Skript der App (z. B. migrate.php) mit demselben Datenverzeichnis aus.
     * @return array{0:int,1:string} Exit-Code und Ausgabe
     */
    public function runCli(string $script, array $args = []): array
    {
        $env = getenv();
        $env['BK_DATA_DIR'] = $this->dataDir;
        $proc = proc_open(
            array_merge([PHP_BINARY, $this->appRoot . DIRECTORY_SEPARATOR . $script], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->appRoot,
            $env,
        );
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), (string) $out];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        self::removeDir($this->workDir);
    }

    private function waitUntilReachable(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("Test-Server auf Port {$port} nicht erreichbar.");
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) {
            throw new \RuntimeException("Kein freier Port: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
