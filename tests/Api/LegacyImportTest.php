<?php

declare(strict_types=1);

namespace Tests\Api;

/** Import alter JSON-Datenstände (vor der SQLite-Umstellung) über migrate.php. */
final class LegacyImportTest extends ApiTestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../fixtures/legacy-v0';

    public function testJsonDatenstandWirdVollstaendigUebernommen(): void
    {
        foreach (glob(self::FIXTURE_DIR . '/*.json') as $file) {
            copy($file, $this->server->dataPath(basename($file)));
        }

        [$code, $out] = $this->server->runCli('migrate.php');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Migration erfolgreich', $out);

        $this->assertOk($this->api->post('login', ['username' => 'altadmin', 'password' => 'Alt-Passwort-1']));

        $data = $this->assertOk($this->api->get('load'))['data'];
        $this->assertSame(['Altbau Musterstraße 5', 'Garage Huber'], array_column($data['baustellen'], 'name'));
        $this->assertSame('P-2023-017', $data['baustellen'][0]['projektNr']);
        $this->assertSame(1, $data['baustellen'][0]['kundeId']);
        $this->assertEquals(45.5, $data['pauschalen'][0]['preis']);
        $this->assertEquals(12.25, $data['stundenKatalog'][0]['fixkosten']);
        $this->assertEquals(3.99, $data['materialKatalog'][0]['ek']);

        $kunden = $this->assertOk($this->api->get('load_kunden'))['kunden'];
        $this->assertSame('000001', $kunden[0]['kundennummer']);

        $entries = $this->assertOk($this->api->get('load_zeiterfassung'))['entries'];
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(['arbeit', 'urlaub'], array_column($entries, 'typ'));

        $rechnungen = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('RE-2023-0001', $rechnungen[0]['nummer']);
        $this->assertSame('Muster GmbH', $rechnungen[0]['kundeName']);

        $users = array_column($this->assertOk($this->api->get('list_users'))['users'], null, 'username');
        $this->assertSame([1], $users['geselle']['visibleBaustellen']);
    }

    public function testZweiterLaufOhneForceAendertNichts(): void
    {
        copy(self::FIXTURE_DIR . '/users.json', $this->server->dataPath('users.json'));
        $this->assertSame(0, $this->server->runCli('migrate.php')[0]);

        [$code, $out] = $this->server->runCli('migrate.php');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Datenbank existiert bereits', $out);
    }
}
