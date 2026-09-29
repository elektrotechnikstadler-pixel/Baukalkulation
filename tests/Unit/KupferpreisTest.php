<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Kupferpreis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Soll-Tests AP-20260929-kupferpreis: Parser der Westmetall-Seite „obere Kupfer WM-Notiz“
 * und Abruf-/Drossel-Logik ohne Netz (Fake-Abruf mit Zähler, feste Uhrzeit).
 */
final class KupferpreisTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/kupferpreis/';
    private const JETZT = '2026-09-29T10:00:00+02:00';

    /** @var list<string> */
    private array $abgerufeneUrls = [];

    // ── Parser ──────────────────────────────────────────────

    public function testEchteSeiteLiefertOberstenPreisUndNotizDatum(): void
    {
        $this->assertSame(
            ['preis' => 1307.62, 'stand' => '2026-09-29'],
            Kupferpreis::parseWestmetall(self::fixture('westmetall_wm_cu_high.html')),
        );
    }

    public function testParserHaengtNichtAnCssKlassen(): void
    {
        $html = str_replace(
            [' class="text last"', ' class="text"', ' class="last"', ' class="shaded"'],
            '',
            self::fixture('westmetall_wm_cu_high.html'),
        );

        $this->assertSame(['preis' => 1307.62, 'stand' => '2026-09-29'], Kupferpreis::parseWestmetall($html));
    }

    public function testZwischenueberschriftDatumWirdUebersprungen(): void
    {
        // Ohne September-Zeilen beginnt tbody mit der Monats-Zwischenüberschrift „Datum | obere Kupfer WM-Notiz“.
        $html = (string) preg_replace(
            '#<tr>\s*<td >\d{2}\. September 2026</td>\s*<td class="last">[\d.,]+</td>\s*</tr>\s*#u',
            '',
            self::fixture('westmetall_wm_cu_high.html'),
        );
        $this->assertStringNotContainsString('September 2026</td>', $html, 'Fixture-Aufbereitung fehlgeschlagen');

        $this->assertSame(['preis' => 1279.85, 'stand' => '2026-08-28'], Kupferpreis::parseWestmetall($html));
    }

    public function testZeileOhneZahlWirdUebersprungen(): void
    {
        $html = self::ersteZeile(wert: '-');

        $this->assertSame(['preis' => 1311.22, 'stand' => '2026-09-28'], Kupferpreis::parseWestmetall($html));
    }

    public function testZahlOhneTausenderpunkt(): void
    {
        $this->assertSame(
            ['preis' => 987.5, 'stand' => '2026-09-29'],
            Kupferpreis::parseWestmetall(self::ersteZeile(wert: '987,50')),
        );
    }

    public static function monate(): iterable
    {
        $namen = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        foreach ($namen as $i => $name) {
            yield $name => [$name, sprintf('2026-%02d-02', $i + 1)];
        }
    }

    #[DataProvider('monate')]
    public function testDeutscheMonatsnamenWerdenInIsoDatumUmgesetzt(string $monat, string $erwartet): void
    {
        $result = Kupferpreis::parseWestmetall(self::ersteZeile(datum: "02. {$monat} 2026"));

        $this->assertNotNull($result);
        $this->assertSame($erwartet, $result['stand']);
        $this->assertSame(1307.62, $result['preis']);
    }

    public static function seitenOhnePassendeTabelle(): iterable
    {
        yield 'leer'                 => [''];
        yield 'kein HTML'            => ['Service Unavailable'];
        yield 'JSON-Fehlerseite'     => ['{"error":"rate limit"}'];
        yield 'Tabelle entfernt'     => [self::fixture('westmetall_ohne_tabelle.html')];
        yield 'Layout ohne Tabelle'  => [self::fixture('westmetall_umgebaut.html')];
        yield 'Abbruch in 1. Zeile'  => [self::abgeschnitten()];
        yield 'nur Kopfzeile'        => ['<table><thead><tr><th>Datum</th><th>obere Kupfer WM-Notiz</th></tr></thead><tbody></tbody></table>'];
    }

    #[DataProvider('seitenOhnePassendeTabelle')]
    public function testSeiteOhnePassendeTabelleLiefertNull(string $html): void
    {
        $this->assertNull(Kupferpreis::parseWestmetall($html));
    }

    public static function werteAusserhalbPlausibilitaet(): iterable
    {
        yield 'USD/t (LME)'   => ['14.544,50'];
        yield 'zu klein'      => ['99,99'];
        yield 'zu groß'       => ['5.000,01'];
        yield 'null'          => ['0,00'];
        yield 'negativ'       => ['-1.307,62'];
    }

    #[DataProvider('werteAusserhalbPlausibilitaet')]
    public function testZahlenAusserhalb100Bis5000LiefernNull(string $wert): void
    {
        $html = (string) preg_replace('#<td class="last">[\d.,]+</td>#', '<td class="last">' . $wert . '</td>', self::fixture('westmetall_wm_cu_high.html'));

        $this->assertNull(Kupferpreis::parseWestmetall($html));
    }

    #[DataProvider('werteAusserhalbPlausibilitaet')]
    public function testUnplausibleObersteZeileLiefertNullStattAelteremWert(string $wert): void
    {
        // Nur die oberste Zeile ist manipuliert; darunter stehen gültige Werte (28.09. = 1.311,22).
        $this->assertNull(Kupferpreis::parseWestmetall(self::ersteZeile(wert: $wert)));
    }

    public static function ungueltigesDatumNurObersteZeile(): iterable
    {
        yield 'Kalenderwoche'       => ['KW 40/2026'];
        yield 'unmögliches Datum'   => ['31. September 2026'];
        yield 'Monat falsch'        => ['29. Septembr 2026'];
        yield 'leer'                => [''];
    }

    /**
     * Plan 2.7 Schritt 3: „erste Zeile mit gültigem Datum + Zahl“ – eine Zeile ohne lesbares Datum
     * zählt nicht als Notiz. Preis und Stand müssen dann aus derselben (älteren) Zeile stammen.
     */
    #[DataProvider('ungueltigesDatumNurObersteZeile')]
    public function testUngueltigesDatumNurInObersterZeileLiefertPreisUndStandDerselbenZeile(string $datum): void
    {
        $result = Kupferpreis::parseWestmetall(self::ersteZeile(datum: $datum));

        $this->assertSame(['preis' => 1311.22, 'stand' => '2026-09-28'], $result, 'Kein Mischen von Wert der obersten Zeile mit älterem Datum');
    }

    public static function unbekannteDatumsformate(): iterable
    {
        yield 'Kalenderwoche' => ['KW 40/2026'];
        yield 'Text'          => ['gestern'];
    }

    #[DataProvider('unbekannteDatumsformate')]
    public function testUnbekanntesDatumsformatLiefertNull(string $datum): void
    {
        $html = (string) preg_replace('#<td >\d{2}\. \p{L}+ \d{4}</td>#u', '<td >' . $datum . '</td>', self::fixture('westmetall_wm_cu_high.html'));

        $this->assertNull(Kupferpreis::parseWestmetall($html));
    }

    // ── Abruf-Entscheidung ──────────────────────────────────

    public function testOhneGespeichertenWertWirdAbgerufen(): void
    {
        $this->assertTrue($this->service()->sollAbrufen([]));
    }

    public function testErfolgJuengerAls6StundenWirdNichtErneutAbgerufen(): void
    {
        $this->assertFalse($this->service()->sollAbrufen(self::autoWert(vorStunden: 5)));
        $this->assertTrue($this->service()->sollAbrufen(self::autoWert(vorStunden: 7)));
    }

    public function testManuellerWertWirdAuchNach6StundenNichtUeberschrieben(): void
    {
        $manuell = self::manuellerWert(vorStunden: 48);

        $this->assertFalse($this->service()->sollAbrufen($manuell));
        $this->assertTrue($this->service()->sollAbrufen($manuell, force: true));
    }

    public function testAbgeschaltetesModulRuftNieAb(): void
    {
        $this->assertFalse($this->service()->sollAbrufen([], aktiv: false));
        $this->assertFalse($this->service()->sollAbrufen(self::autoWert(vorStunden: 48), aktiv: false, force: true));
    }

    // ── Aktualisieren mit Fake-Abruf ────────────────────────

    public function testErfolgreicherAbrufSpeichertPreisNotizDatumUndAbrufzeit(): void
    {
        $r = $this->service(self::fixture('westmetall_wm_cu_high.html'))->aktualisieren(self::autoWert(vorStunden: 7));

        $this->assertCount(1, $this->abgerufeneUrls);
        $this->assertStringContainsString('westmetall.com', $this->abgerufeneUrls[0]);
        $this->assertStringContainsString('WM_Cu_high', $this->abgerufeneUrls[0]);
        $this->assertSame(1307.62, $r['delNotierung']);
        $this->assertSame('2026-09-29', $r['stand']);
        $this->assertSame('2026-09-29', $r['datum'], 'datum bleibt für ältere Clients erhalten');
        $this->assertEquals(150, $r['basisNotierung']);
        $this->assertStringStartsWith('auto', $r['quelle']);
        $this->assertStringContainsStringIgnoringCase('westmetall', $r['quelle']);
        $this->assertSame((new \DateTimeImmutable(self::JETZT))->getTimestamp(), strtotime($r['updated']));
        $this->assertFalse($r['veraltet']);
        $this->assertEmpty($r['fetchError'] ?? '');
    }

    public function testFehlschlagLiefertLetztenWertAlsVeraltetOhneErsatzquelle(): void
    {
        $alt = self::autoWert(vorStunden: 7);

        $r = $this->service(null)->aktualisieren($alt);

        $this->assertCount(1, $this->abgerufeneUrls, 'Keine Ersatzquellen (LME, finanzen.net) mehr');
        $this->assertEquals(1250.5, $r['delNotierung']);
        $this->assertSame('2026-09-28', $r['stand'] ?? $r['datum']);
        $this->assertTrue($r['veraltet']);
        $this->assertNotEmpty($r['fetchError']);
        $this->assertSame((new \DateTimeImmutable(self::JETZT))->getTimestamp(), strtotime($r['letzterVersuch']));
    }

    public function testNichtLesbareSeiteGiltAlsFehlschlag(): void
    {
        $r = $this->service(self::fixture('westmetall_umgebaut.html'))->aktualisieren(self::autoWert(vorStunden: 7));

        $this->assertEquals(1250.5, $r['delNotierung'], 'Alter Wert bleibt, kein geratener Wert');
        $this->assertTrue($r['veraltet']);
        $this->assertNotEmpty($r['fetchError']);
    }

    public function testAusnahmeImAbrufGiltAlsFehlschlag(): void
    {
        $kp = new Kupferpreis(function (string $url): ?string {
            $this->abgerufeneUrls[] = $url;
            throw new \RuntimeException('Verbindung abgelehnt');
        }, new \DateTimeImmutable(self::JETZT));

        $r = $kp->aktualisieren(self::autoWert(vorStunden: 7));

        $this->assertEquals(1250.5, $r['delNotierung']);
        $this->assertTrue($r['veraltet']);
        $this->assertNotEmpty($r['fetchError']);
    }

    public function testFetchErrorEnthaeltKeineInternenDetails(): void
    {
        $intern = 'file_get_contents(https://www.westmetall.com/…): Failed to open stream: C:\\srv\\app\\src\\Helpers.php:168 10.0.0.12';
        $kp = new Kupferpreis(function (string $url) use ($intern): ?string {
            $this->abgerufeneUrls[] = $url;
            throw new \ErrorException($intern);
        }, new \DateTimeImmutable(self::JETZT));

        $log = tempnam(sys_get_temp_dir(), 'bk-kp');
        $vorher = ini_set('error_log', (string) $log);
        try {
            $r = $kp->aktualisieren(self::autoWert(vorStunden: 7));
        } finally {
            ini_set('error_log', (string) $vorher);
        }
        $protokoll = (string) file_get_contents((string) $log);
        unlink((string) $log);

        $this->assertNotEmpty($r['fetchError']);
        foreach (['file_get_contents', 'Helpers.php', 'C:\\', '10.0.0.12', 'Failed to open stream'] as $detail) {
            $this->assertStringNotContainsString($detail, $r['fetchError']);
        }
        $this->assertStringContainsString($intern, $protokoll, 'Ausnahmetext nur im Fehlerprotokoll');
    }

    public function testFehlschlagOhneAltwertLiefertNull(): void
    {
        $r = $this->service(null)->aktualisieren([]);

        $this->assertEquals(0, $r['delNotierung']);
        $this->assertNotEmpty($r['fetchError']);
    }

    public function testNachFehlschlagInnerhalb30MinutenKeinErneuterAbruf(): void
    {
        $nachFehlschlag = $this->service(null)->aktualisieren(self::autoWert(vorStunden: 7));
        $this->assertCount(1, $this->abgerufeneUrls);

        $r = $this->service(null, '+10 minutes')->aktualisieren($nachFehlschlag);
        $this->assertCount(1, $this->abgerufeneUrls, 'Kein externer Abruf innerhalb von 30 min');
        $this->assertEquals(1250.5, $r['delNotierung']);
        $this->assertFalse($this->service(null, '+29 minutes')->sollAbrufen($nachFehlschlag));

        $this->service(null, '+31 minutes')->aktualisieren($nachFehlschlag);
        $this->assertCount(2, $this->abgerufeneUrls, 'Nach 30 min wieder abrufen');
    }

    public function testDrosselungAuchOhneAltwert(): void
    {
        $nachFehlschlag = $this->service(null)->aktualisieren([]);

        $this->service(null, '+5 minutes')->aktualisieren($nachFehlschlag);

        $this->assertCount(1, $this->abgerufeneUrls);
    }

    public function testManuellerWertOhneForceBleibtOhneAbruf(): void
    {
        $manuell = self::manuellerWert(vorStunden: 48);

        $r = $this->service(self::fixture('westmetall_wm_cu_high.html'))->aktualisieren($manuell);

        $this->assertSame([], $this->abgerufeneUrls);
        $this->assertEquals(1300, $r['delNotierung']);
        $this->assertSame('manual', $r['quelle']);
    }

    public function testForceUeberschreibtManuellenWert(): void
    {
        $r = $this->service(self::fixture('westmetall_wm_cu_high.html'))->aktualisieren(self::manuellerWert(vorStunden: 1), force: true);

        $this->assertCount(1, $this->abgerufeneUrls);
        $this->assertSame(1307.62, $r['delNotierung']);
        $this->assertStringStartsWith('auto', $r['quelle']);
    }

    public function testErfolgNachFehlschlagHebtVeraltetAuf(): void
    {
        $nachFehlschlag = $this->service(null)->aktualisieren(self::autoWert(vorStunden: 7));

        $r = $this->service(self::fixture('westmetall_wm_cu_high.html'), '+31 minutes')->aktualisieren($nachFehlschlag);

        $this->assertSame(1307.62, $r['delNotierung']);
        $this->assertFalse($r['veraltet']);
        $this->assertEmpty($r['fetchError'] ?? '');
    }

    public function testAltdatenOhneNeueFelderWerdenGelesen(): void
    {
        // Format vor AP-20260929-kupferpreis (z. B. aus eingespielter Sicherung)
        $alt = [
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'datum'          => '2026-09-28',
            'quelle'         => 'auto (westmetall (WM-Notiz))',
            'updated'        => (new \DateTimeImmutable(self::JETZT))->modify('-1 hour')->format('c'),
        ];

        $this->assertFalse($this->service()->sollAbrufen($alt));
        $this->assertFalse($this->service()->istVeraltet($alt));
        $r = $this->service(null)->aktualisieren($alt);
        $this->assertSame([], $this->abgerufeneUrls);
        $this->assertEquals(1250.5, $r['delNotierung']);
    }

    // ── Veraltet (Notiz älter als 5 Kalendertage) ───────────

    public static function notizAlter(): iterable
    {
        yield 'gestern'                  => ['2026-09-28', false];
        yield 'Freitag, heute Dienstag'  => ['2026-09-25', false];
        yield '6 Tage'                   => ['2026-09-23', true];
        yield 'Wochen alt'               => ['2026-09-01', true];
    }

    #[DataProvider('notizAlter')]
    public function testIstVeraltetNachNotizDatum(string $stand, bool $erwartet): void
    {
        $this->assertSame($erwartet, $this->service()->istVeraltet(['delNotierung' => 1300.0, 'stand' => $stand]));
    }

    public function testIstVeraltetNutztBeiAltdatenDasFeldDatum(): void
    {
        $this->assertTrue($this->service()->istVeraltet(['delNotierung' => 1300.0, 'datum' => '2026-09-01']));
        $this->assertFalse($this->service()->istVeraltet(['delNotierung' => 1300.0, 'datum' => '2026-09-28']));
    }

    // ── Hilfen ──────────────────────────────────────────────

    /** Service mit Fake-Abruf: $antwort = HTML der „Seite“ oder null (Abruf fehlgeschlagen). */
    private function service(?string $antwort = null, string $zeitversatz = '+0 minutes'): Kupferpreis
    {
        return new Kupferpreis(
            function (string $url) use ($antwort): ?string {
                $this->abgerufeneUrls[] = $url;
                return $antwort;
            },
            (new \DateTimeImmutable(self::JETZT))->modify($zeitversatz),
        );
    }

    private static function autoWert(int $vorStunden): array
    {
        $zeit = (new \DateTimeImmutable(self::JETZT))->modify("-{$vorStunden} hours")->format('c');
        return [
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'stand'          => '2026-09-28',
            'datum'          => '2026-09-28',
            'quelle'         => 'auto (westmetall)',
            'updated'        => $zeit,
        ];
    }

    private static function manuellerWert(int $vorStunden): array
    {
        $zeit = (new \DateTimeImmutable(self::JETZT))->modify("-{$vorStunden} hours")->format('c');
        return [
            'delNotierung'   => 1300.0,
            'basisNotierung' => 150,
            'datum'          => substr($zeit, 0, 10),
            'quelle'         => 'manual',
            'updated'        => $zeit,
        ];
    }

    private static function fixture(string $name): string
    {
        $html = file_get_contents(self::FIXTURES . $name);
        if ($html === false) {
            throw new \RuntimeException("Fixture {$name} fehlt.");
        }
        return $html;
    }

    /** Echte Seite mit geänderter oberster Datenzeile (29. September 2026 | 1.307,62). */
    private static function ersteZeile(?string $datum = null, ?string $wert = null): string
    {
        $html = self::fixture('westmetall_wm_cu_high.html');
        if ($datum !== null) {
            $html = self::einmalErsetzen('<td >29. September 2026</td>', "<td >{$datum}</td>", $html);
        }
        if ($wert !== null) {
            $html = self::einmalErsetzen('<td class="last">1.307,62</td>', "<td class=\"last\">{$wert}</td>", $html);
        }
        return $html;
    }

    private static function einmalErsetzen(string $suche, string $ersatz, string $text): string
    {
        $pos = strpos($text, $suche);
        if ($pos === false) {
            throw new \RuntimeException("Fixture enthält '{$suche}' nicht.");
        }
        return substr_replace($text, $ersatz, $pos, strlen($suche));
    }

    /** Übertragung bricht direkt nach dem Öffnen der ersten Preiszelle ab. */
    private static function abgeschnitten(): string
    {
        $html = self::fixture('westmetall_wm_cu_high.html');
        $marke = '<td class="last">';
        return substr($html, 0, (int) strpos($html, $marke) + strlen($marke));
    }
}
