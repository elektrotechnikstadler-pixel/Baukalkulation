<?php
declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiResponse;

final class BackupTest extends ApiTestCase
{
    private function befuelleBeispieldaten(): int
    {
        $kundeId = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH']))['kunde']['id'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau', 'kundeId' => $kundeId]]);
        $this->assertOk($this->api->post('save_zeiterfassung', ['entries' => [[
            'clientUuid' => 'u-1', 'datum' => '2026-03-02', 'typ' => 'arbeit', 'baustelleId' => 1,
            'stunden' => 8, 'von' => '07:00', 'bis' => '15:00', 'pause' => 0,
        ]]]));
        $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung', 'kundeId' => $kundeId]]));
        return $kundeId;
    }

    private function speichereZip(ApiResponse $r): string
    {
        $this->assertSame(200, $r->status, $r->describe());
        $this->assertSame('application/zip', $r->header('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'bkzip') . '.zip';
        file_put_contents($path, $r->body);
        return $path;
    }

    public function testManuelleSicherungErscheintInDerListe(): void
    {
        $this->setupAdmin();
        $this->befuelleBeispieldaten();

        $date = $this->assertOk($this->api->post('backup_create', ['name' => 'Vor Umbau']))['date'];
        $this->assertStringEndsWith('_Vor-Umbau', $date);

        $backups = $this->assertOk($this->api->get('backups'))['backups'];
        $this->assertSame($date, $backups[0]['date']);
        $this->assertSame(1, $backups[0]['count']);
        $this->assertFileExists($this->server->dataPath("backups/{$date}/database.sqlite"));
    }

    public function testDownloadEnthaeltJsonUndSqlite(): void
    {
        $this->setupAdmin();
        $this->befuelleBeispieldaten();

        $zipPath = $this->speichereZip($this->api->get('backup_download'));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->locateName('baukalkulation.json'));
        $this->assertNotFalse($zip->locateName('database.sqlite'));
        $snap = json_decode((string)$zip->getFromName('baukalkulation.json'), true);
        $zip->close();
        @unlink($zipPath);

        $this->assertSame('Neubau', $snap['data']['baustellen'][0]['name']);
        $this->assertArrayHasKey('v', $snap);
    }

    public function testUploadInFrischeInstallationStelltAllesWiederHer(): void
    {
        $this->setupAdmin();
        $this->befuelleBeispieldaten();
        $zipPath = $this->speichereZip($this->api->get('backup_download'));

        $this->server->resetData();
        $this->api = $this->server->client();
        $this->setupAdmin();
        $json = $this->assertOk($this->api->upload('backup_upload', 'backup', $zipPath));
        @unlink($zipPath);
        $this->assertNotEmpty($json['safetyBackup']);

        $this->assertSame(['Neubau'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
        $this->assertSame(['u-1'], array_column($this->assertOk($this->api->get('load_zeiterfassung'))['entries'], 'clientUuid'));
        $this->assertCount(1, $this->assertOk($this->api->get('list_rechnungen'))['rechnungen']);
    }

    public function testRestoreSetztAlleDatenAufDenSicherungsstandZurueck(): void
    {
        $this->setupAdmin();
        $kundeId = $this->befuelleBeispieldaten();
        $date = $this->assertOk($this->api->post('backup_create'))['date'];

        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau geändert', 'kundeId' => $kundeId], ['id' => 2, 'name' => 'Zusätzlich']], $rev);
        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Neukunde']));

        $json = $this->assertOk($this->api->post('restore', [], ['date' => $date]));

        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
        $this->assertSame(['Neubau'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));

        // Die Sicherheitskopie enthält den Stand direkt vor dem Restore.
        $this->assertOk($this->api->post('restore', [], ['date' => $json['safetyBackup']]));
        $this->assertSame(
            ['Neubau geändert', 'Zusätzlich'],
            array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'),
        );
    }

    public function testUploadInBestehendeInstallationSetztBaustellenZurueck(): void
    {
        $this->setupAdmin();
        $kundeId = $this->befuelleBeispieldaten();
        $zipPath = $this->speichereZip($this->api->get('backup_download'));

        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau geändert', 'kundeId' => $kundeId]], $rev);

        $this->assertOk($this->api->upload('backup_upload', 'backup', $zipPath));
        @unlink($zipPath);

        $data = $this->assertOk($this->api->get('load'))['data'];
        $this->assertSame(['Neubau'], array_column($data['baustellen'], 'name'));
        $this->assertGreaterThan($rev + 1, $data['rev'], 'Restore erhöht die Revision, damit offene Clients neu laden');
    }

    public function testSicherungOhneBaustellenUeberschreibtVorhandeneNicht(): void
    {
        $this->setupAdmin();
        $leer = $this->assertOk($this->api->post('backup_create'))['date'];
        $this->befuelleBeispieldaten();

        $this->assertStatus(400, $this->api->post('restore', [], ['date' => $leer]));

        $this->assertSame(['Neubau'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
    }

    public function testNurAdminOderMasterDarfSichern(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->post('backup_create'));
        $this->assertStatus(403, $monteur->get('backup_download'));
    }
}
