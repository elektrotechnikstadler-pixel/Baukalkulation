<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RechnungsModus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Gemeinsame Fallliste mit tests/js/rechnungen-modus.test.mjs (E-036, E-037). */
final class RechnungsModusTest extends TestCase
{
    /** @return iterable<string, array{array<string,mixed>}> */
    public static function faelle(): iterable
    {
        $faelle = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/lexoffice/rechnungen-modus.json'), true);
        foreach ($faelle as $fall) {
            yield $fall['name'] => [$fall];
        }
    }

    /** @param array<string,mixed> $fall */
    #[DataProvider('faelle')]
    public function testFallliste(array $fall): void
    {
        $this->assertSame($fall['aktiv'], RechnungsModus::aktiv($fall['settings']));
        $this->assertSame($fall['lesbar'], RechnungsModus::lesbar($fall['settings'], $fall['rolle']));
        $this->assertSame($fall['inLexware'], RechnungsModus::inLexware($fall['settings']));
    }
}
