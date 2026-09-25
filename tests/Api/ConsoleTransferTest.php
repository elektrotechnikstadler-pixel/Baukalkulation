<?php

declare(strict_types=1);

namespace Tests\Api;

/** db:transfer: PostgreSQL → SQLite → PostgreSQL ohne Datenverlust. */
final class ConsoleTransferTest extends ApiTestCase
{
    public function testRundreisePostgresqlSqlitePostgresql(): void
    {
        if (!$this->server->isPgsql()) {
            $this->markTestSkipped('Benötigt PostgreSQL (BK_DB_DRIVER=pgsql).');
        }
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH']));
        $this->saveBaustellen([['id' => 7, 'name' => 'Neubau Musterstraße', 'kundeId' => 1]]);
        $file = sys_get_temp_dir() . '/bk_transfer_' . bin2hex(random_bytes(4)) . '.sqlite';

        try {
            [$code, $out] = $this->server->runCli('bin/console', ['db:transfer', 'to-sqlite', "--sqlite={$file}"]);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('Übertragung abgeschlossen', $out);

            [$code, $out] = $this->server->runCli('bin/console', ['db:transfer', 'to-sqlite', "--sqlite={$file}"]);
            $this->assertSame(1, $code, 'Ohne --force darf ein befülltes Ziel nicht überschrieben werden: ' . $out);

            $this->server->resetDatabase();
            [$code, $out] = $this->server->runCli('bin/console', ['db:transfer', 'to-pgsql', "--sqlite={$file}"]);
            $this->assertSame(0, $code, $out);
        } finally {
            gc_collect_cycles();
            if (is_file($file)) {
                unlink($file);
            }
        }

        $client = $this->server->client();
        $this->assertOk($client->post('login', ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASS]));
        $this->assertSame(['Neubau Musterstraße'], array_column($this->assertOk($client->get('load'))['data']['baustellen'], 'name'));

        // Identity-Zähler wurden nachgezogen: neue Datensätze kollidieren nicht mit übertragenen IDs.
        $neu = $this->assertOk($client->post('save_kunde', ['firma' => 'Neu nach Umzug']));
        $this->assertSame(2, (int) $neu['kunde']['id']);
    }
}
