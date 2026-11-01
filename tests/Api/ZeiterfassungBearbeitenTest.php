<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiClient;

final class ZeiterfassungBearbeitenTest extends ApiTestCase
{
    private ApiClient $monteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['auto_import_stunden' => true]));
        $this->assertOk($this->api->post('add_user', [
            'username' => 'monteur',
            'password' => 'Start-Passwort-1',
            'role' => 'normal',
            'kuerzel' => 'MM',
        ]));

        $this->monteur = $this->server->client();
        $this->assertOk($this->monteur->post('login', ['username' => 'monteur', 'password' => 'Start-Passwort-1']));
        $this->assertOk($this->monteur->post('change_password', ['old' => 'Start-Passwort-1', 'new' => 'Monteur-Pass-1']));

        $this->assertOk($this->api->post('save', ['data' => [
            'baustellen' => [
                ['id' => 1, 'name' => 'Neubau', 'arbeitszeit' => []],
            ],
            'stundenKatalog' => [
                ['id' => 10, 'kategorie' => 'Monteur', 'preis' => 50, 'fixkosten' => 0],
            ],
        ]]));
    }

    private function eintrag(int $id, string $uuid, string $datum, float $stunden): array
    {
        return [
            'id' => $id,
            'clientUuid' => $uuid,
            'datum' => $datum,
            'typ' => 'arbeit',
            'baustelleId' => 1,
            'stunden' => $stunden,
            'stundenKatId' => 10,
            'von' => '07:00',
            'bis' => sprintf('%02d:00', 7 + (int) $stunden),
            'pause' => 0,
            'bemerkung' => '',
        ];
    }

    private function aktuelleZeitRev(): int
    {
        return (int) $this->assertOk($this->monteur->get('load_zeiterfassung'))['zeitRev'];
    }

    /** @param list<array<string, mixed>> $entries */
    private function saveDesktopSnapshot(array $entries, int $baseZeitRev): array
    {
        return $this->assertOk($this->monteur->post('save_zeiterfassung', [
            'entries' => $entries,
            'fullSnapshot' => true,
            'quelle' => 'desktop',
            'baseZeitRev' => $baseZeitRev,
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function zeiteintraege(): array
    {
        return array_column($this->assertOk($this->monteur->get('load_zeiterfassung'))['entries'], null, 'id');
    }

    /** @return list<array<string, mixed>> */
    private function buchungen(int $baustelleId): array
    {
        $baustellen = array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], null, 'id');
        return array_values(array_filter(
            $baustellen[$baustelleId]['arbeitszeit'] ?? [],
            static fn(array $a): bool => !empty($a['autoImport']),
        ));
    }

    public function testDesktopFullSnapshotAktualisiertEintragUndBuchungOhneDoppelung(): void
    {
        $first = $this->saveDesktopSnapshot([
            $this->eintrag(101, 'uuid-101', '2026-03-02', 8),
        ], $this->aktuelleZeitRev());

        $this->saveDesktopSnapshot([
            $this->eintrag(101, 'uuid-101', '2026-03-03', 6),
        ], (int) $first['zeitRev']);

        $entries = $this->zeiteintraege();
        self::assertCount(1, $entries);
        self::assertSame('uuid-101', $entries[101]['clientUuid']);
        self::assertSame('2026-03-03', $entries[101]['datum']);
        self::assertEquals(6, $entries[101]['stunden']);

        $buchungen = $this->buchungen(1);
        self::assertCount(1, $buchungen);
        self::assertSame([101], array_column($buchungen, 'zeitEntryId'));
        self::assertEquals(6, $buchungen[0]['stunden']);
        self::assertSame('2026-03-03', $buchungen[0]['datum']);
    }

    public function testVeralteteBaseZeitRevLiefertNeedsMergeUndAendertNichts(): void
    {
        $first = $this->saveDesktopSnapshot([
            $this->eintrag(101, 'uuid-101', '2026-03-02', 8),
        ], $this->aktuelleZeitRev());

        $second = $this->saveDesktopSnapshot([
            $this->eintrag(101, 'uuid-101', '2026-03-04', 7),
        ], (int) $first['zeitRev']);

        $conflict = $this->assertStatus(409, $this->monteur->post('save_zeiterfassung', [
            'entries' => [$this->eintrag(101, 'uuid-101', '2026-03-05', 5)],
            'fullSnapshot' => true,
            'quelle' => 'desktop',
            'baseZeitRev' => (int) $first['zeitRev'],
        ]));

        self::assertTrue((bool) ($conflict['needsMerge'] ?? false));
        self::assertSame('zeit_conflict', $conflict['code'] ?? '');

        $entries = $this->zeiteintraege();
        self::assertSame('2026-03-04', $entries[101]['datum']);
        self::assertEquals(7, $entries[101]['stunden']);
        self::assertSame((int) $second['zeitRev'], $this->aktuelleZeitRev());

        $buchungen = $this->buchungen(1);
        self::assertCount(1, $buchungen);
        self::assertEquals(7, $buchungen[0]['stunden']);
        self::assertSame('2026-03-04', $buchungen[0]['datum']);
    }
}
