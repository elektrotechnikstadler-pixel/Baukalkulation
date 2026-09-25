<?php

declare(strict_types=1);

namespace Tests\Api;

final class ConsoleBackupTest extends ApiTestCase
{
    public function testSicherungPerConsoleAnlegenUndVerschluesseltEinspielen(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH']));
        $zip = sys_get_temp_dir() . '/bk_cli_' . bin2hex(random_bytes(4)) . '.zip';

        [$code, $out] = $this->server->runCli('bin/console', ['backup:create', '--name=Vor Test', "--zip={$zip}", '--password=Geheim-1']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('AES-256', $out);

        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Später angelegt']));

        [$code, $out] = $this->server->runCli('bin/console', ['backup:import', $zip, '--yes']);
        $this->assertSame(1, $code, 'Ohne Passwort muss der Import scheitern: ' . $out);

        [$code, $out] = $this->server->runCli('bin/console', ['backup:import', $zip, '--password=Geheim-1', '--yes']);
        unlink($zip);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Sicherheitskopie', $out);
        $this->assertSame(['Muster GmbH'], array_column($this->assertOk($this->api->get('load_kunden'))['kunden'], 'firma'));
    }

    public function testAlteSicherungPerConsoleInLeereInstallation(): void
    {
        [$code, $out] = $this->server->runCli('bin/console', ['backup:import', __DIR__ . '/../fixtures/backups/v2.10.99.zip', '--yes']);
        $this->assertSame(0, $code, $out);

        $this->assertOk($this->api->post('login', ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASS]));
        $this->assertSame(['Neubau Musterstraße', 'Garage Huber'], array_column($this->assertOk($this->api->get('load'))['data']['baustellen'], 'name'));
    }
}
