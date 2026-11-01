<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jobs\NasAbgleich;
use App\Services\Jobs\NasJob;
use App\Services\Jobs\Ueberwachung;
use PHPUnit\Framework\TestCase;

final class NasJobTest extends TestCase
{
    private const REL_DATA_DIR = 'tests\\tmp\\nas-tests-data';

    private \PDO $db;
    private string $dataDir;
    private string $runDir;
    private string $controlFile;
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
    }

    protected function setUp(): void
    {
        $this->dataDir = rtrim((string) DATA_DIR, '/\\');
        $this->runDir = $this->dataDir . DIRECTORY_SEPARATOR . 'run';
        $this->controlFile = $this->runDir . DIRECTORY_SEPARATOR . 'fake-rclone-control.json';
        $this->fakeRclone = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'rclone' . DIRECTORY_SEPARATOR . 'fake-rclone.php';

        $this->removeDir($this->dataDir);
        mkdir($this->runDir, 0777, true);

        $this->db = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->createSchema($this->db);
        $this->seedBasisdaten($this->db);
    }

    public function testInaktiverJobOhneAuftragMachtNichts(): void
    {
        $this->storeSettings(false, '02:00', 'Geheim!123');

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 03:00:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 03:00:00'));

        $this->assertNull($result);
        $this->assertSame(0, $this->tableCount('job_laeufe'));
    }

    public function testErstversuchWartetBisTagessicherungOderZweiStundenVorbei(): void
    {
        $this->storeSettings(true, '02:00', 'Geheim!123');

        $frueh = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 03:30:00'));
        $this->assertNull($frueh->lauf($this->newNasAbgleich('2026-11-01 03:30:00')));
        $this->assertSame(0, $this->tableCount('job_laeufe'));

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

        $spaet = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 04:00:00'));
        $this->assertSame('nas: ok', $spaet->lauf($this->newNasAbgleich('2026-11-01 04:00:00')));

        $status = $this->statusRow('nas');
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
    }

    public function testErstversuchStartetVorZweiStundenWennSicherungAbgeschlossenIst(): void
    {
        $this->storeSettings(true, '02:00', 'Geheim!123');
        $this->insertStatus('sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'ok',
        ]);

        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 2, 'bytes' => 20, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 02:10:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 02:10:00'));

        $this->assertSame('nas: ok', $result);
        $this->assertSame(1, $this->tableCount('job_laeufe'));
    }

    public function testFehlerWirdWiederholtUndNachVierVersuchenFehlgeschlagenMitMeldung(): void
    {
        $this->storeSettings(true, '02:00', 'Klartext-Geheim-123');
        $this->insertStatus('sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'ok',
        ]);

        $kaputt = new NasAbgleich($this->db, [$this->dataDir . DIRECTORY_SEPARATOR . 'not-existing-rclone.exe']);

        $zeiten = ['2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 06:00:00', '2026-11-01 06:30:00'];
        foreach ($zeiten as $zeit) {
            $job = new NasJob($this->db, static fn() => new \DateTimeImmutable($zeit));
            $result = $job->lauf($kaputt);
            $this->assertStringContainsString('nas: fehlgeschlagen', (string) $result);
        }

        $zuFrueh = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 05:10:00'));
        $this->assertNull($zuFrueh->lauf($kaputt));

        $status = $this->statusRow('nas');
        $this->assertSame('fehlgeschlagen', $status['tageslaufStatus'] ?? null);
        $this->assertSame(4, (int) ($status['versuche'] ?? 0));
        $this->assertSame('', $status['naechsterVersuchAm'] ?? null);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 07:00:00'));
        $outbox = $this->db->query("SELECT schluessel FROM meldungen_outbox WHERE job='nas' AND typ='fehler'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['nas:2026-11-01:fehler'], $outbox);

        $statusJson = json_encode($this->statusRow('nas'), JSON_UNESCAPED_UNICODE);
        $laufJson = json_encode($this->db->query("SELECT * FROM job_laeufe WHERE job='nas' ORDER BY id DESC LIMIT 1")->fetch(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Klartext-Geheim-123', (string) $statusJson);
        $this->assertStringNotContainsString('Klartext-Geheim-123', (string) $laufJson);
    }

    public function testManuellerAuftragWirdAuchBeiInaktivemJobAlsFertigMarkiert(): void
    {
        $this->storeSettings(false, '02:00', 'Geheim!123');
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES ('auftrag-1', 'nas', 'wartend', '2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 05:00:00', 'admin', '', '{}')",
        )->execute();

        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 3, 'bytes' => 30, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 05:05:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 05:05:00'));

        $this->assertSame('nas: ok', $result);
        $auftrag = $this->db->query("SELECT status FROM job_auftraege WHERE id='auftrag-1'")->fetchColumn();
        $this->assertSame('fertig', $auftrag);
    }


    public function testManuellerLaufOhneFaelligkeitAendertTageslaufNicht(): void
    {
        $this->storeSettings(false, '02:00', 'Geheim!123');
        $this->insertStatus('nas', [
            'tageslaufDatum' => '2026-10-31',
            'tageslaufStatus' => 'fehlgeschlagen',
            'versuche' => 2,
            'naechsterVersuchAm' => '2026-10-31 07:30:00',
        ]);
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES ('auftrag-manuell-ohne-faelligkeit', 'nas', 'wartend', '2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 05:00:00', 'admin', '', '{}')",
        )->execute();

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

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 05:05:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 05:05:00'));

        $this->assertSame('nas: ok', $result);
        $status = $this->statusRow('nas');
        $this->assertSame('2026-10-31', $status['tageslaufDatum'] ?? null);
        $this->assertSame('fehlgeschlagen', $status['tageslaufStatus'] ?? null);
    }

    public function testManuellerErfolgSetztHeutigenFehlgeschlagenenTageslaufAufOk(): void
    {
        $this->storeSettings(false, '02:00', 'Geheim!123');
        $this->insertStatus('nas', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehlgeschlagen',
            'versuche' => 4,
            'naechsterVersuchAm' => '2026-11-01 07:30:00',
        ]);
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES ('auftrag-manuell-heutiger-fehler', 'nas', 'wartend', '2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 05:00:00', 'admin', '', '{}')",
        )->execute();

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

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 05:05:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 05:05:00'));

        $this->assertSame('nas: ok', $result);
        $status = $this->statusRow('nas');
        $this->assertSame('2026-11-01', $status['tageslaufDatum'] ?? null);
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
        $this->assertSame(0, (int) ($status['versuche'] ?? -1));
        $this->assertSame('', $status['naechsterVersuchAm'] ?? null);
    }

    public function testManuellerErfolgOhneHeutigenFehlschlagLaesstTageslaufUnveraendert(): void
    {
        $this->storeSettings(false, '02:00', 'Geheim!123');
        $this->insertStatus('nas', [
            'tageslaufDatum' => '2026-10-31',
            'tageslaufStatus' => 'ok',
            'versuche' => 3,
            'naechsterVersuchAm' => '2026-11-01 09:00:00',
        ]);
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES ('auftrag-manuell-heute-ok', 'nas', 'wartend', '2026-11-01 05:00:00', '2026-11-01 05:30:00', '2026-11-01 05:00:00', 'admin', '', '{}')",
        )->execute();

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

        $job = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 05:05:00'));
        $result = $job->lauf($this->newNasAbgleich('2026-11-01 05:05:00'));

        $this->assertSame('nas: ok', $result);
        $status = $this->statusRow('nas');
        $this->assertSame('2026-10-31', $status['tageslaufDatum'] ?? null);
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
    }

    public function testFehlgeschlagenerManuellerLaufBlockiertAutomatischenTageslaufNicht(): void
    {
        $this->storeSettings(true, '02:00', 'Geheim!123');
        $this->insertStatus('sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'ok',
        ]);
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES ('auftrag-manuell-fehler', 'nas', 'wartend', '2026-11-01 01:00:00', '2026-11-01 01:30:00', '2026-11-01 01:00:00', 'admin', '', '{}')",
        )->execute();

        $kaputt = new NasAbgleich($this->db, [$this->dataDir . DIRECTORY_SEPARATOR . 'not-existing-rclone.exe']);
        $manuell = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 01:00:00'));
        $manuellErgebnis = $manuell->lauf($kaputt);

        $this->assertSame('nas: fehlgeschlagen (NAS_RCLONE_FEHLT)', $manuellErgebnis);

        $this->writeControl([
            'queue' => [
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0],
                ['exitCode' => 0],
                ['stdout' => "obscured-pass\n"],
                ['exitCode' => 0, 'stats' => ['transfers' => 2, 'bytes' => 20, 'errors' => 0]],
                ['exitCode' => 0],
            ],
        ]);

        $auto = new NasJob($this->db, static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-01 04:00:00'));
        $autoErgebnis = $auto->lauf($this->newNasAbgleich('2026-11-01 04:00:00'));

        $this->assertSame('nas: ok', $autoErgebnis);
        $status = $this->statusRow('nas');
        $this->assertSame('2026-11-01', $status['tageslaufDatum'] ?? null);
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
        $this->assertSame(2, $this->tableCount('job_laeufe'));
    }

    public function testNaechsterVersuchAmOrientiertSichAnZeitNachAbgleich(): void
    {
        $this->storeSettings(true, '02:00', 'Geheim!123');
        $this->insertStatus('sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'ok',
        ]);

        $zeitpunkte = [
            new \DateTimeImmutable('2026-11-01 06:00:00'),
            new \DateTimeImmutable('2026-11-01 06:00:00'),
            new \DateTimeImmutable('2026-11-01 06:00:00'),
            new \DateTimeImmutable('2026-11-01 08:15:00'),
            new \DateTimeImmutable('2026-11-01 08:20:00'),
            new \DateTimeImmutable('2026-11-01 08:20:00'),
        ];
        $uhrIndex = 0;
        $uhr = static function () use (&$uhrIndex, $zeitpunkte): \DateTimeImmutable {
            $pos = min($uhrIndex, count($zeitpunkte) - 1);
            $uhrIndex++;
            return $zeitpunkte[$pos];
        };

        $job = new NasJob($this->db, $uhr);
        $kaputt = new NasAbgleich($this->db, [$this->dataDir . DIRECTORY_SEPARATOR . 'not-existing-rclone.exe']);
        $result = $job->lauf($kaputt);

        $this->assertSame('nas: fehlgeschlagen (NAS_RCLONE_FEHLT)', $result);

        $status = $this->statusRow('nas');
        $this->assertSame('fehler', $status['tageslaufStatus'] ?? null);
        $this->assertSame(1, (int) ($status['versuche'] ?? 0));
        $this->assertSame('2026-11-01 08:50:00', $status['naechsterVersuchAm'] ?? null);
    }

    private function newNasAbgleich(string $zeit): NasAbgleich
    {
        return new NasAbgleich($this->db, [PHP_BINARY, $this->fakeRclone], static fn(): \DateTimeImmutable => new \DateTimeImmutable($zeit));
    }

    private function storeSettings(bool $nasAktiv, string $uhrzeit, string $passwort): void
    {
        $data = [
            'sicherung' => ['uhrzeit' => $uhrzeit, 'aufbewahrungTage' => 7],
            'ueberwachung' => ['aktiv' => true, 'empfaenger' => [], 'ausbleibenStunden' => 26, 'speicherMinMb' => 1024],
            'nas' => [
                'aktiv' => $nasAktiv,
                'host' => 'nas.example.local',
                'port' => 445,
                'freigabe' => 'Freigabe',
                'pfad' => 'baukalkulation',
                'benutzer' => 'nas-user',
                'passwort' => $passwort,
                'domaene' => '',
                'bandbreiteKBs' => 0,
                'passwortNeuEingeben' => false,
            ],
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
    }

    /** @param array<string,mixed> $overrides */
    private function insertStatus(string $job, array $overrides): void
    {
        $row = array_replace([
            'job' => $job,
            'letzterLaufAm' => '',
            'letzterErfolgAm' => '',
            'letzterFehlerAm' => '',
            'fehlerCode' => '',
            'stoerungSeit' => '',
            'laeuftSeit' => '',
            'tageslaufDatum' => '',
            'tageslaufStatus' => '',
            'versuche' => 0,
            'naechsterVersuchAm' => '',
            'beobachtetSeit' => '',
        ], $overrides);

        $this->db->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit, laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm, beobachtetSeit)
             VALUES (:job, :letzterLaufAm, :letzterErfolgAm, :letzterFehlerAm, :fehlerCode, :stoerungSeit, :laeuftSeit, :tageslaufDatum, :tageslaufStatus, :versuche, :naechsterVersuchAm, :beobachtetSeit)
             ON CONFLICT(job) DO UPDATE SET
                letzterLaufAm = excluded.letzterLaufAm,
                letzterErfolgAm = excluded.letzterErfolgAm,
                letzterFehlerAm = excluded.letzterFehlerAm,
                fehlerCode = excluded.fehlerCode,
                stoerungSeit = excluded.stoerungSeit,
                laeuftSeit = excluded.laeuftSeit,
                tageslaufDatum = excluded.tageslaufDatum,
                tageslaufStatus = excluded.tageslaufStatus,
                versuche = excluded.versuche,
                naechsterVersuchAm = excluded.naechsterVersuchAm,
                beobachtetSeit = excluded.beobachtetSeit",
        )->execute($row);
    }

    /** @return array<string,mixed> */
    private function statusRow(string $job): array
    {
        $stmt = $this->db->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute([$job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS system_einstellungen (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS erinnerung_settings (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL DEFAULT '', role TEXT NOT NULL DEFAULT 'normal', isLocked INTEGER NOT NULL DEFAULT 0, email TEXT NOT NULL DEFAULT '', password TEXT NOT NULL DEFAULT '')");
        $db->exec("CREATE TABLE IF NOT EXISTS job_status (
            job TEXT NOT NULL PRIMARY KEY,
            letzterLaufAm TEXT NOT NULL DEFAULT '',
            letzterErfolgAm TEXT NOT NULL DEFAULT '',
            letzterFehlerAm TEXT NOT NULL DEFAULT '',
            fehlerCode TEXT NOT NULL DEFAULT '',
            stoerungSeit TEXT NOT NULL DEFAULT '',
            laeuftSeit TEXT NOT NULL DEFAULT '',
            tageslaufDatum TEXT NOT NULL DEFAULT '',
            tageslaufStatus TEXT NOT NULL DEFAULT '',
            versuche INTEGER NOT NULL DEFAULT 0,
            naechsterVersuchAm TEXT NOT NULL DEFAULT '',
            beobachtetSeit TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS job_laeufe (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT NOT NULL DEFAULT '',
            ausloeser TEXT NOT NULL DEFAULT '',
            gestartetAm TEXT NOT NULL DEFAULT '',
            beendetAm TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '',
            fehlerCode TEXT NOT NULL DEFAULT '',
            meldung TEXT NOT NULL DEFAULT '',
            kennzahlen TEXT NOT NULL DEFAULT '{}'
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS job_auftraege (
            id TEXT NOT NULL PRIMARY KEY,
            job TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT '',
            erstelltAm TEXT NOT NULL DEFAULT '',
            ablaufAm TEXT NOT NULL DEFAULT '',
            aktualisiertAm TEXT NOT NULL DEFAULT '',
            angefordertVon TEXT NOT NULL DEFAULT '',
            payload TEXT NOT NULL DEFAULT '',
            ergebnis TEXT NOT NULL DEFAULT '{}'
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS meldungen_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schluessel TEXT NOT NULL DEFAULT '' UNIQUE,
            job TEXT NOT NULL DEFAULT '',
            typ TEXT NOT NULL DEFAULT '',
            daten TEXT NOT NULL DEFAULT '{}',
            erstelltAm TEXT NOT NULL DEFAULT '',
            gesendetAm TEXT NOT NULL DEFAULT '',
            versuche INTEGER NOT NULL DEFAULT 0,
            naechsterVersuchAm TEXT NOT NULL DEFAULT ''
        )");
    }

    private function seedBasisdaten(\PDO $db): void
    {
        $db->exec("INSERT INTO system_einstellungen (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
        $db->exec("INSERT INTO erinnerung_settings (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
        $db->exec("INSERT INTO settings (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
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
