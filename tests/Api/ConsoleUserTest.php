<?php

declare(strict_types=1);

namespace Tests\Api;

final class ConsoleUserTest extends ApiTestCase
{
    public function testPasswortPerConsoleZuruecksetzenEntsperrtKonto(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $client = $this->server->client();
        for ($i = 0; $i < 3; $i++) {
            $client->post('login', ['username' => 'monteur', 'password' => 'falsch']);
        }

        [$code, $out] = $this->server->runCli('bin/console', ['user:reset-password', 'monteur', '--password=Neues-Passwort-1']);
        $this->assertSame(0, $code, $out);

        $login = $this->assertOk($client->post('login', ['username' => 'monteur', 'password' => 'Neues-Passwort-1']));
        $this->assertTrue($login['mustChangePassword']);
    }

    public function testUnbekannterBenutzer(): void
    {
        $this->setupAdmin();

        [$code] = $this->server->runCli('bin/console', ['user:reset-password', 'niemand', '--password=Neues-Passwort-1']);

        $this->assertSame(1, $code);
    }
}
