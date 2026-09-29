<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Soll-Tests AP-20260929-kupferpreis (Abnahmekriterien 4–8) über die API.
 * Kein externer Abruf: manueller Wert, frischer Wert oder abgeschaltetes Modul verhindern ihn.
 */
final class MetallzuschlagTest extends ApiTestCase
{
    public function testManuellerWertBleibtAuchNach6StundenGueltig(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));
        $this->assertEquals(1300, $get['delNotierung']);
        $this->assertSame('manual', $get['quelle']);

        $this->verschiebeUpdated('-7 hours');

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));
        $this->assertEquals(1300, $get['delNotierung']);
        $this->assertSame('manual', $get['quelle']);

        $auto = $this->assertStatus(200, $this->api->get('metallzuschlag_auto_fetch'));
        $this->assertEquals(1300, $auto['delNotierung']);
        $this->assertSame('manual', $auto['quelle']);
        $this->assertSame('manual', $this->gespeichert()['quelle'], 'Gespeicherter Wert unverändert');
    }

    public function testNormalerBenutzerLiestManuellenWertOhneAbruf(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $this->verschiebeUpdated('-7 hours');
        $user = $this->createActiveUser('monteur', 'Monteur-Pass-123');

        $this->assertEquals(1300, $this->assertStatus(200, $user->get('metallzuschlag_get'))['delNotierung']);
        $auto = $this->assertStatus(200, $user->get('metallzuschlag_auto_fetch'));
        $this->assertEquals(1300, $auto['delNotierung']);
        $this->assertSame('manual', $auto['quelle']);
    }

    public static function ungueltigeWerte(): iterable
    {
        yield 'null'         => [['delNotierung' => 0]];
        yield 'negativ'      => [['delNotierung' => -1300]];
        yield 'Text'         => [['delNotierung' => 'abc']];
        yield 'zu groß'      => [['delNotierung' => 5000.01]];
        yield 'riesig'       => [['delNotierung' => 1e9]];
        yield 'zu klein'     => [['delNotierung' => 99.99]];
        yield 'fehlt'        => [['basisNotierung' => 150]];
        yield 'leer'         => [['delNotierung' => '']];
        yield 'Array'        => [['delNotierung' => [1300]]];
    }

    #[DataProvider('ungueltigeWerte')]
    public function testUngueltigerWertWirdAbgelehntUndAlterWertBleibt(array $body): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));

        $this->assertStatus(400, $this->api->post('metallzuschlag_set', $body));

        $this->assertEquals(1300, $this->assertStatus(200, $this->api->get('metallzuschlag_get'))['delNotierung']);
        $this->assertEquals(1300, $this->gespeichert()['delNotierung']);
    }

    public function testDezimalwertWirdGespeichert(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1311.22]));

        $this->assertSame(1311.22, (float) $this->assertStatus(200, $this->api->get('metallzuschlag_get'))['delNotierung']);
    }

    public function testNurAdminDarfSetzenOderErzwingen(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $user = $this->createActiveUser('monteur', 'Monteur-Pass-123');

        $this->assertStatus(403, $user->post('metallzuschlag_set', ['delNotierung' => 1400]));
        $this->assertStatus(403, $user->get('metallzuschlag_auto_fetch', ['force' => 1]));

        $this->assertEquals(1300, $this->gespeichert()['delNotierung']);
        $this->assertSame('manual', $this->gespeichert()['quelle']);
    }

    public function testOhneAnmeldung401(): void
    {
        $this->setupAdmin();
        $anonym = $this->server->client();

        $this->assertStatus(401, $anonym->get('metallzuschlag_get'));
        $this->assertStatus(401, $anonym->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $this->assertStatus(401, $anonym->get('metallzuschlag_auto_fetch'));
        $this->assertStatus(401, $anonym->get('metallzuschlag_auto_fetch', ['force' => 1]));
    }

    public function testAbgeschaltetesModulLiefertKeinenZuschlag(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $this->assertOk($this->api->post('save_settings', ['modul_kupfer_del' => false]));
        $this->verschiebeUpdated('-7 hours');
        $vorher = $this->gespeichert();

        foreach ([
            $this->api->get('metallzuschlag_get'),
            $this->api->get('metallzuschlag_auto_fetch'),
            $this->api->get('metallzuschlag_auto_fetch', ['force' => 1]),
        ] as $r) {
            $json = $this->assertStatus(200, $r);
            $this->assertEquals(0, $json['delNotierung'], $r->describe());
            $this->assertArrayHasKey('aktiv', $json, $r->describe());
            $this->assertFalse($json['aktiv'], $r->describe());
        }
        $this->assertSame($vorher, $this->gespeichert(), 'Kein Abruf, nichts gespeichert');

        $this->assertOk($this->api->post('save_settings', ['modul_kupfer_del' => true]));
        $this->assertEquals(1300, $this->assertStatus(200, $this->api->get('metallzuschlag_get'))['delNotierung']);
    }

    public function testAltdatenOhneNeueFelderWerdenGelesen(): void
    {
        $this->setupAdmin();
        $gestern = date('Y-m-d', strtotime('-1 day'));
        $this->schreibeDirekt([
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'datum'          => $gestern,
            'quelle'         => 'auto (westmetall (WM-Notiz))',
            'updated'        => date('c', strtotime('-1 hour')),
        ]);

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));
        $this->assertEquals(1250.5, $get['delNotierung']);
        $this->assertSame($gestern, $get['datum']);
        $this->assertArrayHasKey('veraltet', $get);
        $this->assertFalse($get['veraltet']);

        $auto = $this->assertStatus(200, $this->api->get('metallzuschlag_auto_fetch'));
        $this->assertEquals(1250.5, $auto['delNotierung']);
    }

    public function testAlteNotizWirdAlsVeraltetGemeldet(): void
    {
        $this->setupAdmin();
        $this->schreibeDirekt([
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'datum'          => date('Y-m-d', strtotime('-10 days')),
            'quelle'         => 'auto (westmetall (WM-Notiz))',
            'updated'        => date('c', strtotime('-1 hour')),
        ]);

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));

        $this->assertEquals(1250.5, $get['delNotierung']);
        $this->assertArrayHasKey('veraltet', $get);
        $this->assertTrue($get['veraltet']);
    }

    public function testSonderzeichenInQuelleBleibenErhalten(): void
    {
        $this->setupAdmin();
        $this->schreibeDirekt([
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'datum'          => '<b>2026-09-28</b>',
            'quelle'         => 'Händler „Müller“ <i>€</i>',
            'updated'        => date('c'),
        ]);

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));

        $this->assertSame('Händler „Müller“ <i>€</i>', $get['quelle']);
        $this->assertSame('<b>2026-09-28</b>', $get['datum']);
    }

    // ── Runde 2: Review-Befunde ─────────────────────────────

    public function testFehlschlagInnerhalb30MinutenLiefertAltwertAlsVeraltetOhneAbruf(): void
    {
        $this->setupAdmin();
        $gestern = date('Y-m-d', strtotime('-1 day'));
        $this->schreibeDirekt([
            'delNotierung'   => 1250.5,
            'basisNotierung' => 150,
            'stand'          => $gestern,
            'datum'          => $gestern,
            'quelle'         => 'auto (westmetall)',
            'updated'        => date('c', strtotime('-7 hours')),
            'letzterVersuch' => date('c', strtotime('-10 minutes')),
            'fetchError'     => 'Westmetall-Abruf fehlgeschlagen.',
        ]);
        $vorher = $this->gespeichertRoh();
        $user = $this->createActiveUser('monteur', 'Monteur-Pass-123');

        foreach ([$user->get('metallzuschlag_auto_fetch'), $this->api->get('metallzuschlag_auto_fetch'), $user->get('metallzuschlag_get')] as $r) {
            $json = $this->assertStatus(200, $r);
            $this->assertEquals(1250.5, $json['delNotierung'], $r->describe());
            $this->assertTrue($json['veraltet'], $r->describe());
            $this->assertSame('Westmetall-Abruf fehlgeschlagen.', $json['fetchError'], $r->describe());
        }
        // Ein Abruf hätte letzterVersuch neu gesetzt und gespeichert.
        $this->assertSame($vorher, $this->gespeichertRoh(), 'Kein Abruf, nichts gespeichert');
    }

    public function testFehlschlagOhneAltwertInnerhalb30MinutenOhneAbruf(): void
    {
        $this->setupAdmin();
        $this->schreibeDirekt([
            'delNotierung'   => 0,
            'letzterVersuch' => date('c', strtotime('-5 minutes')),
            'fetchError'     => 'Westmetall nicht erreichbar.',
        ]);
        $vorher = $this->gespeichertRoh();

        $json = $this->assertStatus(200, $this->api->get('metallzuschlag_auto_fetch'));

        $this->assertEquals(0, $json['delNotierung']);
        $this->assertSame('Westmetall nicht erreichbar.', $json['fetchError']);
        $this->assertFalse($json['veraltet'], 'Ohne Wert nichts als veraltet kennzeichnen');
        $this->assertSame($vorher, $this->gespeichertRoh());
    }

    public function testForcePerGetWirdAbgelehnt(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $vorher = $this->gespeichertRoh();

        $this->assertStatus(405, $this->api->get('metallzuschlag_auto_fetch', ['force' => 1]));

        $this->assertSame($vorher, $this->gespeichertRoh(), 'Manueller Wert unverändert');
    }

    public function testForcePerPostAlsMonteur403(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $vorher = $this->gespeichertRoh();
        $user = $this->createActiveUser('monteur', 'Monteur-Pass-123');

        $this->assertStatus(403, $user->post('metallzuschlag_auto_fetch', ['force' => 1]));
        $this->assertStatus(403, $user->post('metallzuschlag_auto_fetch', [], ['force' => 1]));

        $this->assertSame($vorher, $this->gespeichertRoh());
    }

    public static function ungueltigeRohwerte(): iterable
    {
        yield 'DEL unendlich'    => ['{"delNotierung":1e999}'];
        yield 'DEL -5'           => ['{"delNotierung":-5}'];
        yield 'DEL 6000'         => ['{"delNotierung":6000}'];
        yield 'DEL Text 1e999'   => ['{"delNotierung":"1e999"}'];
        yield 'Basis unendlich'  => ['{"delNotierung":1300,"basisNotierung":1e999}'];
        yield 'Basis -5'         => ['{"delNotierung":1300,"basisNotierung":-5}'];
        yield 'Basis 6000'       => ['{"delNotierung":1300,"basisNotierung":6000}'];
        yield 'Basis Text'       => ['{"delNotierung":1300,"basisNotierung":"abc"}'];
        yield 'Basis Array'      => ['{"delNotierung":1300,"basisNotierung":[150]}'];
    }

    #[DataProvider('ungueltigeRohwerte')]
    public function testUngueltigeNotierungWirdAbgelehntUndGespeicherterWertBleibt(string $json): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1250, 'basisNotierung' => 150]));
        $vorher = $this->gespeichertRoh();

        $this->assertStatus(400, $this->api->postRaw('metallzuschlag_set', $json));

        $this->assertSame($vorher, $this->gespeichertRoh());
        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));
        $this->assertEquals(1250, $get['delNotierung']);
        $this->assertEquals(150, $get['basisNotierung']);
    }

    public function testBasisNotierungNullWirdAngenommen(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300, 'basisNotierung' => 0]));

        $this->assertEquals(0, $this->gespeichert()['basisNotierung']);
    }

    /**
     * Bedingtes Schreiben: Ein Abruf, der vor einem manuellen `set` gelesen hat, darf danach nicht speichern.
     * Der echte Wettlauf ist über HTTP nicht herstellbar (Abruf nicht injizierbar, Built-in-Server auf Windows
     * seriell) – daher wird der Speicherschritt des Handlers direkt mit dem vorher gelesenen Rohtext aufgerufen.
     */
    public function testParallelerFehlschlagUeberschreibtManuellenWertNicht(): void
    {
        $this->setupAdmin();
        $this->schreibeDirekt(['delNotierung' => 1250.5, 'quelle' => 'auto (westmetall)', 'updated' => date('c', strtotime('-7 hours'))]);
        $gelesenVorAbruf = $this->gespeichertRoh();

        $this->assertOk($this->api->post('metallzuschlag_set', ['delNotierung' => 1300]));
        $manuell = $this->gespeichertRoh();

        $fehlschlag = ['delNotierung' => 1250.5, 'quelle' => 'auto (westmetall)', 'letzterVersuch' => date('c'), 'fetchError' => 'Westmetall nicht erreichbar.'];
        $this->assertFalse($this->speichereWieHandler($fehlschlag, $gelesenVorAbruf), 'Veralteter Lesestand darf nicht schreiben');
        $this->assertSame($manuell, $this->gespeichertRoh());

        $get = $this->assertStatus(200, $this->api->get('metallzuschlag_get'));
        $this->assertEquals(1300, $get['delNotierung']);
        $this->assertSame('manual', $get['quelle']);
        $this->assertArrayNotHasKey('fetchError', $get);

        // Gegenprobe: mit aktuellem Lesestand wird geschrieben.
        $this->assertTrue($this->speichereWieHandler($fehlschlag, $manuell));
        $this->assertSame('Westmetall nicht erreichbar.', $this->gespeichert()['fetchError']);
    }

    /** @param array<string,mixed> $data */
    private function speichereWieHandler(array $data, string $erwartet): bool
    {
        $pdo = $this->server->db();
        $methode = new \ReflectionMethod(\App\Handlers\CatalogActions::class, 'saveMetallzuschlag');
        $ok = $methode->invoke(new \App\Handlers\CatalogActions($pdo, []), $data, $erwartet);
        $pdo = null;
        gc_collect_cycles();
        return $ok;
    }

    private function gespeichertRoh(): string
    {
        $pdo = $this->server->db();
        $raw = $pdo->query('SELECT data FROM metallzuschlag WHERE id = 1')->fetchColumn();
        $pdo = null;
        gc_collect_cycles();
        $this->assertIsString($raw, 'Zeile metallzuschlag.id = 1 fehlt.');
        return $raw;
    }

    /** @return array<string,mixed> */
    private function gespeichert(): array
    {
        $pdo = $this->server->db();
        $raw = $pdo->query('SELECT data FROM metallzuschlag WHERE id = 1')->fetchColumn();
        $pdo = null;
        gc_collect_cycles();
        $this->assertIsString($raw, 'Zeile metallzuschlag.id = 1 fehlt.');
        return json_decode($raw, true) ?: [];
    }

    /** Wie eine eingespielte Sicherung: Daten ohne API direkt in die Datenbank schreiben. */
    private function schreibeDirekt(array $data): void
    {
        $pdo = $this->server->db();
        $pdo->prepare('UPDATE metallzuschlag SET data = ? WHERE id = 1')->execute([json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $pdo = null;
        gc_collect_cycles();
    }

    /** Simuliert Zeitablauf: gespeichertes `updated` in die Vergangenheit legen. */
    private function verschiebeUpdated(string $versatz): void
    {
        $data = $this->gespeichert();
        $data['updated'] = date('c', (int) strtotime($versatz));
        $this->schreibeDirekt($data);
    }
}
