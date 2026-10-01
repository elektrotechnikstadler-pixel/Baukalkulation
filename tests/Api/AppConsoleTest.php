<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\Migrator;
use App\Services\UpdatePruefung;

/** AP-20261001-update, Stufe 1 Schritt 4: `app:info`, `app:check-update` (AK 7, 9). */
final class AppConsoleTest extends ApiTestCase
{
    public function testAppInfoZeigtVersionUndSchema(): void
    {
        $this->setupAdmin();

        [$code, $out] = $this->server->runCli('bin/console', ['app:info']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString($this->version(), $out);
        $this->assertStringContainsString((string) Migrator::latestVersion(), $out);
        $this->assertStringContainsString($this->server->driver, $out);
    }

    public function testAppInfoOhneAbsolutePfadeUndSecrets(): void
    {
        $this->setupAdmin();

        [$code, $out] = $this->server->runCli('bin/console', ['app:info']);

        $this->assertSame(0, $code, $out);
        $norm = static fn(string $s): string => strtolower(str_replace('\\', '/', $s));
        $this->assertStringNotContainsString($norm($this->server->dataDir), $norm($out));
        $this->assertStringNotContainsString(self::ADMIN_PASS, $out);
    }

    public function testCheckUpdateOhneRepoExit1MitEinrichtungshinweis(): void
    {
        $this->setupAdmin();

        [$code, $out] = $this->server->runCli('bin/console', ['app:check-update']);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString(UpdatePruefung::MELDUNG_NICHT_KONFIGURIERT, $out);
        $this->assertFileDoesNotExist($this->server->dataPath('update_check.json'), 'Ohne Repo kein Abruf');
    }

    private function version(): string
    {
        return trim((string) file_get_contents($this->server->appRoot . '/VERSION'));
    }
}
