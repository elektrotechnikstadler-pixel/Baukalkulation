<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Migrator;
use App\Services\UpdatePruefung;
use PHPUnit\Framework\Attributes\DataProvider;

/** AP-20261001-update, Stufe 1: `system_info` und `update_check` (AK 2, 3, 6, 9). */
final class UpdateApiTest extends ApiTestCase
{
    private const FREMDER_ORIGIN = 'Origin: https://angreifer.example';

    // ── AK 2: nur admin ─────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function nichtAdminRollen(): iterable
    {
        yield 'master' => ['master'];
        yield 'normal' => ['normal'];
    }

    #[DataProvider('nichtAdminRollen')]
    public function testSystemInfoNurFuerAdmin(string $rolle): void
    {
        $this->setupAdmin();
        $client = $this->createActiveUser('nutzer', 'Nutzer-Pass-123', $rolle);

        $this->assertStatus(403, $client->get('system_info'));
    }

    #[DataProvider('nichtAdminRollen')]
    public function testUpdateCheckNurFuerAdmin(string $rolle): void
    {
        $this->setupAdmin();
        $client = $this->createActiveUser('nutzer', 'Nutzer-Pass-123', $rolle);

        $this->assertStatus(403, $client->post('update_check'));
        $this->assertStatus(403, $client->post('update_check', ['force' => 1]));
        $this->assertFileDoesNotExist($this->cacheDatei());
    }

    public function testOhneAnmeldung401(): void
    {
        $this->setupAdmin();
        $anonym = $this->server->client();

        $this->assertStatus(401, $anonym->get('system_info'));
        $this->assertStatus(401, $anonym->post('update_check'));
        $this->assertStatus(401, $anonym->get('update_check', ['force' => 1]));
    }

    // ── AK 3: Methode und Origin ────────────────────────────────

    public function testForcePerGetLiefert405(): void
    {
        $this->setupAdmin();

        $this->assertStatus(405, $this->api->get('update_check', ['force' => 1]));
        $this->assertFileDoesNotExist($this->cacheDatei());
    }

    #[DataProvider('nichtAdminRollen')]
    public function testRollenpruefungVorMethodenpruefung(string $rolle): void
    {
        $this->setupAdmin();
        $client = $this->createActiveUser('nutzer', 'Nutzer-Pass-123', $rolle);

        $this->assertStatus(403, $client->get('update_check', ['force' => 1]));
    }

    public function testFremderOriginWirdAbgelehnt(): void
    {
        $this->setupAdmin();

        $this->assertStatus(403, $this->api->post('update_check', ['force' => 1], [], [self::FREMDER_ORIGIN]));
        $this->assertFileDoesNotExist($this->cacheDatei());
    }

    public function testEigenerOriginWirdAngenommen(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->post('update_check', [], [], ['Origin: ' . $this->server->baseUrl]));
    }

    // ── AK 9: ohne BK_UPDATE_REPO kein Abruf ────────────────────

    public function testOhneRepoNichtKonfiguriertUndKeinAbruf(): void
    {
        $this->setupAdmin();

        $ergebnis = $this->pruefErgebnis($this->assertOk($this->api->post('update_check')));
        $this->assertSame('nicht_konfiguriert', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_NICHT_KONFIGURIERT, $ergebnis['meldung']);
        $this->assertSame($this->version(), $ergebnis['aktuelle_version']);
        $this->assertNull($ergebnis['neueste_version']);
        $this->assertNull($ergebnis['geprueft']);
        $this->assertFileDoesNotExist($this->cacheDatei(), 'Ohne Repo darf nichts abgerufen/gecacht werden');
    }

    public function testOhneRepoAuchMitForceKeinAbruf(): void
    {
        $this->setupAdmin();

        $ergebnis = $this->pruefErgebnis($this->assertOk($this->api->post('update_check', ['force' => 1])));
        $this->assertSame('nicht_konfiguriert', $ergebnis['status']);
        $this->assertFileDoesNotExist($this->cacheDatei());
    }

    public function testGetOhneForceLiefertGecachtenStandOhneCache(): void
    {
        $this->setupAdmin();

        $ergebnis = $this->pruefErgebnis($this->assertOk($this->api->get('update_check')));
        $this->assertSame('nicht_konfiguriert', $ergebnis['status']);
        $this->assertSame($this->version(), $ergebnis['aktuelle_version']);
        $this->assertFileDoesNotExist($this->cacheDatei(), 'GET ohne force darf keinen Cache schreiben');
    }

    // ── AK 1/6: Inhalt system_info ohne Secrets und Pfade ───────

    public function testSystemInfoLiefertAnzeigedaten(): void
    {
        $this->setupAdmin();

        $info = $this->infoBlock($this->assertOk($this->api->get('system_info')));
        $this->assertSame($this->version(), $info['version']);
        $this->assertSame(Migrator::latestVersion(), $info['schema']['neueste']);
        $this->assertSame($info['schema']['neueste'], $info['schema']['aktuell']);
        $this->assertSame(0, $info['schema']['offen']);
        $this->assertSame($this->server->driver, $info['db_treiber']);
        $this->assertSame(PHP_VERSION, $info['php']['version']);
        $this->assertContains($info['betriebsart'], ['docker', 'klassisch']);
        $this->assertArrayHasKey('lizenz', $info);
        $this->assertIsArray($info['module']);
    }

    public function testSystemInfoOhneSecretsUndAbsolutePfade(): void
    {
        $this->setupAdmin();
        $r = $this->api->get('system_info');
        $json = $this->assertOk($r);

        $verboten = array_filter([
            self::ADMIN_PASS,
            (string) getenv('BK_DB_PASSWORD'),
            (string) getenv('BK_SECRET_KEY'),
        ], static fn(string $s): bool => $s !== '');
        foreach ($verboten as $geheim) {
            $this->assertStringNotContainsString($geheim, $r->body);
        }

        foreach ($this->alleSchluessel($json) as $schluessel) {
            $this->assertDoesNotMatchRegularExpression('/pass|secret|token|smtp|dsn|api_?key/i', $schluessel);
        }
        foreach ($this->alleStrings($json) as $wert) {
            $this->assertStringNotContainsString($this->normPfad($this->server->appRoot), $this->normPfad($wert));
            $this->assertStringNotContainsString($this->normPfad($this->server->dataDir), $this->normPfad($wert));
            $this->assertDoesNotMatchRegularExpression('#^(/[A-Za-z]|[A-Za-z]:[\\\\/])#', $wert, 'Absoluter Pfad in Antwort');
        }
    }

    public function testUpdateCheckOhneSecretsUndAbsolutePfade(): void
    {
        $this->setupAdmin();
        $r = $this->api->post('update_check');
        $json = $this->assertOk($r);

        $this->assertStringNotContainsString(self::ADMIN_PASS, $r->body);
        foreach ($this->alleStrings($json) as $wert) {
            $this->assertStringNotContainsString($this->normPfad($this->server->dataDir), $this->normPfad($wert));
            $this->assertDoesNotMatchRegularExpression('#^(/[A-Za-z]|[A-Za-z]:[\\\\/])#', $wert);
        }
    }

    // ── Hilfen ──────────────────────────────────────────────────

    private function cacheDatei(): string
    {
        return $this->server->dataPath('update_check.json');
    }

    private function version(): string
    {
        return trim((string) file_get_contents($this->server->appRoot . '/VERSION'));
    }

    /** Antwortformat nicht festgelegt: Ergebnis von `UpdatePruefung::pruefen()` irgendwo in der Antwort. */
    private function pruefErgebnis(array $json): array
    {
        $treffer = $this->suche($json, static fn(array $a): bool => array_key_exists('status', $a) && array_key_exists('aktuelle_version', $a));
        $this->assertNotNull($treffer, 'Prüfergebnis nicht gefunden: ' . json_encode($json));
        return $treffer;
    }

    /** Antwortformat nicht festgelegt: Ergebnis von `SystemInfo::sammeln()` irgendwo in der Antwort. */
    private function infoBlock(array $json): array
    {
        $treffer = $this->suche($json, static fn(array $a): bool => array_key_exists('version', $a) && array_key_exists('schema', $a));
        $this->assertNotNull($treffer, 'Systeminfo nicht gefunden: ' . json_encode($json));
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

    /** @return list<string> */
    private function alleSchluessel(array $daten): array
    {
        $schluessel = [];
        foreach ($daten as $k => $v) {
            if (is_string($k)) {
                $schluessel[] = $k;
            }
            if (is_array($v)) {
                array_push($schluessel, ...$this->alleSchluessel($v));
            }
        }
        return $schluessel;
    }

    /** @return list<string> */
    private function alleStrings(array $daten): array
    {
        $werte = [];
        array_walk_recursive($daten, static function ($v) use (&$werte): void {
            if (is_string($v)) {
                $werte[] = $v;
            }
        });
        return $werte;
    }

    private function normPfad(string $pfad): string
    {
        return strtolower(str_replace('\\', '/', $pfad));
    }
}
