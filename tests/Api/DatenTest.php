<?php
declare(strict_types=1);

namespace Tests\Api;

final class DatenTest extends ApiTestCase
{
    public function testLeereInstallationLiefertLeereDaten(): void
    {
        $this->setupAdmin();

        $data = $this->assertOk($this->api->get('load'))['data'];

        $this->assertSame([], $data['baustellen']);
        $this->assertSame(0, $data['rev']);
        $this->assertSame(1, $data['nextBaustelleId']);
    }

    public function testBaustellenUndKatalogeSpeichernUndLaden(): void
    {
        $this->setupAdmin();

        $saved = $this->assertOk($this->api->post('save', ['data' => [
            'baustellen' => [[
                'id' => 1, 'name' => 'Neubau Musterstraße', 'kundeId' => null,
                'projektNr' => 'P-2026-001',
                'material' => [['id' => 1, 'bezeichnung' => 'NYM-J 3x1,5', 'menge' => 100, 'ek' => 0.89]],
            ]],
            'pauschalen'      => [['id' => 1, 'name' => 'Anfahrt', 'preis' => 45.5]],
            'stundenKatalog'  => [['id' => 1, 'kategorie' => 'Geselle', 'preis' => 58, 'fixkosten' => 12.25]],
            'materialKatalog' => [['id' => 1, 'bezeichnung' => 'Schalter', 'einheit' => 'Stk', 'ek' => 3.99, 'aufschlag' => 25, 'artikelNr' => 'S-1']],
        ]]));
        $this->assertSame(1, $saved['rev']);

        $data = $this->assertOk($this->api->get('load'))['data'];
        $this->assertSame(1, $data['rev']);
        $this->assertCount(1, $data['baustellen']);
        $b = $data['baustellen'][0];
        $this->assertSame(1, $b['id']);
        $this->assertSame('Neubau Musterstraße', $b['name']);
        $this->assertSame('P-2026-001', $b['projektNr']);
        $this->assertEquals(0.89, $b['material'][0]['ek']);

        // Beträge werden intern in Cent gespeichert und müssen exakt zurückkommen.
        $this->assertEquals(45.5, $data['pauschalen'][0]['preis']);
        $this->assertEquals(58, $data['stundenKatalog'][0]['preis']);
        $this->assertEquals(12.25, $data['stundenKatalog'][0]['fixkosten']);
        $this->assertEquals(3.99, $data['materialKatalog'][0]['ek']);
        $this->assertEquals(25, $data['materialKatalog'][0]['aufschlag']);
        $this->assertSame(2, $data['nextBaustelleId']);
    }

    public function testVeralteteRevisionFuehrtZuKonflikt(): void
    {
        $this->setupAdmin();
        $rev1 = $this->saveBaustellen([['id' => 1, 'name' => 'A']])['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'A2']], $rev1);

        $conflict = $this->assertStatus(409, $this->api->post('save', [
            'data' => ['baustellen' => [['id' => 1, 'name' => 'A-alt']]], 'baseRev' => $rev1,
        ]));

        $this->assertTrue($conflict['conflict']);
        $this->assertSame(2, $conflict['currentRev']);
        $this->assertSame('A2', $this->assertOk($this->api->get('load'))['data']['baustellen'][0]['name']);
    }

    public function testSaveOhneRevisionWirdBeiVorhandenenDatenAbgelehnt(): void
    {
        $this->setupAdmin();
        $this->saveBaustellen([['id' => 1, 'name' => 'A']]);

        $this->assertStatus(409, $this->api->post('save', ['data' => ['baustellen' => [['id' => 1, 'name' => 'B']]]]));
    }

    public function testAlleBaustellenLoeschenBrauchtBestaetigung(): void
    {
        $this->setupAdmin();
        $rev = $this->saveBaustellen([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']])['rev'];

        $json = $this->assertStatus(409, $this->api->post('save', ['data' => ['baustellen' => []], 'baseRev' => $rev]));

        $this->assertTrue($json['needsConfirm']);
        $this->assertCount(2, $this->assertOk($this->api->get('load'))['data']['baustellen']);
    }

    public function testBaustelleEntfernen(): void
    {
        $this->setupAdmin();
        $rev = $this->saveBaustellen([['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']])['rev'];

        $this->saveBaustellen([['id' => 2, 'name' => 'B']], $rev);

        $ids = array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'id');
        $this->assertSame([2], $ids);
    }

    public function testDatenOhneFeldDataWerdenAbgelehnt(): void
    {
        $this->setupAdmin();

        $this->assertStatus(400, $this->api->post('save', []));
    }
}
