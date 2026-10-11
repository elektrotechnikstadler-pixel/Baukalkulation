<?php

declare(strict_types=1);

namespace Tests\Api;

final class ZeiterfassungTest extends ApiTestCase
{
    private function eintrag(string $uuid, string $datum, float $stunden, array $extra = []): array
    {
        return $extra + [
            'clientUuid'  => $uuid,
            'datum'       => $datum,
            'typ'         => 'arbeit',
            'baustelleId' => 1,
            'stunden'     => $stunden,
            'von'         => '07:00',
            'bis'         => sprintf('%02d:00', 7 + (int) $stunden),
            'pause'       => 0,
            'bemerkung'   => '',
        ];
    }

    public function testEintraegeSpeichernUndLaden(): void
    {
        $this->setupAdmin();
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau']]);

        $saved = $this->assertOk($this->api->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('uuid-1', '2026-03-02', 8, ['bemerkung' => 'Leitungen verlegt']),
            $this->eintrag('uuid-2', '2026-03-03', 6),
        ]]));
        $this->assertSame(1, $saved['zeitRev']);

        $loaded = $this->assertOk($this->api->get('load_zeiterfassung'));
        $this->assertSame(1, $loaded['zeitRev']);
        $byUuid = array_column($loaded['entries'], null, 'clientUuid');
        $this->assertCount(2, $byUuid);
        $this->assertEquals(8, $byUuid['uuid-1']['stunden']);
        // Zahlen kommen als JSON-Zahl, nicht als String (PostgreSQL liefert REAL sonst als Text).
        $this->assertIsNotString($byUuid['uuid-1']['stunden']);
        $this->assertSame('2026-03-02', $byUuid['uuid-1']['datum']);
        $this->assertSame('Leitungen verlegt', $byUuid['uuid-1']['bemerkung']);
        $this->assertSame(1, (int) $byUuid['uuid-1']['baustelleId']);
        $this->assertSame('Neubau', $byUuid['uuid-1']['baustelleName']);
    }

    public function testAutoImportBuchtMehrereEintraegeJeProjektOhneDuplikate(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['auto_import_stunden' => true]));
        $this->assertOk($this->api->post('add_user', [
            'username' => 'monteur',
            'password' => 'Start-Passwort-1',
            'role' => 'normal',
            'kuerzel' => 'MM',
        ]));
        $monteur = $this->server->client();
        $this->assertOk($monteur->post('login', ['username' => 'monteur', 'password' => 'Start-Passwort-1']));
        $this->assertOk($monteur->post('change_password', ['old' => 'Start-Passwort-1', 'new' => 'Monteur-Pass-1']));
        $this->saveBaustellen([['id' => 1, 'name' => 'Neubau', 'arbeitszeit' => []]]);

        $entries = [
            $this->eintrag('uuid-1', '2026-03-02', 8, ['id' => 101]),
            $this->eintrag('uuid-2', '2026-03-03', 6, ['id' => 102]),
        ];
        $saved = $this->assertOk($monteur->post('save_zeiterfassung', ['entries' => $entries]));
        $this->assertSame(2, $saved['autoImported']);
        $this->assertSame(0, $this->assertOk($monteur->post('save_zeiterfassung', [
            'entries' => $entries,
            'baseZeitRev' => $saved['zeitRev'],
        ]))['autoImported']);

        $data = $this->assertOk($this->api->get('load'))['data'];
        $bookings = $data['baustellen'][0]['arbeitszeit'];
        $this->assertCount(2, $bookings);
        $this->assertSame([101, 102], array_column($bookings, 'zeitEntryId'));
    }

    public function testUeberschneidendeZeitenWerdenAbgelehnt(): void
    {
        $this->setupAdmin();

        $json = $this->assertStatus(400, $this->api->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('a', '2026-03-02', 4, ['von' => '07:00', 'bis' => '11:00']),
            $this->eintrag('b', '2026-03-02', 4, ['von' => '10:00', 'bis' => '14:00']),
        ]]));

        $this->assertSame('ZE001', $json['code']);
    }

    public function testEndeVorBeginnWirdAbgelehnt(): void
    {
        $this->setupAdmin();

        $json = $this->assertStatus(400, $this->api->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('a', '2026-03-02', 4, ['von' => '12:00', 'bis' => '08:00']),
        ]]));

        $this->assertSame('ZE002', $json['code']);
    }

    public function testUngueltigesDatumWirdAbgelehnt(): void
    {
        $this->setupAdmin();

        $json = $this->assertStatus(400, $this->api->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('a', '02.03.2026', 4),
        ]]));

        $this->assertSame('ZE010', $json['code']);
    }

    public function testVeralteteZeitRevisionFuehrtZuKonflikt(): void
    {
        $this->setupAdmin();
        $rev = $this->assertOk($this->api->post('save_zeiterfassung', ['entries' => [$this->eintrag('a', '2026-03-02', 8)]]))['zeitRev'];
        $this->assertOk($this->api->post('save_zeiterfassung', [
            'entries' => [$this->eintrag('a', '2026-03-02', 8), $this->eintrag('b', '2026-03-03', 8)], 'baseZeitRev' => $rev,
        ]));

        $this->assertStatus(409, $this->api->post('save_zeiterfassung', [
            'entries' => [$this->eintrag('a', '2026-03-02', 7)], 'baseZeitRev' => $rev,
        ]));
    }

    public function testEintraegeSindProBenutzerGetrennt(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertOk($this->api->post('save_zeiterfassung', ['entries' => [$this->eintrag('admin-1', '2026-03-02', 8)]]));
        $this->assertOk($monteur->post('save_zeiterfassung', ['entries' => [$this->eintrag('m-1', '2026-03-02', 5)]]));

        $this->assertSame(['m-1'], array_column($this->assertOk($monteur->get('load_zeiterfassung'))['entries'], 'clientUuid'));
        $this->assertSame(['admin-1'], array_column($this->assertOk($this->api->get('load_zeiterfassung'))['entries'], 'clientUuid'));
    }
}
