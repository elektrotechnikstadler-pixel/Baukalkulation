<?php

declare(strict_types=1);

namespace Tests\Api;

final class ZeiterfassungAdminTest extends ApiTestCase
{
    public function testAdminZeitraumbuchungUeberspringtGesetzlicheFeiertage(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->speichereWochentagsprofil('monteur', ['Mo' => 8, 'Di' => 8, 'Mi' => 8, 'Do' => 8, 'Fr' => 8]);

        $result = $this->assertOk($this->api->post('admin_add_zeiterfassung', [
            'username' => 'monteur',
            'datum' => '2025-04-17',
            'datumBis' => '2025-04-22',
            'typ' => 'urlaub',
            'stunden' => 0,
        ]));

        self::assertSame(['2025-04-17', '2025-04-22'], array_column($result['inserted'], 'datum'));
        self::assertSame([8.0, 8.0], array_map(static fn(array $row): float => (float) $row['stunden'], $result['inserted']));
    }

    public function testAdminZeitraumbuchungNutztWochentagsmodusFuerSamstagUndNulltage(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('samstag', 'Monteur-Pass-1');
        $this->speichereWochentagsprofil('samstag', ['Mo' => 0, 'Di' => 0, 'Mi' => 0, 'Do' => 0, 'Fr' => 0, 'Sa' => 4]);

        $result = $this->assertOk($this->api->post('admin_add_zeiterfassung', [
            'username' => 'samstag',
            'datum' => '2025-03-10',
            'datumBis' => '2025-03-15',
            'typ' => 'arbeit',
            'stunden' => 4,
        ]));

        self::assertSame(['2025-03-15'], array_column($result['inserted'], 'datum'));
    }

    public function testAdminZeitraumbuchungPrueftUrlaubslimitMitTatsaechlichAngelegtenTagen(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('urlaub', 'Monteur-Pass-1');
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'urlaub',
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 8,
            'sollstundenMi' => 0,
            'sollstundenDo' => 8,
            'sollstundenFr' => 8,
            'sollstundenSa' => 0,
            'sollstundenSo' => 0,
            'urlaubstageProJahr' => ['2025' => 3],
        ]));

        $result = $this->assertOk($this->api->post('admin_add_zeiterfassung', [
            'username' => 'urlaub',
            'datum' => '2025-04-21',
            'datumBis' => '2025-04-25',
            'typ' => 'urlaub',
            'stunden' => 0,
        ]));

        self::assertSame(['2025-04-22', '2025-04-24', '2025-04-25'], array_column($result['inserted'], 'datum'));
        self::assertSame([8.0, 8.0, 8.0], array_map(static fn(array $row): float => (float) $row['stunden'], $result['inserted']));
    }

    public function testUrlaubsanspruchNullLehntUrlaubAb(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('keinurlaub', 'Monteur-Pass-1');
        $this->speichereWochentagsprofil('keinurlaub', ['Mo' => 8, 'Di' => 8, 'Mi' => 8, 'Do' => 8, 'Fr' => 8]);
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'keinurlaub',
            'urlaubstageProJahr' => ['2025' => 0],
        ]));

        $this->assertStatus(400, $this->api->post('admin_add_zeiterfassung', [
            'username' => 'keinurlaub',
            'datum' => '2025-03-17',
            'typ' => 'urlaub',
            'stunden' => 0,
        ]));
    }

    public function testNormalerBenutzerDarfKeineAdminZeiterfassungAnlegen(): void
    {
        $this->setupAdmin();
        $normal = $this->createActiveUser('normal', 'Monteur-Pass-1');

        $this->assertStatus(403, $normal->post('admin_add_zeiterfassung', [
            'username' => 'normal',
            'datum' => '2025-03-17',
            'typ' => 'arbeit',
            'stunden' => 8,
        ]));
    }

    /** @param array<string,float|int> $stunden */
    private function speichereWochentagsprofil(string $username, array $stunden): void
    {
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => $username,
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => $stunden['Mo'] ?? 0,
            'sollstundenDi' => $stunden['Di'] ?? 0,
            'sollstundenMi' => $stunden['Mi'] ?? 0,
            'sollstundenDo' => $stunden['Do'] ?? 0,
            'sollstundenFr' => $stunden['Fr'] ?? 0,
            'sollstundenSa' => $stunden['Sa'] ?? 0,
            'sollstundenSo' => $stunden['So'] ?? 0,
        ]));
    }
}
