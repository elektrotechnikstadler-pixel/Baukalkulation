<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrator;
use App\Services\SystemInfo;
use PHPUnit\Framework\TestCase;

/**
 * Soll-Tests AP-20261001-update Stufe 1, Schritt 2: Systeminfo für das Update-Fenster
 * (Felder vorhanden, keine Secrets, keine absoluten Pfade – AK 1 und 6).
 */
final class SystemInfoTest extends TestCase
{
    private string $datenVerzeichnis;
    private string $sicherungsVerzeichnis;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->datenVerzeichnis = sys_get_temp_dir() . '/bk-sysinfo-' . bin2hex(random_bytes(6)) . '/';
        $this->sicherungsVerzeichnis = $this->datenVerzeichnis . 'backups/';
        mkdir($this->sicherungsVerzeichnis . '2026-09-28', 0777, true);
        mkdir($this->sicherungsVerzeichnis . '2026-09-30_2', 0777, true);
        mkdir($this->datenVerzeichnis . 'uploads/projekt-1', 0777, true);
        file_put_contents($this->sicherungsVerzeichnis . '2026-09-28/backup.json', '{}');
        file_put_contents($this->sicherungsVerzeichnis . '2026-09-30_2/backup.json', '{}');
        file_put_contents($this->datenVerzeichnis . 'uploads/projekt-1/foto.jpg', str_repeat('x', 1000));
        file_put_contents($this->datenVerzeichnis . 'settings.json', '{"smtp_password":"geheim-smtp","api_secret":"geheim-api"}');
        $this->db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        $dateien = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->datenVerzeichnis, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($dateien as $datei) {
            $datei->isDir() ? rmdir($datei->getPathname()) : unlink($datei->getPathname());
        }
        rmdir($this->datenVerzeichnis);
    }

    public function testFelderLautPlanVorhanden(): void
    {
        $info = $this->info()->sammeln();

        foreach (['version', 'schema', 'db_treiber', 'php', 'betriebsart', 'letzte_sicherung', 'datenverzeichnis', 'lizenz', 'module'] as $feld) {
            $this->assertArrayHasKey($feld, $info, "Feld {$feld} fehlt");
        }
        $this->assertSame(trim((string) file_get_contents(__DIR__ . '/../../VERSION')), $info['version']);
        $this->assertSame('sqlite', $info['db_treiber']);
        $this->assertContains($info['betriebsart'], ['docker', 'klassisch']);
        $this->assertIsArray($info['lizenz']);
        $this->assertArrayHasKey('tier', $info['lizenz']);
        $this->assertIsArray($info['module']);
    }

    public function testSchemaStandAusMigrator(): void
    {
        $schema = $this->info()->sammeln()['schema'];

        $this->assertSame(0, $schema['aktuell']);
        $this->assertSame(Migrator::latestVersion(), $schema['neueste']);
        $this->assertSame(count(Migrator::status($this->db)), $schema['offen']);
    }

    public function testPhpUndPflichtExtensions(): void
    {
        $php = $this->info()->sammeln()['php'];

        $this->assertSame(PHP_VERSION, $php['version']);
        foreach (['pdo_sqlite', 'pdo_pgsql', 'sodium', 'zip', 'gd', 'mbstring'] as $ext) {
            $this->assertArrayHasKey($ext, $php['extensions']);
            $this->assertSame(extension_loaded($ext), $php['extensions'][$ext]);
        }
    }

    public function testLetzteSicherungIstNeuesteNachName(): void
    {
        $sicherung = $this->info()->sammeln()['letzte_sicherung'];

        $this->assertIsArray($sicherung);
        $this->assertSame('2026-09-30_2', $sicherung['name']);
        $this->assertIsString($sicherung['zeit']);
    }

    public function testOhneSicherungNull(): void
    {
        $leer = $this->datenVerzeichnis . 'leer/';
        mkdir($leer);

        $info = new SystemInfo($this->db, $this->datenVerzeichnis, $leer);

        $this->assertNull($info->sammeln()['letzte_sicherung']);
    }

    public function testDatenverzeichnisWirdGezaehlt(): void
    {
        $dv = $this->info()->sammeln()['datenverzeichnis'];

        $this->assertSame(4, $dv['dateien']);
        $this->assertGreaterThanOrEqual(1000, $dv['bytes']);
        $this->assertFalse($dv['begrenzt']);
    }

    public function testDateiObergrenzeBegrenztZaehlung(): void
    {
        $dv = (new SystemInfo($this->db, $this->datenVerzeichnis, $this->sicherungsVerzeichnis, 2))->sammeln()['datenverzeichnis'];

        $this->assertSame(2, $dv['dateien']);
        $this->assertTrue($dv['begrenzt']);
    }

    public function testKeineSecretsSchluessel(): void
    {
        $schluessel = [];
        self::sammleSchluessel($this->info()->sammeln(), $schluessel);

        foreach ($schluessel as $key) {
            $this->assertDoesNotMatchRegularExpression('/password|passwort|smtp|secret|token|dsn/i', $key);
        }
    }

    public function testKeineSecretsWerteUndKeineAbsolutenPfade(): void
    {
        $json = (string) json_encode($this->info()->sammeln(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('geheim', $json);
        $this->assertDoesNotMatchRegularExpression('/password|smtp|secret/i', $json);
        $this->assertStringNotContainsString(str_replace('\\', '/', sys_get_temp_dir()), str_replace('\\\\', '/', $json));
        $this->assertStringNotContainsString(str_replace('\\', '/', dirname(__DIR__, 2)), str_replace('\\\\', '/', $json));
        $this->assertDoesNotMatchRegularExpression('#"(/[a-z]+){2,}|(?<![A-Za-z])[A-Za-z]:(\\\\\\\\|/)#', $json, 'absoluter Pfad in Ausgabe');
    }

    private function info(): SystemInfo
    {
        return new SystemInfo($this->db, $this->datenVerzeichnis, $this->sicherungsVerzeichnis);
    }

    /**
     * @param array<mixed> $daten
     * @param list<string> $schluessel
     */
    private static function sammleSchluessel(array $daten, array &$schluessel): void
    {
        foreach ($daten as $key => $wert) {
            $schluessel[] = (string) $key;
            if (is_array($wert)) {
                self::sammleSchluessel($wert, $schluessel);
            }
        }
    }
}
