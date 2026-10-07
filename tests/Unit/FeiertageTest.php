<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Feiertage;
use PHPUnit\Framework\TestCase;

final class FeiertageTest extends TestCase
{
    public function testBayerischeFeiertage2025MitCustomDesJahresSortiert(): void
    {
        $feiertage = Feiertage::fuerJahr(2025, [
            ['datum' => '2025-03-19', 'name' => 'Betriebsfeier'],
            ['datum' => '2024-12-24', 'name' => 'Vorjahr'],
            ['datum' => '2026-01-02', 'name' => 'Folgejahr'],
        ]);

        self::assertSame([
            '2025-01-01',
            '2025-01-06',
            '2025-03-19',
            '2025-04-18',
            '2025-04-21',
            '2025-05-01',
            '2025-05-29',
            '2025-06-09',
            '2025-06-19',
            '2025-08-15',
            '2025-10-03',
            '2025-11-01',
            '2025-12-25',
            '2025-12-26',
        ], array_keys($feiertage));
        self::assertCount(14, $feiertage);
        self::assertSame('Betriebsfeier', $feiertage['2025-03-19']);
        self::assertArrayNotHasKey('2024-12-24', $feiertage);
        self::assertArrayNotHasKey('2026-01-02', $feiertage);
    }

    public function testCustomAusEinstellungenAkzeptiertJsonStringArrayUndUngueltigeWerte(): void
    {
        $custom = [['datum' => '2025-03-19', 'name' => 'Betriebsfeier']];

        self::assertSame($custom, Feiertage::customAusEinstellungen([
            'custom_feiertage' => '[{"datum":"2025-03-19","name":"Betriebsfeier"}]',
        ]));
        self::assertSame($custom, Feiertage::customAusEinstellungen(['custom_feiertage' => $custom]));
        self::assertSame([], Feiertage::customAusEinstellungen(['custom_feiertage' => '{kaputt']));
        self::assertSame([], Feiertage::customAusEinstellungen(['custom_feiertage' => 42]));
        self::assertSame([], Feiertage::customAusEinstellungen([]));
    }
}
