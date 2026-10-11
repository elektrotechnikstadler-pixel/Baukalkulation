<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Wächter gegen doppelt UTF-8-kodierte Umlaute (U+00C3 U+00BC statt „ü“), wie sie entstehen,
 * wenn ein Werkzeug eine UTF-8-Datei als Windows-1252 liest und als UTF-8 zurückschreibt.
 * Solche Texte landen sonst unbemerkt in Fehlermeldungen und Oberflächen.
 */
final class QuelltextKodierungTest extends TestCase
{
    public function testQuelltextEnthaeltKeineDoppeltKodiertenUmlaute(): void
    {
        $root = dirname(__DIR__, 2);
        $betroffen = [];
        foreach (['src', 'modules', 'public', 'tests'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (!preg_match('/\.(php|js|html|css)$/', $path) || str_contains($path, '.min.') || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $inhalt = (string) file_get_contents($path);
                // U+00C3 gefolgt von U+0080–U+00BF bzw. U+00E2 U+20AC (Anfang von –, „, “).
                if (preg_match('/\x{00C3}[\x{0080}-\x{00BF}]|\x{00E2}\x{20AC}/u', $inhalt)) {
                    $betroffen[] = substr($path, strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $betroffen, 'Doppelt kodierte Umlaute gefunden.');
    }
}
