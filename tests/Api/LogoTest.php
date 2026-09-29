<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ApiResponse;

/** AP-20260929-sicherheit: get_logo liefert nur das hochgeladene Firmenlogo aus dem Datenverzeichnis. */
final class LogoTest extends ApiTestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const MARKER = 'BK-GEHEIMER-DATEIINHALT-4711';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        // Leere 404-Antworten kommen beim Client an, bevor der Server die SQLite-Datei schließt; der Folgerequest wartet darauf.
        $this->server->client()->get('features');
        gc_collect_cycles();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function fremdeLogoPfade(): array
    {
        return [
            'Datei im Projekt'                     => ['VERSION'],
            'PHP-Quelltext'                        => ['public/api.php'],
            'Traversal über data/'                 => ['data/../VERSION'],
            'Bild außerhalb des Datenverzeichnisses' => ['public/planning-dashboard-logo.png'],
            'absoluter Pfad'                       => ['{ABSOLUT}'],
        ];
    }

    /** L1 */
    #[DataProvider('fremdeLogoPfade')]
    public function testGetLogoLiefertKeineFremdenDateien(string $pfad): void
    {
        $this->setupAdmin();
        if ($pfad === '{ABSOLUT}') {
            $pfad = $this->tempFile('.txt', self::MARKER);
        }
        $this->setzeLogoEinstellungDirekt($pfad);

        $r = $this->server->client()->get('get_logo');

        $this->assertSame(404, $r->status, $this->kurz($r));
        $this->assertStringNotContainsString(trim((string) file_get_contents($this->server->appRoot . '/VERSION')), $r->body);
        $this->assertStringNotContainsString('<?php', $r->body);
        $this->assertStringNotContainsString(self::MARKER, $r->body);
        $this->assertStringStartsNotWith("\x89PNG", $r->body);
    }

    /** L2 */
    public function testHochgeladenesLogoWirdOhneAnmeldungAusgeliefert(): void
    {
        $this->setupAdmin();
        $upload = $this->assertOk($this->api->upload('upload_logo', 'logo', $this->pngDatei()));
        $this->assertSame('data/firma_logo.png', $upload['url']);

        $r = $this->server->client()->get('get_logo');

        $this->assertSame(200, $r->status, $this->kurz($r) . $this->server->errorLogTail());
        $this->assertSame('image/png', $r->header('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $r->body);
        $this->assertSame('nosniff', $r->header('X-Content-Type-Options'));
    }

    /** L3 */
    public function testGeloeschtesLogoLiefert404(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->upload('upload_logo', 'logo', $this->pngDatei()));

        $this->assertOk($this->api->post('save_settings', ['firma_logo_url' => '']));

        $r = $this->server->client()->get('get_logo');
        $this->assertSame(404, $r->status, $this->kurz($r));
        $this->assertStringStartsNotWith("\x89PNG", $r->body);
    }

    /** @return array<string, array{string}> */
    public static function ungueltigeLogoEinstellungen(): array
    {
        return [
            'Traversal'            => ['../VERSION'],
            'Datenbank'            => ['data/database.sqlite'],
            'externe URL'          => ['https://example.org/x.png'],
        ];
    }

    /** L4 */
    #[DataProvider('ungueltigeLogoEinstellungen')]
    public function testSaveSettingsLehntUngueltigenLogoPfadAb(string $wert): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->upload('upload_logo', 'logo', $this->pngDatei()));

        $json = $this->assertStatus(400, $this->api->post('save_settings', ['firma_logo_url' => $wert, 'firma_name' => 'Soll nicht gespeichert werden']));
        $this->assertArrayHasKey('error', $json);

        $settings = $this->assertOk($this->api->get('load_settings'))['settings'];
        $this->assertSame('data/firma_logo.png', $settings['firma_logo_url']);
        $this->assertNotSame('Soll nicht gespeichert werden', $settings['firma_name']);
    }

    /** @return array<string, array{mixed}> */
    public static function sonderwerteLogoEinstellung(): array
    {
        return [
            'Nullbyte'     => ["data/firma_logo.png\0.php"],
            'php://filter' => ['php://filter/convert.base64-encode/resource=data/firma_logo.png'],
            'file://'      => ['file:///etc/passwd'],
            'Endung groß'  => ['data/firma_logo.PNG'],
            'Array'        => [['data/firma_logo.png']],
            'Zahl'         => [1],
            'Boolesch'     => [true],
        ];
    }

    /** L4 (Randfälle): auch Nicht-Strings führen zu 400 statt 500 und speichern nichts. */
    #[DataProvider('sonderwerteLogoEinstellung')]
    public function testSaveSettingsLehntSonderwerteAb(mixed $wert): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->upload('upload_logo', 'logo', $this->pngDatei()));

        $this->assertStatus(400, $this->api->post('save_settings', ['firma_logo_url' => $wert, 'firma_name' => 'Soll nicht gespeichert werden']));

        $settings = $this->assertOk($this->api->get('load_settings'))['settings'];
        $this->assertSame('data/firma_logo.png', $settings['firma_logo_url']);
        $this->assertNotSame('Soll nicht gespeichert werden', $settings['firma_name']);
    }

    /** L4 (erlaubte Werte) */
    public function testSaveSettingsAkzeptiertGueltigenLogoPfadUndLeerwert(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->post('save_settings', ['firma_logo_url' => 'data/firma_logo.png']));
        $this->assertSame('data/firma_logo.png', $this->assertOk($this->api->get('load_settings'))['settings']['firma_logo_url']);

        $this->assertOk($this->api->post('save_settings', ['firma_logo_url' => '']));
        $this->assertSame('', $this->assertOk($this->api->get('load_settings'))['settings']['firma_logo_url']);
    }

    /** L5 */
    public function testTextdateiUnterLogonamenWirdNichtAusgeliefert(): void
    {
        $this->setupAdmin();
        file_put_contents($this->server->dataPath('firma_logo.png'), self::MARKER . ' <script>alert(1)</script>');
        $this->setzeLogoEinstellungDirekt('data/firma_logo.png');

        $r = $this->server->client()->get('get_logo');

        $this->assertSame(404, $r->status, $this->kurz($r));
        $this->assertStringNotContainsString(self::MARKER, $r->body);
    }

    /** L6 (Abnahmekriterium 4): fremde Werte aus einer Sicherung erreichen den Browser nicht. */
    #[DataProvider('ungueltigeLogoEinstellungen')]
    public function testFremderLogoPfadWirdAnDenBrowserLeerAusgegeben(string $wert): void
    {
        $this->setupAdmin();
        $this->setzeLogoEinstellungDirekt($wert);

        $this->assertSame('', $this->assertOk($this->api->get('load_settings'))['settings']['firma_logo_url']);
        $this->assertSame('', $this->assertStatus(200, $this->api->get('check'))['settings']['firma_logo_url']);
    }

    /** Wie eine eingespielte Sicherung: Einstellung ohne save_settings direkt in der Datenbank setzen. */
    private function setzeLogoEinstellungDirekt(string $wert): void
    {
        $pdo = $this->server->db();
        $raw = $pdo->query('SELECT data FROM settings WHERE id = 1')->fetchColumn();
        $this->assertIsString($raw, 'Zeile settings.id = 1 fehlt.');
        $data = json_decode($raw, true) ?: [];
        $data['firma_logo_url'] = $wert;
        $pdo->prepare('UPDATE settings SET data = ? WHERE id = 1')->execute([json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $pdo = null;
        gc_collect_cycles();
    }

    private function pngDatei(): string
    {
        return $this->tempFile('.png', (string) base64_decode(self::PNG_1X1, true));
    }

    private function tempFile(string $suffix, string $inhalt): string
    {
        $basis = (string) tempnam(sys_get_temp_dir(), 'bklogo');
        $this->tempFiles[] = $basis;
        $pfad = $basis . $suffix;
        file_put_contents($pfad, $inhalt);
        $this->tempFiles[] = $pfad;
        return $pfad;
    }

    /** Antwort gekürzt, ohne Binär- oder Dateiinhalte in die Fehlermeldung zu kippen. */
    private function kurz(ApiResponse $r): string
    {
        return "[{$r->action}] HTTP {$r->status}, Content-Type " . ($r->header('Content-Type') ?? '-') . ', ' . strlen($r->body) . ' Byte';
    }
}
