<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sollzeit;
use PHPUnit\Framework\TestCase;

final class SollzeitTest extends TestCase
{
    public function testAltmodusBeachtetArbeitstageUndFallbacks(): void
    {
        $mitarbeiter = ['sollstundenTag' => 8, 'arbeitstage' => '1,2,3,4,5'];
        self::assertSame(8.0, Sollzeit::tagesSoll($mitarbeiter, '2026-03-02'));
        self::assertSame(0.0, Sollzeit::tagesSoll($mitarbeiter, '2026-03-07'));

        $sechsTage = ['arbeitstage' => '', 'sollTageWoche' => 6];
        self::assertSame(8.0, Sollzeit::tagesSoll($sechsTage, '2026-03-07'));
        self::assertSame(0.0, Sollzeit::tagesSoll($sechsTage, '2026-03-08'));

        self::assertSame(0.0, Sollzeit::tagesSoll(
            ['sollstundenTag' => 0, 'arbeitstage' => '1,2,3,4,5'],
            '2026-03-02',
        ));
        self::assertSame(8.0, Sollzeit::tagesSoll(['arbeitstage' => '1,2,3,4,5'], '2026-03-02'));
    }

    public function testWochentagsmodusLiefertExakteWerteUndLehntUngueltigeDatenAb(): void
    {
        $mitarbeiter = [
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 0,
            'sollstundenMi' => 0,
            'sollstundenDo' => 0,
            'sollstundenFr' => 5.5,
            'sollstundenSa' => 0,
            'sollstundenSo' => 2,
        ];

        self::assertSame(8.0, Sollzeit::tagesSoll($mitarbeiter, '2026-03-02'));
        self::assertSame(5.5, Sollzeit::tagesSoll($mitarbeiter, '2026-03-06'));
        self::assertSame(0.0, Sollzeit::tagesSoll($mitarbeiter, '2026-03-07'));
        self::assertSame(2.0, Sollzeit::tagesSoll($mitarbeiter, '2026-03-08'));
        self::assertSame(0.0, Sollzeit::tagesSoll($mitarbeiter, '2026-02-30'));
        self::assertSame(0.0, Sollzeit::tagesSoll($mitarbeiter, '06.03.2026'));
    }

    public function testIstStundenFolgenDerRegelJeErfassungstyp(): void
    {
        $mitarbeiter = [
            'sollzeitJeWochentag' => true,
            'sollstundenMo' => 8,
            'sollstundenDi' => 0,
            'sollstundenMi' => 0,
            'sollstundenDo' => 0,
            'sollstundenFr' => 5.5,
            'sollstundenSa' => 0,
            'sollstundenSo' => 2,
        ];
        $eintrag = static fn(string $typ, float $stunden = 3, string $datum = '2026-03-02'): array => [
            'typ' => $typ,
            'stunden' => $stunden,
            'datum' => $datum,
        ];

        self::assertSame(3.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('arbeit')));
        self::assertSame(8.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('urlaub')));
        self::assertSame(8.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('krank')));
        self::assertSame(8.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('feiertag')));
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('urlaub', 3, '2026-03-03')));
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('abwesend')));
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('gleitzeit')));
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('sonstig')));

        $custom = [
            ['value' => 'custom-no-work', 'isArbeit' => false],
            ['value' => 'custom-work', 'isArbeit' => true],
            ['value' => 'custom-soll', 'isArbeit' => true, 'istGleichSoll' => true],
        ];
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('custom-no-work'), $custom));
        self::assertSame(3.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('custom-work'), $custom));
        self::assertSame(8.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('custom-soll'), $custom));
        self::assertSame(3.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('legacy-custom'), ['legacy-custom']));
        self::assertSame(0.0, Sollzeit::istStundenEintrag($mitarbeiter, $eintrag('unknown')));
    }
}
