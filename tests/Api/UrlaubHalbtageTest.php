<?php

declare(strict_types=1);

namespace Tests\Api;

/** Halbe Urlaubstage: Buchung, Anspruch und Limit in 0,5-Schritten. */
final class UrlaubHalbtageTest extends ApiTestCase
{
    public function testHalbeUrlaubstageWerdenGebuchtGezaehltUndBegrenzt(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('halb', 'Monteur-Pass-1');
        $this->speichereProfil('halb');
        $this->assertOk($this->api->post('set_urlaubstage', ['username' => 'halb', 'year' => 2025, 'urlaubstage' => '1,5']));

        $profil = $this->assertOk($monteur->get('get_user_sollstunden'));
        self::assertEqualsWithDelta(1.5, (float) $profil['urlaubstageProJahr']['2025'], 0.0001);

        $eintraege = [
            ['id' => 1, 'clientUuid' => 'h-1', 'datum' => '2025-03-17', 'typ' => 'urlaub', 'stunden' => 0, 'tagesanteil' => 0.5],
            ['id' => 2, 'clientUuid' => 'h-2', 'datum' => '2025-03-18', 'typ' => 'urlaub', 'stunden' => 0],
        ];
        $this->assertOk($monteur->post('save_zeiterfassung', ['entries' => $eintraege]));

        $geladen = array_column($this->assertOk($monteur->get('load_zeiterfassung'))['entries'], null, 'id');
        self::assertEqualsWithDelta(0.5, (float) $geladen[1]['tagesanteil'], 0.0001);
        self::assertEqualsWithDelta(4.0, (float) $geladen[1]['stunden'], 0.0001, 'Halber Tag = halbes Tages-Soll');
        self::assertEqualsWithDelta(1.0, (float) $geladen[2]['tagesanteil'], 0.0001);
        self::assertEqualsWithDelta(8.0, (float) $geladen[2]['stunden'], 0.0001);

        // Alter Client ohne tagesanteil: halber Tag bleibt erhalten.
        unset($eintraege[0]['tagesanteil']);
        $this->assertOk($monteur->post('save_zeiterfassung', ['entries' => $eintraege]));
        $geladen = array_column($this->assertOk($monteur->get('load_zeiterfassung'))['entries'], null, 'id');
        self::assertEqualsWithDelta(0.5, (float) $geladen[1]['tagesanteil'], 0.0001);

        // 1,5 Tage genommen = Anspruch ausgeschöpft; ein weiterer halber Tag überschreitet das Limit.
        $eintraege[] = ['id' => 3, 'clientUuid' => 'h-3', 'datum' => '2025-03-19', 'typ' => 'urlaub', 'stunden' => 0, 'tagesanteil' => 0.5];
        $this->assertStatus(400, $monteur->post('save_zeiterfassung', ['entries' => $eintraege]));
    }

    public function testAdminNachtragUndBearbeitenMitHalbemTag(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('nachtrag', 'Monteur-Pass-1');
        $this->speichereProfil('nachtrag');
        $this->assertOk($this->api->post('set_urlaubstage', ['username' => 'nachtrag', 'year' => 2025, 'urlaubstage' => 1]));

        $result = $this->assertOk($this->api->post('admin_add_zeiterfassung', [
            'username' => 'nachtrag',
            'datum' => '2025-03-17',
            'datumBis' => '2025-03-18',
            'typ' => 'urlaub',
            'stunden' => 0,
            'tagesanteil' => 0.5,
        ]));
        self::assertSame([4.0, 4.0], array_map(static fn(array $row): float => (float) $row['stunden'], $result['inserted']));

        // Zwei halbe Tage = 1 Tag; ein ganzer Tag statt eines halben würde das Limit überschreiten.
        $entryId = (int) $result['inserted'][0]['entryId'];
        $this->assertStatus(400, $this->api->post('admin_edit_zeiterfassung', [
            'username' => 'nachtrag',
            'entryId' => $entryId,
            'typ' => 'urlaub',
            'tagesanteil' => 1,
        ]));

        $this->assertOk($this->api->post('set_urlaubstage', ['username' => 'nachtrag', 'year' => 2025, 'urlaubstage' => 1.5]));
        $edit = $this->assertOk($this->api->post('admin_edit_zeiterfassung', [
            'username' => 'nachtrag',
            'entryId' => $entryId,
            'typ' => 'urlaub',
            'tagesanteil' => 1,
        ]));
        self::assertEqualsWithDelta(8.0, (float) $edit['stunden'], 0.0001);
    }

    private function speichereProfil(string $username): void
    {
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => $username,
            'sollstundenTag' => 8,
            'arbeitstage' => '1,2,3,4,5',
        ]));
    }
}
