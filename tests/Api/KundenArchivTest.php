<?php

declare(strict_types=1);

namespace Tests\Api;

final class KundenArchivTest extends ApiTestCase
{
    public function testListArchiveLiefertKundeIdAuchWennFehlend(): void
    {
        $this->setupAdmin();

        $kundeId = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Kunde fuer Archiv']))['kunde']['id'];
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 1, 'name' => 'Mit Kunde', 'kundeId' => $kundeId],
            ['id' => 2, 'name' => 'Ohne Kunde'],
        ], $rev);

        $this->assertOk($this->api->post('archive_baustelle', ['baustelleId' => 1]));
        $this->assertOk($this->api->post('archive_baustelle', ['baustelleId' => 2]));

        $archives = $this->assertOk($this->api->get('list_archive'))['archives'];
        $byId = [];
        foreach ($archives as $entry) {
            $byId[(int) ($entry['id'] ?? 0)] = $entry;
        }

        $this->assertArrayHasKey(1, $byId);
        $this->assertArrayHasKey('kundeId', $byId[1], 'kundeId muss fuer verknuepfte Archive vorhanden sein.');
        $this->assertSame($kundeId, $byId[1]['kundeId']);

        $this->assertArrayHasKey(2, $byId);
        $this->assertArrayHasKey('kundeId', $byId[2], 'kundeId muss auch ohne Zuordnung als null vorhanden sein.');
        $this->assertNull($byId[2]['kundeId']);
    }

    public function testListArchiveOrdnetMehrereKundenKorrektZu(): void
    {
        $this->setupAdmin();

        $kundeA = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Kunde A']))['kunde']['id'];
        $kundeB = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Kunde B']))['kunde']['id'];
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 11, 'name' => 'Archiv A', 'kundeId' => $kundeA],
            ['id' => 12, 'name' => 'Archiv B', 'kundeId' => $kundeB],
        ], $rev);

        $this->assertOk($this->api->post('archive_baustelle', ['baustelleId' => 11]));
        $this->assertOk($this->api->post('archive_baustelle', ['baustelleId' => 12]));

        $archives = $this->assertOk($this->api->get('list_archive'))['archives'];
        $byId = [];
        foreach ($archives as $entry) {
            $byId[(int) ($entry['id'] ?? 0)] = $entry;
        }

        $this->assertSame($kundeA, $byId[11]['kundeId'] ?? null);
        $this->assertSame($kundeB, $byId[12]['kundeId'] ?? null);
    }
}
