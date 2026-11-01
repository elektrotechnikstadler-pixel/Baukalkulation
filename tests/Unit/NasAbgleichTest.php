<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jobs\NasAbgleich;
use App\Services\Jobs\NasAbgleichFehler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NasAbgleichTest extends TestCase
{
    private const REL_DATA_DIR = 'tests\\tmp\\nas-tests-data';

    private \PDO $db;
    private string $dataDir;
    private string $runDir;
    private string $controlFile;
    private string $logFile;
    private string $errorLogFile;
    private string $fakeRclone;

    public static function setUpBeforeClass(): void
    {
        $dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::REL_DATA_DIR;
        if (!defined('DATA_DIR')) {
            define('DATA_DIR', rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR);
        }
        if (!defined('APP_VERSION')) {
            define('APP_VERSION', 'test-version');
        }
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 2));
        }
    }

    protected function setUp(): void
    {
        $this->dataDir = rtrim((string) DATA_DIR, '/\\');
        $this->runDir = $this->dataDir . DIRECTORY_SEPARATOR . 'run';
        $this->controlFile = $this->runDir . DIRECTORY_SEPARATOR . 'fake-rclone-control.json';
        $this->logFile = $this->runDir . DIRECTORY_SEPARATOR . 'fake-rclone-log.jsonl';
        $this->errorLogFile = $this->runDir . DIRECTORY_SEPARATOR . 'php-error.log';
        $this->fakeRclone = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'rclone' . DIRECTORY_SEPARATOR . 'fake-rclone.php';

        $this->removeDir($this->dataDir);
        mkdir($this->runDir, 0777, true);

        $this->db = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec("CREATE TABLE system_einstellungen (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
    }

    public function testVerbindungTestenFuehrtMkdirDannRcatMitSmbZielAus(): void
    {
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
            ],
        ]);

        $nas = $this->nasConfig(['passwort' => 'Geheim!123']);
        $abgleich = $this->newNasAbgleich();

        $result = $abgleich->verbindungTesten($nas);

        $this->assertSame(['ok' => true], $result);
        $entries = $this->logEntries();
        $this->assertSame(['obscure', 'mkdir', 'rcat'], array_column($entries, 'command'));

        $mkdir = $entries[1]['argv'];
        $rcat = $entries[2]['argv'];
        $this->assertSame('mkdir', $mkdir[0] ?? null);
        $this->assertStringStartsWith(':smb:', (string) ($mkdir[1] ?? ''));
        $this->assertStringContainsString('Freigabe/baukalkulation', (string) ($mkdir[1] ?? ''));

        $this->assertSame('rcat', $rcat[0] ?? null);
        $this->assertStringStartsWith(':smb:', (string) ($rcat[1] ?? ''));
        $this->assertStringEndsWith('/_baukalkulation_verbindungstest.txt', (string) ($rcat[1] ?? ''));
    }

    #[DataProvider('verbindungFehlerProvider')]
    public function testVerbindungTestenMapptRcloneFehlercodes(string $stderr, string $erwartet): void
    {
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 1, 'stderr' => $stderr],
            ],
        ]);

        $result = $this->newNasAbgleich()->verbindungTesten($this->nasConfig(['passwort' => 'Geheim!123']));

        $this->assertFalse($result['ok']);
        $this->assertSame($erwartet, $result['fehlerCode'] ?? null);
    }

    public static function verbindungFehlerProvider(): iterable
    {
        yield 'anmeldung' => ['The attempted logon is invalid', 'NAS_ANMELDUNG'];
        yield 'host' => ['dial tcp: no such host', 'NAS_NICHT_ERREICHBAR'];
        yield 'timeout' => ['i/o timeout', 'NAS_NICHT_ERREICHBAR'];
        yield 'rechte' => ['permission denied', 'NAS_KEINE_SCHREIBRECHTE'];
        yield 'rechte-gemischt-1' => ['Permission Denied', 'NAS_KEINE_SCHREIBRECHTE'];
        yield 'rechte-gemischt-2' => ['Access is denied', 'NAS_KEINE_SCHREIBRECHTE'];
        yield 'rest' => ['something else', 'NAS_VERBINDUNG'];
    }

    public function testVerbindungTestenLiefertKonfigurationsfehlerBeiUngueltigemHost(): void
    {
        $result = $this->newNasAbgleich()->verbindungTesten($this->nasConfig([
            'host' => 'bad host',
            'passwort' => 'abc',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertSame('NAS_KONFIGURATION', $result['fehlerCode'] ?? null);
    }

    public function testVerbindungTestenLiefertRcloneFehltBeiNichtStartbaremBefehl(): void
    {
        $abgleich = new NasAbgleich($this->db, [$this->dataDir . DIRECTORY_SEPARATOR . 'not-existing-rclone.exe']);
        $result = $abgleich->verbindungTesten($this->nasConfig(['passwort' => 'abc']));

        $this->assertFalse($result['ok']);
        $this->assertSame('NAS_RCLONE_FEHLT', $result['fehlerCode'] ?? null);
    }

    #[DataProvider('verbindungExitCodeProvider')]
    public function testVerbindungTestenErkenntExitCodesKorrekt(int $exitCode, bool $okErwartet): void
    {
        $queue = [
            ['stdout' => "obscured-pass\n"],
            ['exitCode' => $exitCode, 'stderr' => $exitCode === 0 ? '' : 'generic failure'],
        ];
        if ($exitCode === 0) {
            $queue[] = ['exitCode' => 0];
        }

        $this->writeControl(['queue' => $queue]);

        $result = $this->newNasAbgleich()->verbindungTesten($this->nasConfig(['passwort' => 'abc']));

        $this->assertSame($okErwartet, (bool) ($result['ok'] ?? false));
        if ($okErwartet) {
            $this->assertArrayNotHasKey('fehlerCode', $result);
            return;
        }
        $this->assertSame('NAS_VERBINDUNG', $result['fehlerCode'] ?? null);
    }

    public static function verbindungExitCodeProvider(): iterable
    {
        yield 'exit-0' => [0, true];
        yield 'exit-1' => [1, false];
        yield 'exit-3' => [3, false];
    }

    public function testAbgleichenVerwendetVertragsfilterUndErlaubteBefehle(): void
    {
        $externDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'nas-extern-backups';
        $this->removeDir($externDir);
        mkdir($externDir, 0777, true);
        $externDir = realpath($externDir) ?: $externDir;
        file_put_contents($externDir . DIRECTORY_SEPARATOR . 'dummy.txt', 'x');
        file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
            'backups' => $externDir,
            'uploads' => $this->dataDir . DIRECTORY_SEPARATOR . 'uploads',
        ], JSON_UNESCAPED_UNICODE));

        $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 5, 'bytes' => 120, 'errors' => 0]],
                ['exitCode' => 0, 'stats' => ['transfers' => 2, 'bytes' => 80, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $result = $this->newNasAbgleich(systemPfade: [])->abgleichen();

        $this->assertSame(7, $result['transfers']);
        $this->assertSame(200, $result['bytes']);
        $this->assertSame(0, $result['fehler']);
        $this->assertSame(1, $result['externePfade']);
        $this->assertSame(0, $result['externeUebersprungen']);

        $entries = $this->logEntries();
        $commands = array_column($entries, 'command');
        $this->assertSame(['obscure', 'mkdir', 'rcat', 'obscure', 'copy', 'copy', 'rcat'], $commands);

        $forbidden = ['sync', 'move', 'delete', 'deletefile', 'purge', 'rmdir', 'cleanup'];
        foreach ($commands as $cmd) {
            $this->assertNotContains($cmd, $forbidden, 'Verbotener rclone-Befehl erkannt: ' . $cmd);
        }

        $mainCopy = $entries[4]['argv'];
        $this->assertSame('copy', $mainCopy[0] ?? null);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $mainCopy[1] ?? null);
        $this->assertSame(':smb:Freigabe/baukalkulation/data', $mainCopy[2] ?? null);

        $filterRegeln = [];
        for ($i = 3; $i < count($mainCopy); $i++) {
            if ($mainCopy[$i] === '--filter') {
                $filterRegeln[] = $mainCopy[$i + 1] ?? '';
                $i++;
            }
        }
        $this->assertSame([
            '- /database.sqlite*',
            '- /secret.key',
            '- /systemadmin-passwort.txt',
            '- /audit_pepper.txt',
            '- /update/**',
            '- /run/**',
            '- /backups/.tmp-*/**',
            '- *.lock',
            '+ /backups/**',
            '+ /uploads/**',
            '+ /bautagebuch/**',
            '+ /datanorm/**',
            '+ /archiv/**',
            '+ /archiv_material/**',
            '+ /exports/**',
            '+ /pg-backups/**',
            '+ /paths_config.json',
            '+ /firma_logo.*',
            '+ /audit.log',
            '- **',
        ], $filterRegeln);

        $externCopy = $entries[5]['argv'];
        $this->assertSame('copy', $externCopy[0] ?? null);
        $this->assertSame($externDir, $externCopy[1] ?? null);
        $this->assertSame(':smb:Freigabe/baukalkulation/extern/backups', $externCopy[2] ?? null);

        $externFilterRegeln = [];
        for ($i = 3; $i < count($externCopy); $i++) {
            if ($externCopy[$i] === '--filter') {
                $externFilterRegeln[] = $externCopy[$i + 1] ?? '';
                $i++;
            }
        }
        $this->assertSame([
            '- secret.key',
            '- database.sqlite*',
            '- systemadmin-passwort.txt',
            '- audit_pepper.txt',
            '- *.env',
            '- .tmp-*/**',
            '- *.lock',
        ], $externFilterRegeln);

        $statusRcat = $entries[6]['argv'];
        $this->assertSame('rcat', $statusRcat[0] ?? null);
        $this->assertStringEndsWith('/_baukalkulation_status.json', (string) ($statusRcat[1] ?? ''));
    }


    public function testAbgleichenUeberspringtExternePfadeDieDataDirVorfahrSind(): void
    {
        $vorfahr = dirname($this->dataDir);
        file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
            'backups' => $vorfahr,
        ], JSON_UNESCAPED_UNICODE));

        $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 1, 'bytes' => 10, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $altLog = ini_set('error_log', $this->errorLogFile);
        try {
            $result = $this->newNasAbgleich()->abgleichen();
        } finally {
            if ($altLog !== false) {
                ini_set('error_log', $altLog);
            }
        }

        $this->assertSame(0, $result['externePfade']);
        $this->assertSame(1, $result['externeUebersprungen']);

        $copyBefehle = array_values(array_filter(
            $this->logEntries(),
            static fn(array $entry): bool => ($entry['command'] ?? '') === 'copy',
        ));
        $this->assertCount(1, $copyBefehle);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $copyBefehle[0]['argv'][1] ?? null);
    }



    public function testAbgleichenUeberspringtDataDirVorfahrAuchBeiLeererSystempfadListe(): void
    {
        $vorfahr = dirname((string) APP_ROOT);
        file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
            'backups' => $vorfahr,
        ], JSON_UNESCAPED_UNICODE));

        $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 1, 'bytes' => 10, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $result = $this->newNasAbgleich(systemPfade: [])->abgleichen();

        $this->assertSame(0, $result['externePfade']);
        $this->assertSame(1, $result['externeUebersprungen']);

        $copyBefehle = array_values(array_filter(
            $this->logEntries(),
            static fn(array $entry): bool => ($entry['command'] ?? '') === 'copy',
        ));
        $this->assertCount(1, $copyBefehle);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $copyBefehle[0]['argv'][1] ?? null);
    }

    public function testAbgleichenUeberspringtExternePfadeInnerhalbVonAppRootAusserhalbDataDir(): void
    {
        if (!defined('APP_ROOT')) {
            define('APP_ROOT', dirname(__DIR__, 2));
        }
        $appRoot = rtrim((string) APP_ROOT, '/\\');
        file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
            'backups' => $appRoot . DIRECTORY_SEPARATOR . 'src',
        ], JSON_UNESCAPED_UNICODE));

        $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 1, 'bytes' => 10, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $result = $this->newNasAbgleich()->abgleichen();

        $this->assertSame(0, $result['externePfade']);
        $this->assertSame(1, $result['externeUebersprungen']);

        $copyBefehle = array_values(array_filter(
            $this->logEntries(),
            static fn(array $entry): bool => ($entry['command'] ?? '') === 'copy',
        ));
        $this->assertCount(1, $copyBefehle);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $copyBefehle[0]['argv'][1] ?? null);
    }

    public function testAbgleichenUeberspringtTmpPfadInUnixSystempfaden(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Unix-Systempfade wie /tmp/... sind unter Windows nicht reproduzierbar.');
        }

        $tmpPfad = '/tmp/nas-abgleich-test-systempfad';
        $this->removeDir($tmpPfad);

        if (!@mkdir($tmpPfad, 0777, true) && !is_dir($tmpPfad)) {
            $this->markTestSkipped('Systempfad /tmp ist auf dieser Umgebung nicht reproduzierbar.');
        }

        $tmp = realpath($tmpPfad) ?: $tmpPfad;

        try {
            file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
                'backups' => $tmp,
            ], JSON_UNESCAPED_UNICODE));

            $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
            $this->writeControl([
                'queue' => [
                    ['stdout' => "obscured-pass\n"],
                    ['exitCode' => 0],
                    ['exitCode' => 0],
                    ['stdout' => "obscured-pass\n"],
                    ['exitCode' => 0, 'stats' => ['transfers' => 1, 'bytes' => 10, 'errors' => 0]],
                    ['exitCode' => 0],
                ],
            ]);

            $result = $this->newNasAbgleich()->abgleichen();
        } finally {
            $this->removeDir($tmpPfad);
        }

        $this->assertSame(0, $result['externePfade']);
        $this->assertSame(1, $result['externeUebersprungen']);

        $copyBefehle = array_values(array_filter(
            $this->logEntries(),
            static fn(array $entry): bool => ($entry['command'] ?? '') === 'copy',
        ));
        $this->assertCount(1, $copyBefehle);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $copyBefehle[0]['argv'][1] ?? null);
    }

    public function testAbgleichenUeberspringtHomePfadInUnixSystempfadenWennReproduzierbar(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Unix-Systempfade wie /home/... sind unter Windows nicht reproduzierbar.');
        }

        $homePfad = '/home/nas-abgleich-test-systempfad';
        $this->removeDir($homePfad);

        if (!@mkdir($homePfad, 0777, true) && !is_dir($homePfad)) {
            $this->markTestSkipped('Systempfad /home ist auf dieser Umgebung nicht reproduzierbar (keine Schreibrechte).');
        }

        $home = realpath($homePfad) ?: $homePfad;

        try {
            file_put_contents($this->dataDir . DIRECTORY_SEPARATOR . 'paths_config.json', json_encode([
                'backups' => $home,
            ], JSON_UNESCAPED_UNICODE));

            $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
            $this->writeControl([
                'queue' => [
                    ['stdout' => "obscured-pass\n"],
                    ['exitCode' => 0],
                    ['exitCode' => 0],
                    ['stdout' => "obscured-pass\n"],
                    ['exitCode' => 0, 'stats' => ['transfers' => 1, 'bytes' => 10, 'errors' => 0]],
                    ['exitCode' => 0],
                ],
            ]);

            $result = $this->newNasAbgleich()->abgleichen();
        } finally {
            $this->removeDir($homePfad);
        }

        $this->assertSame(0, $result['externePfade']);
        $this->assertSame(1, $result['externeUebersprungen']);

        $copyBefehle = array_values(array_filter(
            $this->logEntries(),
            static fn(array $entry): bool => ($entry['command'] ?? '') === 'copy',
        ));
        $this->assertCount(1, $copyBefehle);
        $this->assertSame(rtrim($this->dataDir, '/\\'), $copyBefehle[0]['argv'][1] ?? null);
    }

    public function testAbgleichenSchreibtStatusdateiBeiTransferfehlerUndWirftNasTransfer(): void
    {
        $this->storeNas($this->nasConfig(['passwort' => 'Geheim!123']));
        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 1, 'stderr' => 'copy failed', 'stats' => ['transfers' => 3, 'bytes' => 99, 'errors' => 1]],
                ['exitCode' => 0],
            ],
        ]);

        try {
            $this->newNasAbgleich()->abgleichen();
            $this->fail('NAS_TRANSFER wurde erwartet.');
        } catch (NasAbgleichFehler $e) {
            $this->assertSame('NAS_TRANSFER', $e->fehlerCode);
            $this->assertSame(['transfers', 'bytes', 'fehler', 'dauerSek', 'externePfade', 'externeUebersprungen'], array_keys($e->kennzahlen));
            $this->assertStringNotContainsString('Freigabe', json_encode($e->kennzahlen, JSON_UNESCAPED_UNICODE));
        }

        $entries = $this->logEntries();
        $this->assertSame('rcat', $entries[count($entries) - 1]['command'] ?? null);
        $this->assertStringContainsString('"ergebnis":"fehler"', (string) ($entries[count($entries) - 1]['stdin'] ?? ''));
    }

    public function testPasswortBleibtAusArgumentenRueckgabenUndErrorLogs(): void
    {
        $klartext = 'Klartext-Passwort-987';
        $obscured = 'obscured-pass-token';
        $this->writeControl([
            'queue' => [
                ['stdout' => $obscured . "\n"],
                ['exitCode' => 1, 'stderr' => 'permission denied host=nas-host user=test-user token=' . $obscured],
            ],
        ]);

        $altLog = ini_set('error_log', $this->errorLogFile);
        try {
            $result = $this->newNasAbgleich()->verbindungTesten($this->nasConfig([
                'host' => 'nas-host',
                'benutzer' => 'test-user',
                'passwort' => $klartext,
            ]));
        } finally {
            if ($altLog !== false) {
                ini_set('error_log', $altLog);
            }
        }

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString($klartext, json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString($obscured, json_encode($result, JSON_UNESCAPED_UNICODE));

        foreach ($this->logEntries() as $entry) {
            $argvText = json_encode($entry['argv'] ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($klartext, (string) $argvText);
            if (($entry['command'] ?? '') !== 'obscure') {
                $this->assertStringNotContainsString($obscured, (string) $argvText);
            }

            $env = $entry['env'] ?? [];
            $this->assertNotSame($klartext, (string) ($env['RCLONE_SMB_PASS'] ?? ''));
            if (($entry['command'] ?? '') !== 'obscure') {
                $passwortObscured = str_replace('\\n', '', (string) ($env['RCLONE_SMB_PASS'] ?? ''));
                $this->assertSame($obscured, trim($passwortObscured));
            }
        }

        $errorLog = is_file($this->errorLogFile) ? (string) file_get_contents($this->errorLogFile) : '';
        $this->assertStringNotContainsString($klartext, $errorLog);
        $this->assertStringNotContainsString($obscured, $errorLog);
        $this->assertStringNotContainsString('nas-host', $errorLog);
        $this->assertStringNotContainsString('test-user', $errorLog);
    }

    public function testVerbindungTestenBrichtBeiZeitlimitMitNasVerbindungAb(): void
    {
        $this->writeControl([
            'queue' => [
                ['sleepMs' => 11000],
            ],
        ]);

        $result = $this->newNasAbgleich()->verbindungTesten($this->nasConfig(['passwort' => 'abc']));

        $this->assertFalse($result['ok']);
        $this->assertSame('NAS_VERBINDUNG', $result['fehlerCode'] ?? null);
    }

    private function newNasAbgleich(?array $systemPfade = null): NasAbgleich
    {
        return new NasAbgleich(
            $this->db,
            [PHP_BINARY, $this->fakeRclone],
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 10:00:00'),
            $systemPfade,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function nasConfig(array $overrides): array
    {
        return array_replace([
            'aktiv' => true,
            'host' => 'nas.example.local',
            'port' => 445,
            'freigabe' => 'Freigabe',
            'pfad' => 'baukalkulation',
            'benutzer' => 'nas-user',
            'passwort' => '',
            'domaene' => '',
            'bandbreiteKBs' => 0,
            'passwortNeuEingeben' => false,
        ], $overrides);
    }

    /** @param array<string,mixed> $nas */
    private function storeNas(array $nas): void
    {
        $data = [
            'sicherung' => ['uhrzeit' => '02:00', 'aufbewahrungTage' => 7],
            'ueberwachung' => ['aktiv' => true, 'empfaenger' => [], 'ausbleibenStunden' => 26, 'speicherMinMb' => 1024],
            'nas' => $nas,
        ];
        $this->db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data",
        )->execute([json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $control */
    private function writeControl(array $control): void
    {
        file_put_contents($this->controlFile, json_encode($control, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @unlink($this->logFile);
        @unlink($this->errorLogFile);
    }

    /** @return list<array<string,mixed>> */
    private function logEntries(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        $entries = [];
        foreach (file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
