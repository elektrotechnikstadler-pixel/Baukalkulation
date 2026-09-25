<?php

declare(strict_types=1);

namespace Tests\Api;

final class AuthTest extends ApiTestCase
{
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

    /**
     * Hält den heutigen Stand fest: nach dem Setup existiert "Systemadmin" mit fest
     * eingebautem Passwort. Wird mit der geplanten Sicherheitskorrektur angepasst.
     */
    public function testSystemadminWirdNachSetupMitStandardpasswortAngelegt(): void
    {
        $this->setupAdmin();
        $this->api->get('check');

        $login = $this->assertOk($this->server->client()->post('login', ['username' => 'Systemadmin', 'password' => 'Stadler2580!']));
        $this->assertTrue($login['mustChangePassword']);
    }
}
