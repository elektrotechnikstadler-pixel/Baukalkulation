<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Soll-Tests AP-20261001-update Stufe 2, Schritt 7 (2.5.5, 2.5.8, AK 2/3): `update_start`, `update_status`
 * und Wartungsmodus. Gutfall 202 nur im Unit-Test: TestServer entfernt `BK_UPDATE_REPO` (kein Netz).
 */
final class UpdateStartApiTest extends ApiTestCase
{
    private const FREMDER_ORIGIN = 'Origin: https://angreifer.example';

    /** @return iterable<string, array{string}> */
    public static function nichtAdminRollen(): iterable
    {
        yield 'master' => ['master'];
        yield 'normal' => ['normal'];
    }

    // ── Rechte, Methode, Origin ─────────────────────────────────

    public function testOhneAnmeldung401(): void
    {
        $this->setupAdmin();
        $anonym = $this->server->client();

        $this->assertStatus(401, $anonym->post('update_start', ['version' => '9.9.9']));
        $this->assertStatus(401, $anonym->get('update_status'));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    #[DataProvider('nichtAdminRollen')]
    public function testNurAdmin(string $rolle): void
    {
        $this->setupAdmin();
        $this->heartbeat();
        $client = $this->createActiveUser('nutzer', 'Nutzer-Pass-123', $rolle);

        $this->assertStatus(403, $client->post('update_start', ['version' => '9.9.9']));
        $this->assertStatus(403, $client->get('update_status'));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testUpdateStartPerGet405(): void
    {
        $this->setupAdmin();
        $this->heartbeat();

        $this->assertStatus(405, $this->api->get('update_start', ['version' => '9.9.9']));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    #[DataProvider('nichtAdminRollen')]
    public function testRollenpruefungVorMethodenpruefung(string $rolle): void
    {
        $this->setupAdmin();
        $client = $this->createActiveUser('nutzer', 'Nutzer-Pass-123', $rolle);

        $this->assertStatus(403, $client->get('update_start', ['version' => '9.9.9']));
    }

    public function testFremderOriginWirdAbgelehnt(): void
    {
        $this->setupAdmin();
        $this->heartbeat();

        $this->assertStatus(403, $this->api->post('update_start', ['version' => '9.9.9'], [], [self::FREMDER_ORIGIN]));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    // ── ohne BK_UPDATE_REPO ─────────────────────────────────────

    public function testOhneRepoNichtKonfiguriert(): void
    {
        $this->setupAdmin();
        $this->heartbeat();

        $json = $this->assertStatus(400, $this->api->post('update_start', ['version' => '9.9.9']));

        $this->assertSame('nicht_konfiguriert', $json['code'] ?? null, json_encode($json));
        $this->assertIsString($json['error'] ?? null);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
        $this->assertFileDoesNotExist($this->server->dataPath('update_check.json'), 'Ohne Repo kein Abruf/Cache');
    }

    public static function ungueltigeKoerper(): iterable
    {
        yield 'ohne version' => [[]];
        yield 'version als Liste' => [['version' => ['9.9.9']]];
        yield 'version als Zahl' => [['version' => 3]];
        yield 'version als Objekt' => [['version' => ['a' => '9.9.9']]];
    }

    #[DataProvider('ungueltigeKoerper')]
    public function testUngueltigerKoerper400(array $koerper): void
    {
        $this->setupAdmin();
        $this->heartbeat();

        $this->assertStatus(400, $this->api->post('update_start', $koerper));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    // ── update_status ───────────────────────────────────────────

    public function testStatusOhneDateienWartet(): void
    {
        $this->setupAdmin();

        $status = $this->statusBlock($this->assertOk($this->api->get('update_status')));

        $this->assertSame('wartet', $status['phase']);
        $this->assertFalse($status['updater_aktiv']);
        $this->assertFalse($status['wartung']);
    }

    public function testStatusMitHeartbeatUndStatusDatei(): void
    {
        $this->setupAdmin();
        $this->heartbeat();
        file_put_contents($this->updatePfad('status.json'), json_encode([
            'id'           => '0123456789abcdef',
            'phase'        => 'lade',
            'version_alt'  => '3.0.1',
            'version_ziel' => '3.1.0',
            'ergebnis'     => null,
            'meldung'      => 'Image wird geladen.',
            'ts'           => time(),
        ]));

        $status = $this->statusBlock($this->assertOk($this->api->get('update_status')));

        $this->assertSame('lade', $status['phase']);
        $this->assertSame('3.1.0', $status['version_ziel']);
        $this->assertTrue($status['updater_aktiv']);
    }

    public function testStatusMitAltemHeartbeatInaktiv(): void
    {
        $this->setupAdmin();
        $this->heartbeat(time() - 600);

        $status = $this->statusBlock($this->assertOk($this->api->get('update_status')));

        $this->assertFalse($status['updater_aktiv']);
    }

    // ── Wartungsmodus ───────────────────────────────────────────

    public function testWartungSperrtSchreibaktionen(): void
    {
        $this->setupAdmin();
        $this->wartung();

        $json = $this->assertStatus(503, $this->api->post('save_settings', ['firma_name' => 'Während Wartung']));

        $this->assertStringContainsString('Wartung', (string) ($json['error'] ?? ''));
    }

    public function testWartungSperrtUpdateStart(): void
    {
        $this->setupAdmin();
        $this->heartbeat();
        $this->wartung();

        $this->assertStatus(503, $this->api->post('update_start', ['version' => '9.9.9']));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testWartungErlaubtCheckUndStatus(): void
    {
        $this->setupAdmin();
        $this->wartung();

        $this->assertSame(200, $this->api->get('check')->status);
        $status = $this->statusBlock($this->assertOk($this->api->get('update_status')));
        $this->assertTrue($status['wartung']);
    }

    public function testWartungErlaubtLesendeGetAktion(): void
    {
        $this->setupAdmin();
        $this->wartung();

        $this->assertOk($this->api->get('system_info'));
    }

    public function testWartungsmarkerKurzVorAblaufGiltNoch(): void
    {
        $this->setupAdmin();
        $this->wartung(time() - 29 * 60);

        $this->assertStatus(503, $this->api->post('save_settings', ['firma_name' => 'Während Wartung']));
    }

    public function testAlterWartungsmarkerWirdIgnoriert(): void
    {
        $this->setupAdmin();
        $this->wartung(time() - 31 * 60);

        $this->assertOk($this->api->post('save_settings', ['firma_name' => 'Nach hängendem Sidecar']));
    }

    public function testOhneMarkerKeineWartung(): void
    {
        $this->setupAdmin();
        $this->wartung();
        unlink($this->updatePfad('maintenance'));

        $this->assertOk($this->api->post('save_settings', ['firma_name' => 'Nach Wartung']));
    }

    // ── Wartungsmodus: zweiter Einstiegspunkt din1090_api.php ───

    public function testWartungSperrtDin1090Schreibaktion(): void
    {
        $this->setupAdmin();
        $this->wartung();

        $antwort = $this->api->skript('POST', 'din1090_api.php', [], ['sub' => 'save_welder', 'name' => 'Während Wartung']);

        $this->assertSame(503, $antwort->status, $antwort->body);
        $json = json_decode($antwort->body, true);
        $this->assertStringContainsString('Wartung', (string) ($json['error'] ?? ''));
        $this->assertNotContains('Während Wartung', $this->din1090Schweisser(), 'Schreibaktion darf nicht ausgeführt werden');
    }

    public function testWartungErlaubtDin1090Lesen(): void
    {
        $this->setupAdmin();
        $this->wartung();

        $antwort = $this->api->skript('GET', 'din1090_api.php', ['sub' => 'list_welders']);

        $this->assertSame(200, $antwort->status, $antwort->body);
        $this->assertTrue(json_decode($antwort->body, true)['ok'] ?? false, $antwort->body);
    }

    public function testOhneMarkerDin1090Schreibbar(): void
    {
        $this->setupAdmin();

        $antwort = $this->api->skript('POST', 'din1090_api.php', [], ['sub' => 'save_welder', 'name' => 'Ohne Wartung']);

        $this->assertSame(200, $antwort->status, $antwort->body);
        $this->assertContains('Ohne Wartung', $this->din1090Schweisser());
    }

    public function testAlterWartungsmarkerDin1090Schreibbar(): void
    {
        $this->setupAdmin();
        $this->wartung(time() - 31 * 60);

        $antwort = $this->api->skript('POST', 'din1090_api.php', [], ['sub' => 'save_welder', 'name' => 'Nach hängendem Sidecar']);

        $this->assertSame(200, $antwort->status, $antwort->body);
    }

    // ── Hilfen ──────────────────────────────────────────────────

    private function updatePfad(string $datei): string
    {
        $verzeichnis = $this->server->dataPath('update');
        if (!is_dir($verzeichnis)) {
            mkdir($verzeichnis, 0777, true);
        }
        return $verzeichnis . DIRECTORY_SEPARATOR . $datei;
    }

    /** Leitstand Stufe 2 R2: App schreibt nur nach `update/anforderung/`, Sidecar-Dateien liegen in `update/`. */
    private function anforderungsDatei(): string
    {
        return $this->server->dataPath('update' . DIRECTORY_SEPARATOR . 'anforderung' . DIRECTORY_SEPARATOR . 'request.json');
    }

    private function heartbeat(?int $ts = null): void
    {
        file_put_contents(
            $this->updatePfad('updater.json'),
            json_encode(['ts' => $ts ?? time(), 'updater_version' => '1', 'dry_run' => false]),
        );
    }

    /** @return list<string> Namen aller DIN-1090-Schweißer (über die API gelesen, Wartung blockiert GET nicht). */
    private function din1090Schweisser(): array
    {
        $antwort = $this->api->skript('GET', 'din1090_api.php', ['sub' => 'list_welders']);
        $daten = json_decode($antwort->body, true)['data'] ?? [];
        return array_map(static fn(array $z): string => (string) ($z['name'] ?? ''), is_array($daten) ? $daten : []);
    }

    private function wartung(?int $mtime = null): void
    {
        $marker = $this->updatePfad('maintenance');
        file_put_contents($marker, '');
        if ($mtime !== null) {
            touch($marker, $mtime);
        }
        clearstatcache();
    }

    /** Antwortformat: `phase`, `updater_aktiv`, `wartung` auf einer Ebene, irgendwo in der Antwort. */
    private function statusBlock(array $json): array
    {
        $treffer = $this->suche($json, static fn(array $a): bool => array_key_exists('phase', $a) && array_key_exists('updater_aktiv', $a));
        $this->assertNotNull($treffer, 'Update-Status nicht gefunden: ' . json_encode($json));
        $this->assertArrayHasKey('wartung', $treffer);
        return $treffer;
    }

    private function suche(array $daten, \Closure $passt): ?array
    {
        if ($passt($daten)) {
            return $daten;
        }
        foreach ($daten as $wert) {
            if (is_array($wert) && ($treffer = $this->suche($wert, $passt)) !== null) {
                return $treffer;
            }
        }
        return null;
    }
}
