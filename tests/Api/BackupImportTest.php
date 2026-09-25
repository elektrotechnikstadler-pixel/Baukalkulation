<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Migrator;

/** Backup-Format 2, verschlüsselte Sicherungen und atomarer Import. */
final class BackupImportTest extends ApiTestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function befuelle(): void
    {
        $this->setupAdmin();
        $kundeId = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH']))['kunde']['id'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau', 'kundeId' => $kundeId]]);
        $this->assertOk($this->api->post('save_zeiterfassung', ['entries' => [[
            'clientUuid' => 'u-1', 'datum' => '2026-03-02', 'typ' => 'arbeit', 'baustelleId' => 1,
            'stunden' => 8, 'von' => '07:00', 'bis' => '15:00', 'pause' => 0,
        ]]]));
    }

    /** @return array<string,string> Dateiname => Inhalt */
    private function downloadEntries(): array
    {
        $r = $this->api->get('backup_download');
        $this->assertSame(200, $r->status, $r->describe());
        $zipPath = $this->tmp('.zip');
        file_put_contents($zipPath, $r->body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $entries;
    }

    /** @param array<string,string> $entries */
    private function buildZip(array $entries, ?string $password = null): string
    {
        $path = $this->tmp('.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            if ($password !== null) {
                $zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password);
            }
        }
        $zip->close();
        return $path;
    }

    /** Ändert die database.sqlite eines Backups; das Manifest entfällt (Prüfsumme wäre falsch). */
    private function withModifiedDb(array $entries, callable $modify): array
    {
        $dbPath = $this->tmp('.sqlite');
        file_put_contents($dbPath, $entries['database.sqlite']);
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $modify($pdo);
        $pdo = null;
        $entries['database.sqlite'] = (string) file_get_contents($dbPath);
        unset($entries['manifest.json']);
        return $entries;
    }

    private function tmp(string $suffix): string
    {
        return $this->tmpFiles[] = sys_get_temp_dir() . '/bk_test_' . bin2hex(random_bytes(6)) . $suffix;
    }

    public function testDownloadIstFormat2MitManifestUndPruefsummen(): void
    {
        $this->befuelle();

        $entries = $this->downloadEntries();

        $this->assertEqualsCanonicalizing(['baukalkulation.json', 'database.sqlite', 'manifest.json'], array_keys($entries));
        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame(2, $manifest['format']);
        $this->assertSame(Migrator::latestVersion(), $manifest['schemaVersion']);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame(hash('sha256', $entries['database.sqlite']), $manifest['files']['database.sqlite']['sha256']);
        $this->assertSame(hash('sha256', $entries['baukalkulation.json']), $manifest['files']['baukalkulation.json']['sha256']);
    }

    public function testVerschluesselteSicherungBrauchtPasswort(): void
    {
        $this->befuelle();
        $zip = $this->buildZip($this->downloadEntries(), 'Geheim-123');
        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Nach der Sicherung']));

        $ohne = $this->assertStatus(400, $this->api->upload('backup_upload', 'backup', $zip));
        $this->assertTrue($ohne['passwordRequired']);
        $falsch = $this->assertStatus(400, $this->api->upload('backup_upload', 'backup', $zip, [], ['password' => 'falsch']));
        $this->assertTrue($falsch['passwordRequired']);

        $this->assertOk($this->api->upload('backup_upload', 'backup', $zip, [], ['password' => 'Geheim-123']));
        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
    }

    public function testManipulierteSicherungWirdAbgelehnt(): void
    {
        $this->befuelle();
        $entries = $this->downloadEntries();
        $entries['baukalkulation.json'] = str_replace('Neubau', 'Manipuliert', $entries['baukalkulation.json']);

        $json = $this->assertStatus(400, $this->api->upload('backup_upload', 'backup', $this->buildZip($entries)));

        $this->assertStringContainsString('Prüfsumme', $json['error']);
    }

    public function testSicherungAusNeuererVersionWirdAbgelehnt(): void
    {
        $this->befuelle();
        $entries = $this->withModifiedDb($this->downloadEntries(), function (\PDO $pdo): void {
            $pdo->exec('INSERT INTO ' . Migrator::TABLE . " (version, migration_name) VALUES (99991231235959, 'zukunft')");
        });

        $json = $this->assertStatus(400, $this->api->upload('backup_upload', 'backup', $this->buildZip($entries)));

        $this->assertStringContainsString('neueren App-Version', $json['error']);
    }

    public function testFehlerhafteSicherungAendertNichts(): void
    {
        $this->befuelle();
        $entries = $this->withModifiedDb($this->downloadEntries(), function (\PDO $pdo): void {
            // Doppelte Buchung verletzt im Ziel den eindeutigen Index (username, clientUuid).
            $pdo->exec('DROP INDEX idx_zeit_uuid');
            $pdo->exec("INSERT INTO zeiterfassung (username, datum, typ, stunden, clientUuid) VALUES ('admin', '2026-03-03', 'arbeit', 2, 'u-1')");
            $pdo->exec("UPDATE kunden SET firma = 'Aus defekter Sicherung'");
        });
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau aktuell']], $rev);

        $json = $this->assertStatus(400, $this->api->upload('backup_upload', 'backup', $this->buildZip($entries)));

        $this->assertStringContainsString('zeiterfassung', $json['error']);
        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
        $this->assertSame(['Neubau aktuell'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
        $this->assertCount(1, $this->assertOk($this->api->get('load_zeiterfassung'))['entries']);
    }

    public function testKomplettErsetzenArchiviertFehlendeBaustellen(): void
    {
        $this->befuelle();
        $entries = $this->downloadEntries();
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau'], ['id' => 2, 'name' => 'Nur im Ziel']], $rev);

        $json = $this->assertOk($this->api->upload('backup_upload_replace', 'backup', $this->buildZip($entries)));

        $this->assertSame(1, $json['archived']);
        $this->assertSame(['Neubau'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
        $archiv = $this->assertOk($this->api->get('list_archive'));
        $this->assertStringContainsString('Nur im Ziel', json_encode($archiv, JSON_UNESCAPED_UNICODE));
    }

    public function testNachImportMeldenAlteClientsEinenZeitKonflikt(): void
    {
        $this->befuelle();
        $zeitRev = $this->assertOk($this->api->get('load_zeiterfassung'))['zeitRev'];
        $zip = $this->buildZip($this->downloadEntries());

        $this->assertOk($this->api->upload('backup_upload', 'backup', $zip));

        $this->assertStatus(409, $this->api->post('save_zeiterfassung', [
            'entries' => [[
                'clientUuid' => 'u-1', 'datum' => '2026-03-02', 'typ' => 'arbeit', 'baustelleId' => 1,
                'stunden' => 6, 'von' => '07:00', 'bis' => '13:00', 'pause' => 0,
            ]],
            'baseZeitRev' => $zeitRev,
        ]));
    }

    public function testSehrAlteJsonTagessicherungLaesstSichWiederherstellen(): void
    {
        $this->befuelle();
        $backupDir = $this->server->dataPath('backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        file_put_contents($backupDir . '/2020-01-01.json', json_encode(['ts' => '2020-01-01T07:00:00+01:00', 'data' => [
            'baustellen' => [['id' => 1, 'name' => 'Aus 2020'], ['id' => 5, 'name' => '']],
        ]]));

        $this->assertOk($this->api->post('restore', [], ['date' => '2020-01-01']));

        $this->assertSame(
            ['Aus 2020', 'Baustelle #5'],
            array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'),
        );
        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
    }
}
