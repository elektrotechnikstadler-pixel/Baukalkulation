<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Lexoffice\LexofficeBeleg;
use App\Services\ProjektPositionen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/lexoffice/backend/LexofficeBeleg.php';

/** Fallliste tests/fixtures/lexoffice/projekte.json: Projekt → Positionen → Lexware-Beleg (E-037). */
final class LexofficeBelegTest extends TestCase
{
    private static function liste(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/lexoffice/projekte.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function faelle(): iterable
    {
        foreach (self::liste()['faelle'] as $fall) {
            yield $fall['name'] => [$fall];
        }
    }

    #[DataProvider('faelle')]
    public function testBeleg(array $fall): void
    {
        $basis = self::liste()['basis'];
        $positionen = ProjektPositionen::aus(
            $fall['projekt'] ?? $basis['projekt'],
            array_combine(array_map('intval', array_keys($basis['pauschalen'])), $basis['pauschalen']),
            array_combine(array_map('intval', array_keys($basis['stundenKategorien'])), $basis['stundenKategorien']),
        );
        foreach ($positionen as &$p) {
            $p['abgerechnet'] = in_array($p['art'] . ':' . $p['id'], $fall['abgerechnet'], true);
        }
        unset($p);
        $auswahl = LexofficeBeleg::auswahl($fall['auswahl']);
        $beleg = LexofficeBeleg::bilden($positionen, $auswahl, $fall['firma']);

        foreach ($fall['erwartet'] as $feld => $wert) {
            $this->assertEquals($wert, $beleg[$feld], $feld);
        }
        if (isset($fall['anfrage'])) {
            $anfrage = LexofficeBeleg::anfrage($beleg, $auswahl, $fall['anfrage']['contactId'], $fall['anfrage']['heute']);
            foreach ($fall['anfrage']['erwartet'] as $feld => $wert) {
                $this->assertEquals($wert, $anfrage[$feld], 'anfrage.' . $feld);
            }
            $this->assertSame($fall['auswahl']['belegart'] === 'angebot', isset($anfrage['expirationDate']));
            $this->assertSame($fall['auswahl']['belegart'] === 'rechnung', isset($anfrage['shippingConditions']));
        }
    }

    public function testUngueltigeAuswahlFaelltAufStandardsZurueck(): void
    {
        $a = LexofficeBeleg::auswahl(['belegart' => 'x', 'material' => 'quatsch', 'von' => '2026-02-30', 'bis' => 'a', 'ausgewaehlt' => ['material' => 'kein-array']]);
        $this->assertSame(['angebot', 'einzeln', '', '', null], [$a['belegart'], $a['material'], $a['von'], $a['bis'], $a['ausgewaehlt']['material']]);
        $this->assertSame(['2026-03-01', '2026-03-31'], array_values(array_intersect_key(LexofficeBeleg::auswahl(['von' => '2026-03-31', 'bis' => '2026-03-01']), ['von' => 1, 'bis' => 1])));
        // § 13b: Projektfeld als Standard, ausdrückliche Auswahl hat Vorrang
        $this->assertTrue(LexofficeBeleg::auswahl([], true)['reverseCharge13b']);
        $this->assertFalse(LexofficeBeleg::auswahl(['reverseCharge13b' => false], true)['reverseCharge13b']);
    }
}
