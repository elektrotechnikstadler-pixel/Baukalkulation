<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Migrator;

final class MigrationTest extends ApiTestCase
{
    private function db(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->server->dataPath('database.sqlite'));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return list<string> */
    private function columns(\PDO $db, string $table): array
    {
        return array_column($db->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC), 'name');
    }

    public function testNeueDatenbankHatAktuelleSchemaVersion(): void
    {
        $this->setupAdmin();

        $this->assertSame(Migrator::latestVersion(), Migrator::currentVersion($this->db()));
    }

    public function testAlteDatenbankOhneVersionWirdBeimStartAngehoben(): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(__DIR__ . '/../fixtures/backups/v2.10.99.zip'));
        file_put_contents($this->server->dataPath('database.sqlite'), $zip->getFromName('database.sqlite'));
        $zip->close();

        // Älteren Stand simulieren: später ergänzte Spalten und Tabellen entfernen.
        $db = $this->db();
        $db->exec('DROP INDEX IF EXISTS idx_zeit_uuid');
        $db->exec('DROP INDEX IF EXISTS idx_zeit_status');
        $db->exec('ALTER TABLE zeiterfassung DROP COLUMN quelle');
        $db->exec('ALTER TABLE users DROP COLUMN sessionInvalidatedAt');
        $db->exec('DROP TABLE gruppen_mitglieder');
        $db->exec('DROP TABLE gruppen');
        $this->assertSame(0, Migrator::currentVersion($db));
        $db = null;

        $this->assertOk($this->api->post('login', ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASS]));
        $this->assertSame(['fx-1', 'fx-2'], array_column($this->assertOk($this->api->get('load_zeiterfassung'))['entries'], 'clientUuid'));
        $this->assertOk($this->api->post('save_gruppe', ['name' => 'Team A']));

        $db = $this->db();
        $this->assertSame(Migrator::latestVersion(), Migrator::currentVersion($db));
        $this->assertContains('quelle', $this->columns($db, 'zeiterfassung'));
        $this->assertContains('sessionInvalidatedAt', $this->columns($db, 'users'));
        $this->assertNotFalse($db->query("SELECT 1 FROM sqlite_master WHERE name = 'idx_zeit_uuid'")->fetchColumn());
    }

    public function testNeueresSchemaAlsDerCodeWirdAbgelehnt(): void
    {
        $this->setupAdmin();
        $this->db()->exec('INSERT INTO ' . Migrator::TABLE . " (version, migration_name) VALUES (99991231235959, 'zukunft')");

        $json = $this->assertStatus(503, $this->server->client()->get('check'));

        $this->assertStringContainsString('neuer als diese App-Version', $json['error']);
    }

    public function testConsoleMeldetSchemaStatus(): void
    {
        [$code, $out] = $this->server->runCli('bin/console', ['db:status']);
        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('offen', $out);

        [$code, $out] = $this->server->runCli('bin/console', ['db:migrate']);
        $this->assertSame(0, $code, $out);

        [$code, $out] = $this->server->runCli('bin/console', ['db:status']);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('offen', $out);
    }
}
