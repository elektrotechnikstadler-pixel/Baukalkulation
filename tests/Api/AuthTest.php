<?php

declare(strict_types=1);

namespace Tests\Api;

final class AuthTest extends ApiTestCase
{
    private const FIRMENDATEN = [
        'firma_name' => 'Elektro Testfirma Nord',
        'firma_iban' => 'DE02120300000000202051',
        'smtp_host'  => 'smtp.testfirma-nord.example',
        'smtp_user'  => 'versand@testfirma-nord.example',
    ];

    public function testFrischeInstallationVerlangtSetup(): void
    {
        $json = $this->api->get('check')->json();

        $this->assertFalse($json['loggedIn']);
        $this->assertTrue($json['needSetup']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $json['version']);
        $this->assertSame(trim((string) file_get_contents($this->server->appRoot . '/VERSION')), $json['version']);
    }

    public function testSetupLegtAdminAnUndMeldetIhnAn(): void
    {
        $this->setupAdmin();

        $json = $this->api->get('check')->json();
        $this->assertTrue($json['loggedIn']);
        $this->assertFalse($json['needSetup']);
        $this->assertSame(self::ADMIN_USER, $json['username']);
        $this->assertSame('admin', $json['role']);
        $this->assertFalse($json['mustChangePassword']);
    }

    public function testSetupIstNurEinmalMoeglich(): void
    {
        $this->setupAdmin();

        $this->assertStatus(403, $this->server->client()->post('setup', ['username' => 'zweiter', 'password' => 'Passwort-1']));
    }

    public function testSetupValidiertEingaben(): void
    {
        $this->assertStatus(400, $this->api->post('setup', ['username' => 'ab', 'password' => 'Passwort-1']));
        $this->assertStatus(400, $this->api->post('setup', ['username' => 'admin', 'password' => '12345']));
    }

    public function testGeschuetzteAktionOhneLoginLiefert401(): void
    {
        $this->setupAdmin();

        $json = $this->assertStatus(401, $this->server->client()->get('load'));
        $this->assertSame('login.html', $json['redirect']);
    }

    public function testUnbekannteAktionLiefert400(): void
    {
        $this->assertStatus(400, $this->api->get('gibt_es_nicht'));
    }

    public function testLogoutBeendetDieSession(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('logout'));

        $this->assertFalse($this->api->get('check')->json()['loggedIn']);
        $this->assertStatus(401, $this->api->get('load'));
    }

    public function testLoginMitKorrektemPasswort(): void
    {
        $this->setupAdmin();
        $client = $this->server->client();

        $this->assertOk($client->post('login', ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASS]));
        $this->assertTrue($client->get('check')->json()['loggedIn']);
    }

    public function testKontoWirdNachDreiFehlversuchenGesperrt(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $client = $this->server->client();

        $this->assertStatus(401, $client->post('login', ['username' => 'monteur', 'password' => 'falsch']));
        $this->assertStatus(401, $client->post('login', ['username' => 'monteur', 'password' => 'falsch']));
        $locked = $this->assertStatus(403, $client->post('login', ['username' => 'monteur', 'password' => 'falsch']));
        $this->assertTrue($locked['locked']);

        // Auch das richtige Passwort hilft nach der Sperre nicht mehr.
        $this->assertStatus(403, $client->post('login', ['username' => 'monteur', 'password' => 'Monteur-Pass-1']));

        $this->assertOk($this->api->post('unlock_user', ['username' => 'monteur']));
        $this->assertOk($client->post('login', ['username' => 'monteur', 'password' => 'Monteur-Pass-1']));
    }

    public function testUnbekannterBenutzerLiefert401(): void
    {
        $this->setupAdmin();

        $this->assertStatus(401, $this->server->client()->post('login', ['username' => 'niemand', 'password' => 'egal-123']));
    }

    public function testNeuerBenutzerMussPasswortAendern(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('add_user', ['username' => 'neuling', 'password' => 'Start-Pass-1', 'role' => 'normal']));
        $client = $this->server->client();

        $login = $this->assertOk($client->post('login', ['username' => 'neuling', 'password' => 'Start-Pass-1']));
        $this->assertTrue($login['mustChangePassword']);

        $blocked = $this->assertStatus(403, $client->get('load'));
        $this->assertTrue($blocked['mustChangePassword']);

        $this->assertStatus(401, $client->post('change_password', ['old' => 'falsch', 'new' => 'Neu-Pass-1']));
        $this->assertOk($client->post('change_password', ['old' => 'Start-Pass-1', 'new' => 'Neu-Pass-1']));
        $this->assertOk($client->get('load'));
    }

    public function testNormalerBenutzerDarfKeineAdminAktionen(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->get('list_users'));
        $this->assertStatus(403, $monteur->post('add_user', ['username' => 'x-user', 'password' => 'Pass-123']));
        $this->assertStatus(403, $monteur->get('backups'));
    }

    public function testSystemadminBekommtIndividuellesStartpasswort(): void
    {
        $this->setupAdmin();
        $this->api->get('check');

        $client = $this->server->client();

        $file = $this->server->dataPath('systemadmin-passwort.txt');
        $this->assertFileExists($file);
        $password = trim((string) file($file)[1]);
        $this->assertSame(20, strlen($password));
        $login = $this->assertOk($client->post('login', ['username' => 'Systemadmin', 'password' => $password]));
        $this->assertTrue($login['mustChangePassword']);
    }

    public function testGeheimeEinstellungenWerdenNieAusgeliefert(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['smtp_pass' => 'Smtp-Geheim-1', 'gemini_api_key' => 'AIza-geheim']));
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        foreach ([$this->api->get('check'), $monteur->get('check'), $this->api->get('load_settings')] as $r) {
            $this->assertStringNotContainsString('Smtp-Geheim-1', $r->body);
            $this->assertStringNotContainsString('AIza-geheim', $r->body);
            $settings = $r->json()['settings'];
            $this->assertSame('', $settings['smtp_pass']);
            $this->assertTrue($settings['smtp_pass_gesetzt']);
            $this->assertTrue($settings['gemini_api_key_gesetzt']);
        }

        $pdo = $this->server->db();
        $raw = (string) $pdo->query('SELECT data FROM settings WHERE id = 1')->fetchColumn();
        $this->assertStringNotContainsString('Smtp-Geheim-1', $raw);
        $this->assertStringStartsWith('enc:v1:', json_decode($raw, true)['smtp_pass']);
    }

    public function testGeheimeEinstellungBleibtBeiAnderenAenderungenErhalten(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['smtp_pass' => 'Smtp-Geheim-1']));

        $this->assertOk($this->api->post('save_settings', ['firma_name' => 'Elektro Muster']));
        $this->assertTrue($this->api->get('load_settings')->json()['settings']['smtp_pass_gesetzt']);

        $this->assertOk($this->api->post('save_settings', ['smtp_pass' => '']));
        $this->assertFalse($this->api->get('load_settings')->json()['settings']['smtp_pass_gesetzt']);
    }

    private function schreibeLizenz(): void
    {
        file_put_contents($this->server->dataPath('license.json'), json_encode([
            'customer' => 'Test Lizenznehmer GmbH',
            'expires'  => '2099-12-31',
            'tier'     => 'professional',
            'modules'  => ['*'],
        ]));
    }

    /** C1 (AP-20260929-sicherheit) */
    public function testCheckOhneAnmeldungLiefertKeineEinstellungenUndKeinenLizenznehmer(): void
    {
        $this->setupAdmin();
        $this->schreibeLizenz();
        $this->assertOk($this->api->post('save_settings', self::FIRMENDATEN));

        $r = $this->server->client()->get('check');
        $json = $this->assertStatus(200, $r);

        $this->assertFalse($json['loggedIn']);
        $this->assertSame([], $json['settings']);
        $this->assertInstanceOf(\stdClass::class, json_decode($r->body)->settings);
        foreach ([...array_values(self::FIRMENDATEN), 'Test Lizenznehmer GmbH', '2099-12-31'] as $wert) {
            $this->assertStringNotContainsString($wert, $r->body);
        }
        $this->assertSame('professional', $json['license']['tier']);
        $this->assertArrayHasKey('customer', $json['license']);
        $this->assertNull($json['license']['customer']);
        $this->assertArrayHasKey('expires', $json['license']);
        $this->assertNull($json['license']['expires']);
        $this->assertFalse($json['needSetup']);
        $this->assertNull($json['username']);
        $this->assertNull($json['role']);
        $this->assertSame([], $json['permissions']);
        $this->assertSame([], $json['modules']);
    }

    /** C2: Docker-Healthcheck ruft check vor der Einrichtung ohne Anmeldung auf. */
    public function testCheckVorEinrichtungLiefert200(): void
    {
        $json = $this->assertStatus(200, $this->api->get('check'));

        $this->assertFalse($json['loggedIn']);
        $this->assertTrue($json['needSetup']);
        $this->assertNotEmpty($json['version']);
        $this->assertArrayHasKey('settings', $json);
        $this->assertArrayHasKey('tier', $json['license']);
    }

    /** C3: angemeldet bleibt die Antwort wie bisher. */
    public function testCheckAngemeldetLiefertEinstellungenWieBisher(): void
    {
        $this->setupAdmin();
        $this->schreibeLizenz();
        $this->assertOk($this->api->post('save_settings', self::FIRMENDATEN + ['smtp_pass' => 'Smtp-Geheim-1']));
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $erwarteteSchluessel = array_keys($this->assertOk($this->api->get('load_settings'))['settings']);
        sort($erwarteteSchluessel);

        foreach (['admin' => $this->api, 'monteur' => $monteur] as $wer => $client) {
            $json = $this->assertStatus(200, $client->get('check'));
            $this->assertTrue($json['loggedIn'], $wer);
            $settings = $json['settings'];
            foreach (self::FIRMENDATEN as $key => $wert) {
                $this->assertSame($wert, $settings[$key], "{$wer}: {$key}");
            }
            $this->assertSame('', $settings['smtp_pass'], $wer);
            $this->assertTrue($settings['smtp_pass_gesetzt'], $wer);
            $schluessel = array_keys($settings);
            sort($schluessel);
            $this->assertSame($erwarteteSchluessel, $schluessel, $wer);
            $this->assertSame('Test Lizenznehmer GmbH', $json['license']['customer'], $wer);
            $this->assertSame('2099-12-31', $json['license']['expires'], $wer);
        }
    }
}
