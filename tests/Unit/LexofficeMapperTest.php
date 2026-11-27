<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Lexoffice\LexofficeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/lexoffice/backend/LexofficeMapper.php';

/** Falllisten tests/fixtures/lexoffice/kontakte.json und zuordnung.json (E-037). */
final class LexofficeMapperTest extends TestCase
{
    private static function json(string $datei): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/lexoffice/' . $datei), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>}> */
    public static function kontakte(): iterable
    {
        foreach (self::json('kontakte.json') as $fall) {
            yield $fall['name'] => [$fall['kunde'], $fall['kontakt']];
        }
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>}> */
    public static function zuordnungen(): iterable
    {
        foreach (self::json('zuordnung.json')['faelle'] as $fall) {
            yield $fall['name'] => [$fall['kunde'], $fall['ergebnis']];
        }
    }

    #[DataProvider('kontakte')]
    public function testKontakt(array $kunde, array $erwartet): void
    {
        $ist = json_decode((string) json_encode(LexofficeMapper::kontakt($kunde)), true);
        $this->assertSame($erwartet, $ist);
    }

    #[DataProvider('zuordnungen')]
    public function testZuordnung(array $kunde, array $erwartet): void
    {
        $zuordnung = self::json('zuordnung.json');
        $kandidaten = self::json($zuordnung['kandidaten'])['body']['content'];
        $this->assertSame($erwartet, LexofficeMapper::zuordnen($kunde, $kandidaten));
    }
}
