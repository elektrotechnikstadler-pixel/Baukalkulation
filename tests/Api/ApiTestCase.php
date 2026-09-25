<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\ApiClient;
use Tests\Support\ApiResponse;
use Tests\Support\TestServer;

/**
 * Basis für Charakterisierungstests: jeder Test startet mit leerem Datenverzeichnis.
 * Die Tests halten das heutige API-Verhalten fest, damit Umbau und DB-Umstellung
 * nichts unbemerkt verändern.
 */
abstract class ApiTestCase extends TestCase
{
    protected const ADMIN_USER = 'admin';
    protected const ADMIN_PASS = 'Admin-Test-123';

    protected TestServer $server;
    protected ApiClient $api;

    protected function setUp(): void
    {
        $this->server = TestServer::instance();
        $this->server->resetData();
        $this->api = $this->server->client();
    }

    /** Ersteinrichtung; $this->api ist danach als Admin angemeldet. */
    protected function setupAdmin(): ApiClient
    {
        $this->assertOk($this->api->post('setup', ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASS]));
        return $this->api;
    }

    /** Legt einen Benutzer an und erledigt den erzwungenen Passwortwechsel. */
    protected function createActiveUser(string $username, string $password, string $role = 'normal'): ApiClient
    {
        $this->assertOk($this->api->post('add_user', [
            'username' => $username,
            'password' => 'Start-Passwort-1',
            'role'     => $role,
        ]));
        $client = $this->server->client();
        $this->assertOk($client->post('login', ['username' => $username, 'password' => 'Start-Passwort-1']));
        $this->assertOk($client->post('change_password', ['old' => 'Start-Passwort-1', 'new' => $password]));
        return $client;
    }

    protected function assertOk(ApiResponse $r): array
    {
        $this->assertSame(200, $r->status, $r->describe() . $this->server->errorLogTail());
        $json = $r->json();
        $this->assertTrue($json['ok'] ?? false, $r->describe());
        return $json;
    }

    protected function assertStatus(int $expected, ApiResponse $r): array
    {
        $this->assertSame($expected, $r->status, $r->describe() . $this->server->errorLogTail());
        return $r->json();
    }

    /** Legt eine Baustelle über den Voll-Save an und liefert die neue Revision. */
    protected function saveBaustellen(array $baustellen, ?int $baseRev = null, ?ApiClient $client = null): array
    {
        $client ??= $this->api;
        $body = ['data' => ['baustellen' => $baustellen]];
        if ($baseRev !== null) {
            $body['baseRev'] = $baseRev;
        }
        return $this->assertOk($client->post('save', $body));
    }
}
