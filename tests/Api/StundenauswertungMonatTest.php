<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Services\Stundenauswertung;

final class StundenauswertungMonatTest extends ApiTestCase
{
    public function testMonatRechnetBetrieblichenFeiertagUndAbwesenheitenWieZeituebersicht(): void
    {
        $this->setupAdmin();
        $this->setupStundenauswertungUser('voll');
        $this->setupStundenauswertungUser('versteckt');
        $this->assertOk($this->api->post('set_show_in_zeitverwaltung', ['username' => 'versteckt', 'show' => false]));

        foreach ($this->workdays('2025-03') as $datum) {
            if ($datum === '2025-03-19') {
                continue;
            }
            $typ = match ($datum) {
                '2025-03-20' => 'krank',
                '2025-03-21' => 'urlaub',
                default => 'arbeit',
            };
            $this->insertZeit('voll', $datum, $typ, 8.0);
        }
        $this->insertZeit('versteckt', '2025-03-03', 'arbeit', 8.0);
        $this->insertZeit(self::ADMIN_USER, '2025-03-03', 'arbeit', 8.0);

        $rows = Stundenauswertung::monat($this->server->db(), [
            'custom_feiertage' => '[{"datum":"2025-03-19","name":"Betriebsfeier"}]',
            'ze_custom_typen' => [],
        ], '2025-03');
        $byUser = array_column($rows, null, 'username');

        self::assertSame(['voll'], array_keys($byUser));
        self::assertSame(168.0, $byUser['voll']['soll']);
        self::assertSame(168.0, $byUser['voll']['ist']);
        self::assertSame(0.0, $byUser['voll']['diff']);
        self::assertSame(1, $byUser['voll']['urlaub']);
        self::assertSame(1, $byUser['voll']['krank']);
    }

    public function testGesetzlicheFeiertageOhneEintragSindNeutral(): void
    {
        $this->setupAdmin();
        $this->setupStundenauswertungUser('voll');
        foreach ($this->workdays('2025-04') as $datum) {
            if (in_array($datum, ['2025-04-18', '2025-04-21'], true)) {
                continue;
            }
            $this->insertZeit('voll', $datum, 'arbeit', 8.0);
        }

        $rows = Stundenauswertung::monat($this->server->db(), ['ze_custom_typen' => []], '2025-04');
        $byUser = array_column($rows, null, 'username');

        self::assertSame(176.0, $byUser['voll']['soll']);
        self::assertSame(176.0, $byUser['voll']['ist']);
        self::assertSame(0.0, $byUser['voll']['diff']);
    }

    private function setupStundenauswertungUser(string $username): void
    {
        $this->createActiveUser($username, 'Monteur-Pass-1');
        $this->assertOk($this->api->post('save_user_profile', [
            'username' => $username,
            'sollstundenTag' => 8,
            'arbeitstage' => '1,2,3,4,5',
        ]));
    }

    /** @return list<string> */
    private function workdays(string $monat): array
    {
        $start = new \DateTimeImmutable($monat . '-01');
        $end = $start->modify('last day of this month');
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $days[] = $day->format('Y-m-d');
            }
        }
        return $days;
    }

    private function insertZeit(string $username, string $datum, string $typ, float $stunden): void
    {
        $entryId = (int) $this->server->db()
            ->query("SELECT COALESCE(MAX(entryId), 0) + 1 FROM zeiterfassung WHERE username = " . $this->server->db()->quote($username))
            ->fetchColumn();
        $stmt = $this->server->db()->prepare(
            'INSERT INTO zeiterfassung (username, entryId, datum, typ, stunden, clientUuid, status, createdAt, updatedAt, quelle) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $stmt->execute([
            $username,
            $entryId,
            $datum,
            $typ,
            $stunden,
            $username . '-' . $datum . '-' . $typ,
            'booked_valid',
            $datum . ' 08:00:00',
            $datum . ' 08:00:00',
            'test',
        ]);
    }
}
