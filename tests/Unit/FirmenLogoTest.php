<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FirmenLogo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AP-20260929-sicherheit: FirmenLogo akzeptiert nur DATA_DIR/firma_logo.{png,jpg,gif,webp} mit Bild-MIME. */
final class FirmenLogoTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private string $root;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bk-logo-' . bin2hex(random_bytes(4));
        $this->dataDir = $this->root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
        mkdir($this->dataDir, 0777, true);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @return array<string, array{string, string, string}> */
    public static function gueltigeBilder(): array
    {
        return [
            'PNG'                => ['firma_logo.png', 'data/firma_logo.png', self::PNG_1X1],
            'PNG mit Schrägstrich' => ['firma_logo.png', '/data/firma_logo.png', self::PNG_1X1],
            'GIF'                => ['firma_logo.gif', 'data/firma_logo.gif', self::GIF_1X1],
        ];
    }

    /** U1 */
    #[DataProvider('gueltigeBilder')]
    public function testGueltigesLogoLiefertPfadUndMime(string $datei, string $einstellung, string $base64): void
    {
        $this->schreibe($datei, (string) base64_decode($base64, true));

        $logo = FirmenLogo::resolve(['firma_logo_url' => $einstellung], $this->dataDir);

        $this->assertNotNull($logo);
        $this->assertSame(realpath($this->dataDir . $datei), $logo['path']);
        $this->assertSame(str_ends_with($datei, '.gif') ? 'image/gif' : 'image/png', $logo['mime']);
    }

    /** U2 */
    public function testDatenbankImDatenverzeichnisWirdNichtGeliefert(): void
    {
        $this->schreibe('database.sqlite', "SQLite format 3\0" . str_repeat("\0", 100));

        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => 'data/database.sqlite'], $this->dataDir));
        $this->assertFalse(FirmenLogo::isValidSetting('data/database.sqlite'));
    }

    /** @return array<string, array{string}> */
    public static function fremdeWerte(): array
    {
        return [
            'Traversal'         => ['../x'],
            'SVG'               => ['data/firma_logo.svg'],
            'doppelte Endung'   => ['data/firma_logo.png.php'],
            'externe URL'       => ['https://example.org/x.png'],
            'http://'           => ['http://127.0.0.1/data/firma_logo.png'],
            'file://'           => ['file:///data/firma_logo.png'],
            'php://filter'      => ['php://filter/convert.base64-encode/resource=data/firma_logo.png'],
            'Nullbyte am Ende'  => ["data/firma_logo.png\0.php"],
            'Nullbyte vor Endung' => ["data/firma_logo\0.png"],
            'Endung groß'       => ['data/firma_logo.PNG'],
            'Name groß'         => ['DATA/FIRMA_LOGO.png'],
            'Backslash'         => ['data\\firma_logo.png'],
            'Leerzeichen vorn'  => [' data/firma_logo.png'],
            'doppelter Schrägstrich' => ['//data/firma_logo.png'],
        ];
    }

    /** U3: auch wenn daneben ein gültiges Logo liegt, zählt nur der geprüfte Wert. */
    #[DataProvider('fremdeWerte')]
    public function testFremderWertLiefertNull(string $einstellung): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'x', 'außerhalb');
        $this->schreibe('firma_logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->schreibe('firma_logo.png.php', '<?php echo "x";');
        $this->schreibe('firma_logo.png', (string) base64_decode(self::PNG_1X1, true));

        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => $einstellung], $this->dataDir));
        $this->assertFalse(FirmenLogo::isValidSetting($einstellung));
    }

    /** U4 */
    public function testTextdateiUnterLogonamenLiefertNull(): void
    {
        $this->schreibe('firma_logo.png', 'kein Bild <script>alert(1)</script>');

        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => 'data/firma_logo.png'], $this->dataDir));
    }

    /** U5 */
    public function testFehlendeDateiLiefertNull(): void
    {
        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => 'data/firma_logo.png'], $this->dataDir));
        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => ''], $this->dataDir));
        $this->assertNull(FirmenLogo::resolve([], $this->dataDir));
    }

    /** @return array<string, array{string, bool}> */
    public static function einstellungen(): array
    {
        return [
            'leer'                  => ['', true],
            'png'                   => ['data/firma_logo.png', true],
            'jpg'                   => ['data/firma_logo.jpg', true],
            'jpeg'                  => ['data/firma_logo.jpeg', true],
            'gif'                   => ['data/firma_logo.gif', true],
            'webp'                  => ['data/firma_logo.webp', true],
            'mit Schrägstrich'      => ['/data/firma_logo.png', true],
            'svg'                   => ['data/firma_logo.svg', false],
            'anderer Name'          => ['data/logo.png', false],
            'Umweg über ..'         => ['data/../data/firma_logo.png', false],
            'Zeilenumbruch am Ende' => ["data/firma_logo.png\n", false],
            'Projektdatei'          => ['VERSION', false],
        ];
    }

    /** U6 */
    #[DataProvider('einstellungen')]
    public function testIsValidSetting(string $einstellung, bool $erwartet): void
    {
        $this->assertSame($erwartet, FirmenLogo::isValidSetting($einstellung));
    }

    /** U7: Symlink aus dem Datenverzeichnis heraus wird nicht verfolgt. */
    public function testSymlinkAusDemDatenverzeichnisLiefertNull(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlinks brauchen unter Windows Sonderrechte.');
        }
        $ziel = $this->root . DIRECTORY_SEPARATOR . 'fremd.png';
        file_put_contents($ziel, (string) base64_decode(self::PNG_1X1, true));
        symlink($ziel, $this->dataDir . 'firma_logo.png');

        $this->assertNull(FirmenLogo::resolve(['firma_logo_url' => 'data/firma_logo.png'], $this->dataDir));
    }

    private function schreibe(string $datei, string $inhalt): void
    {
        file_put_contents($this->dataDir . $datei, $inhalt);
    }
}
