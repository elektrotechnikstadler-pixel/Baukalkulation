<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Services\Gleitzeit;

final class GleitzeitSaldoTest extends ApiTestCase
{
    public function testSaldoZaehltErstAbStartdatumUndBisHeuteInklusive(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $saldo = Gleitzeit::saldo(
            $this->server->db(),
            'monteur',
            ['sollstundenTag' => 8, 'arbeitstage' => '1,2,3,4,5'],
            2025,
            ['gleitzeit_startdatum' => '2025-03-17', 'ze_custom_typen' => []],
            new \DateTimeImmutable('2025-03-19'),
        );

        self::assertSame(-24.0, $saldo);
    }

    public function testBetrieblicherFeiertagOhneEintragIstNeutral(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $saldo = Gleitzeit::saldo(
            $this->server->db(),
            'monteur',
            ['sollstundenTag' => 8, 'arbeitstage' => '1,2,3,4,5'],
            2025,
            [
                'gleitzeit_startdatum' => '2025-03-17',
                'custom_feiertage' => [['datum' => '2025-03-19', 'name' => 'Betriebsfeier']],
                'ze_custom_typen' => [],
            ],
            new \DateTimeImmutable('2025-03-19'),
        );

        self::assertSame(-16.0, $saldo);
    }

    public function testWochentagsmodusUndManuelleBuchungenBeachtenDatumsgrenzen(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $db = $this->server->db();
        $stmt = $db->prepare(
            'INSERT INTO gleitzeitkonto_buchungen (username, datum, betrag, kommentar, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?)',
        );
        $stmt->execute(['monteur', '2025-03-16', 10, 'vor Start', self::ADMIN_USER, '2025-03-16 08:00:00']);
        $stmt->execute(['monteur', '2025-03-19', 2, 'im Zeitraum', self::ADMIN_USER, '2025-03-19 08:00:00']);
        $stmt->execute(['monteur', '2025-03-20', 10, 'nach Stichtag', self::ADMIN_USER, '2025-03-20 08:00:00']);

        $saldo = Gleitzeit::saldo(
            $db,
            'monteur',
            [
                'sollzeitJeWochentag' => true,
                'sollstundenMo' => 8,
                'sollstundenDi' => 0,
                'sollstundenMi' => 4,
                'sollstundenDo' => 0,
                'sollstundenFr' => 0,
                'sollstundenSa' => 0,
                'sollstundenSo' => 0,
            ],
            2025,
            ['gleitzeit_startdatum' => '2025-03-17', 'ze_custom_typen' => []],
            new \DateTimeImmutable('2025-03-19'),
        );

        self::assertSame(-10.0, $saldo);
    }

    public function testSaldoFaelleAusGemeinsamerFalllisteInPhpService(): void
    {
        $this->setupAdmin();
        foreach ($this->fallliste()['saldo'] as $index => $fall) {
            $username = $fall['username'] . '-' . $index;
            $this->createActiveUser($username, 'Monteur-Pass-1');
            $db = $this->server->db();
            $this->insertEintraege($db, $username, $fall['eintraege']);
            $this->insertBuchungen($db, $username, $fall['buchungen']);

            $saldo = Gleitzeit::saldo(
                $db,
                $username,
                $fall['mitarbeiter'],
                (int) $fall['jahr'],
                [
                    'gleitzeit_startdatum' => $fall['startdatum'],
                    'ze_custom_typen' => $fall['customTypen'],
                    'custom_feiertage' => $fall['customFeiertage'],
                ],
                new \DateTimeImmutable($fall['heute']),
            );

            self::assertSame((float) $fall['expected'], $saldo, $fall['name']);
        }
    }

    public function testJahreswechselDataUndServiceLiefernFuerVergangenesJahrDenselbenSaldo(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->assertOk($this->api->post('save_settings', [
            'gleitzeit_enabled' => true,
            'gleitzeit_startdatum' => '2025-03-17',
        ]));
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => 'monteur',
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 0,
            'sollstundenMi' => 0,
            'sollstundenDo' => 0,
            'sollstundenFr' => 0,
            'sollstundenSa' => 0,
            'sollstundenSo' => 0,
        ]));

        $serviceSaldo = Gleitzeit::saldo(
            $this->server->db(),
            'monteur',
            [
                'sollzeitJeWochentag' => true,
                'sollstundenMo' => 8,
                'sollstundenDi' => 0,
                'sollstundenMi' => 0,
                'sollstundenDo' => 0,
                'sollstundenFr' => 0,
                'sollstundenSa' => 0,
                'sollstundenSo' => 0,
            ],
            2025,
            ['gleitzeit_startdatum' => '2025-03-17', 'ze_custom_typen' => []],
            new \DateTimeImmutable('2026-01-15'),
        );
        $result = $this->assertOk($this->api->get('get_jahreswechsel_data', ['year' => 2025]));
        $users = array_column($result['users'], null, 'username');

        self::assertSame(-320.0, $serviceSaldo);
        self::assertSame($serviceSaldo, (float) $users['monteur']['gleitzeitSaldo']);
    }

    /** @return array<string,mixed> */
    private function fallliste(): array
    {
        $json = json_decode(
            (string) file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'sollzeit' . DIRECTORY_SEPARATOR . 'faelle.json'),
            true,
        );
        self::assertIsArray($json);
        return $json;
    }

    /** @param list<array<string,mixed>> $eintraege */
    private function insertEintraege(\PDO $db, string $username, array $eintraege): void
    {
        $stmt = $db->prepare(
            'INSERT INTO zeiterfassung (username, entryId, datum, typ, stunden, clientUuid, status, createdAt, updatedAt, quelle) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $entryId = 1;
        foreach ($eintraege as $eintrag) {
            $datum = (string) $eintrag['datum'];
            $stmt->execute([
                $username,
                $entryId,
                $datum,
                (string) $eintrag['typ'],
                (float) ($eintrag['stunden'] ?? 0),
                $username . '-' . $entryId,
                'booked_valid',
                $datum . ' 08:00:00',
                $datum . ' 08:00:00',
                'test',
            ]);
            $entryId++;
        }
    }

    /** @param list<array<string,mixed>> $buchungen */
    private function insertBuchungen(\PDO $db, string $username, array $buchungen): void
    {
        $stmt = $db->prepare(
            'INSERT INTO gleitzeitkonto_buchungen (username, datum, betrag, kommentar, erstellt_von, erstellt_am) VALUES (?,?,?,?,?,?)',
        );
        foreach ($buchungen as $buchung) {
            $datum = (string) $buchung['datum'];
            $stmt->execute([
                $username,
                $datum,
                (float) $buchung['betrag'],
                (string) ($buchung['kommentar'] ?? ''),
                self::ADMIN_USER,
                $datum . ' 08:00:00',
            ]);
        }
    }
}
