<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Feiertage;
use App\Services\Sollzeit;
use PHPUnit\Framework\TestCase;

final class SollzeitParityTest extends TestCase
{
    public function testTagesSollFaelleAusGemeinsamerFallliste(): void
    {
        foreach ($this->fallliste()['tagesSoll'] as $fall) {
            self::assertSame(
                (float) $fall['expected'],
                Sollzeit::tagesSoll($fall['mitarbeiter'], $fall['datum']),
                $fall['name'],
            );
        }
    }

    public function testIstStundenFaelleAusGemeinsamerFallliste(): void
    {
        foreach ($this->fallliste()['istStundenEintrag'] as $fall) {
            self::assertSame(
                (float) $fall['expected'],
                Sollzeit::istStundenEintrag($fall['mitarbeiter'], $fall['eintrag'], $fall['customTypen']),
                $fall['name'],
            );
        }
    }

    public function testFeiertageFaelleAusGemeinsamerFallliste(): void
    {
        foreach ($this->fallliste()['feiertage'] as $fall) {
            $feiertage = Feiertage::fuerJahr((int) $fall['jahr'], $fall['customFeiertage']);

            self::assertSame($fall['expectedDates'], array_keys($feiertage), $fall['name']);
            foreach ($fall['expectedNames'] as $datum => $name) {
                self::assertSame($name, $feiertage[$datum], $fall['name'] . ' ' . $datum);
            }
        }
    }

    public function testUrlaubsanspruchFaelleAusGemeinsamerFallliste(): void
    {
        foreach ($this->fallliste()['urlaubsanspruch'] as $fall) {
            self::assertSame(
                (float) $fall['expected'],
                Sollzeit::urlaubsanspruch($fall['mitarbeiter'], (int) $fall['jahr']),
                $fall['name'],
            );
        }
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
}
