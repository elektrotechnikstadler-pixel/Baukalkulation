<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyHelpersTest extends TestCase
{
    public static function euroCents(): iterable
    {
        yield 'glatt'          => [25.0, 2500];
        yield 'Float-Rauschen' => [14.99, 1499];
        yield 'Rundung'        => [0.005, 1];
        yield 'negativ'        => [-3.5, -350];
        yield 'String'         => ['12.34', 1234];
    }

    #[DataProvider('euroCents')]
    public function testMoneyToCents(float|string $euro, int $cents): void
    {
        $this->assertSame($cents, money_to_cents($euro));
    }

    public function testLeereWerteSindNull(): void
    {
        $this->assertSame(0, money_to_cents(null));
        $this->assertSame(0, money_to_cents(''));
        $this->assertSame(0.0, money_from_cents(null));
        $this->assertSame(0.0, money_round(''));
    }

    public function testRundreiseEuroCentsEuro(): void
    {
        foreach ([0.01, 0.89, 3.99, 12.25, 45.5, 1234.56] as $euro) {
            $this->assertSame($euro, money_from_cents(money_to_cents($euro)));
        }
    }

    public function testMoneyRoundEntferntFloatRauschen(): void
    {
        $this->assertSame(14.99, money_round(14.989999999999999));
    }
}
