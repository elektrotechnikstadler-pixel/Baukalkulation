<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Jede eingecheckte Backup-Fixture (tests/fixtures/backups/v*.zip) muss sich in eine
 * frische Installation einspielen lassen – Absicherung für alte Datenstände.
 */
final class BackupFixtureImportTest extends ApiTestCase
{
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../fixtures/backups/v*.zip') ?: [] as $zip) {
            yield basename($zip) => [$zip, substr($zip, 0, -4) . '.expected.json'];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixtureLaesstSichEinspielen(string $zip, string $expectedFile): void
    {
        $expected = json_decode((string) file_get_contents($expectedFile), true);
        $this->assertOk($this->api->post('setup', ['username' => $expected['adminUser'], 'password' => $expected['adminPass']]));

        $this->assertOk($this->api->upload('backup_upload', 'backup', $zip));

        $this->assertSame($expected['baustellen'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
        $this->assertSame($expected['kunden'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
        $this->assertSame($expected['zeiterfassung'], array_column($this->assertOk($this->api->get('load_zeiterfassung'))['entries'], 'clientUuid'));
        $this->assertSame($expected['rechnungen'], array_column($this->assertOk($this->api->get('list_rechnungen'))['rechnungen'], 'nummer'));
        $this->assertSame($expected['dienstleister'], array_column($this->assertOk($this->api->get('list_dienstleister'))['dienstleister'], 'firma'));

        // Nach dem Import muss mit den Daten normal weitergearbeitet werden können.
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->assertOk($this->api->post('save', ['data' => ['baustellen' => [['id' => 99, 'name' => 'Nach Import']]], 'baseRev' => $rev]));
        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Nach Import']));
    }
}
