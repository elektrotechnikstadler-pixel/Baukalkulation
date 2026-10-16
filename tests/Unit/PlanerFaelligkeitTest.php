<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jobs\Planer;
use PHPUnit\Framework\TestCase;

final class PlanerFaelligkeitTest extends TestCase
{
    public function testVorUhrzeitIstNichtsFaellig(): void
    {
        $status = [];
        $jetzt = new \DateTimeImmutable('2026-10-16 01:59:59');

        $this->assertNull(Planer::faelligkeit($status, '02:00', $jetzt));
    }

    public function testAbUhrzeitIstErstversuchFaelligAuchNachgeholt(): void
    {
        $status = [];

        $this->assertSame(
            'erstversuch',
            Planer::faelligkeit($status, '02:00', new \DateTimeImmutable('2026-10-16 02:00:00')),
        );
        $this->assertSame(
            'erstversuch',
            Planer::faelligkeit($status, '02:00', new \DateTimeImmutable('2026-10-16 08:37:00')),
        );
    }

    public function testNeuerTagStartetNachGesternOkOderFehlgeschlagenWiederMitErstversuch(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-16 09:00:00');
        foreach (['ok', 'fehlgeschlagen'] as $gesternStatus) {
            $status = [
                'tageslaufDatum' => '2026-10-15',
                'tageslaufStatus' => $gesternStatus,
                'versuche' => 4,
                'naechsterVersuchAm' => '2026-10-15 11:00:00',
            ];
            $this->assertSame('erstversuch', Planer::faelligkeit($status, '02:00', $jetzt), $gesternStatus);
        }
    }

    public function testHeuteMitStatusOkIstNichtMehrFaellig(): void
    {
        $status = [
            'tageslaufDatum' => '2026-10-16',
            'tageslaufStatus' => 'ok',
            'versuche' => 1,
            'naechsterVersuchAm' => '',
        ];

        $this->assertNull(Planer::faelligkeit($status, '02:00', new \DateTimeImmutable('2026-10-16 12:00:00')));
    }

    public function testHeuteMitFehlerWirdErstNachNaechsterZeitZurWiederholungFaellig(): void
    {
        $basis = [
            'tageslaufDatum' => '2026-10-16',
            'tageslaufStatus' => 'fehler',
        ];

        for ($versuche = 1; $versuche <= 3; $versuche++) {
            $status = $basis + [
                'versuche' => $versuche,
                'naechsterVersuchAm' => '2026-10-16 12:30:00',
            ];

            $this->assertNull(
                Planer::faelligkeit($status, '02:00', new \DateTimeImmutable('2026-10-16 12:29:59')),
                'Versuch ' . $versuche . ' darf vor naechsterVersuchAm nicht faellig sein.',
            );
            $this->assertSame(
                'wiederholung',
                Planer::faelligkeit($status, '02:00', new \DateTimeImmutable('2026-10-16 12:30:00')),
                'Versuch ' . $versuche . ' muss ab naechsterVersuchAm wieder faellig sein.',
            );
        }
    }

    public function testVersuchVierUndFehlgeschlagenSindNichtMehrFaellig(): void
    {
        $heute = '2026-10-16';
        $uhr = new \DateTimeImmutable('2026-10-16 23:59:00');

        $this->assertNull(Planer::faelligkeit([
            'tageslaufDatum' => $heute,
            'tageslaufStatus' => 'fehler',
            'versuche' => 4,
            'naechsterVersuchAm' => '2026-10-16 23:00:00',
        ], '02:00', $uhr));

        $this->assertNull(Planer::faelligkeit([
            'tageslaufDatum' => $heute,
            'tageslaufStatus' => 'fehlgeschlagen',
            'versuche' => 3,
            'naechsterVersuchAm' => '',
        ], '02:00', $uhr));
    }
}
