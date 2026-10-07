<?php

declare(strict_types=1);

namespace Tests\Api;

final class SollzeitProfilTest extends ApiTestCase
{
    public function testWochenwerteWerdenMitNullUndGrenzwertGespeichertUndGeladen(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => self::ADMIN_USER,
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 0,
            'sollstundenDi' => 8,
            'sollstundenMi' => 8,
            'sollstundenDo' => 8,
            'sollstundenFr' => 5.5,
            'sollstundenSa' => 0,
            'sollstundenSo' => 24,
        ]));

        $profile = $this->assertOk($this->api->get('get_user_profile', ['username' => self::ADMIN_USER]))['profile'];
        self::assertTrue($profile['sollzeitJeWochentag']);
        self::assertEquals(0, $profile['sollstundenMo']);
        self::assertEquals(5.5, $profile['sollstundenFr']);
        self::assertEquals(24, $profile['sollstundenSo']);
    }

    public function testUngueltigeWochenwerteWerdenOhneSpeichernAbgelehnt(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => self::ADMIN_USER,
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 7.5,
        ]));

        foreach ([-1, 24.5, 'abc'] as $invalid) {
            $this->assertStatus(400, $this->api->post('save_user_profile', [
                'username' => self::ADMIN_USER,
                'sollstundenMo' => $invalid,
            ]));
            $profile = $this->assertOk($this->api->get('get_user_profile', ['username' => self::ADMIN_USER]))['profile'];
            self::assertSame(7.5, $profile['sollstundenMo']);
        }
    }

    public function testNormalerBenutzerKannFremdesProfilNichtLesenOderAendern(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->get('get_user_profile', ['username' => self::ADMIN_USER]));
        $this->assertStatus(403, $monteur->post('save_user_profile', [
            'username' => self::ADMIN_USER,
            'sollstundenMo' => 4,
        ]));
    }

    public function testListenLiefernWochentagsSollfelderAus(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'monteur',
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 7,
            'sollstundenMi' => 6,
            'sollstundenDo' => 5,
            'sollstundenFr' => 4,
            'sollstundenSa' => 3,
            'sollstundenSo' => 2,
        ]));

        foreach (['list_users', 'list_users_basic', 'get_sollstunden_extended'] as $action) {
            $result = $this->assertOk($this->api->get($action));
            $users = array_column($result['users'], null, 'username');

            self::assertArrayHasKey('monteur', $users, $action);
            self::assertTrue($users['monteur']['sollzeitJeWochentag'], $action);
            self::assertSame(8.0, (float) $users['monteur']['sollstundenMo'], $action);
            self::assertSame(7.0, (float) $users['monteur']['sollstundenDi'], $action);
            self::assertSame(6.0, (float) $users['monteur']['sollstundenMi'], $action);
            self::assertSame(5.0, (float) $users['monteur']['sollstundenDo'], $action);
            self::assertSame(4.0, (float) $users['monteur']['sollstundenFr'], $action);
            self::assertSame(3.0, (float) $users['monteur']['sollstundenSa'], $action);
            self::assertSame(2.0, (float) $users['monteur']['sollstundenSo'], $action);
        }
    }

    public function testJahreswechselSaldoWertetUrlaubAlsSollUndAbwesendMitNull(): void
    {
        $this->setupAdmin();
        $mitarbeiter = $this->createActiveUser('saldo-user', 'Saldo-Pass-1');
        $this->assertOk($this->api->post('save_settings', [
            'gleitzeit_enabled' => true,
            'gleitzeit_startdatum' => '2025-01-01',
        ]));
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'saldo-user',
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 8,
            'sollstundenMi' => 0,
            'sollstundenDo' => 0,
            'sollstundenFr' => 0,
            'sollstundenSa' => 0,
            'sollstundenSo' => 0,
        ]));
        $this->assertOk($mitarbeiter->post('save_zeiterfassung', ['entries' => [
            $this->eintrag('urlaub-2025', '2025-01-13', 'urlaub'),
            $this->eintrag('abwesend-2025', '2025-01-14', 'abwesend'),
        ]]));

        $result = $this->assertOk($this->api->get('get_jahreswechsel_data', ['year' => 2025]));
        $users = array_column($result['users'], null, 'username');
        self::assertSame(-800.0, (float) $users['saldo-user']['gleitzeitSaldo']);
    }

    private function eintrag(string $uuid, string $datum, string $typ): array
    {
        return [
            'clientUuid' => $uuid,
            'datum' => $datum,
            'typ' => $typ,
            'baustelleId' => 0,
            'stunden' => 0,
            'von' => '',
            'bis' => '',
            'pause' => 0,
            'bemerkung' => '',
        ];
    }
}
