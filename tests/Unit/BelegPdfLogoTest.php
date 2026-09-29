<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BelegPdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AP-20260929-sicherheit, Abnahmekriterium 5: Beleg-HTML bettet keine Datei über einen fremden firma_logo_url ein. */
final class BelegPdfLogoTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function fremdeLogoPfade(): array
    {
        return [
            'Bild außerhalb des Datenverzeichnisses' => ['public/planning-dashboard-logo.png'],
            'Projektdatei'                           => ['VERSION'],
            'Traversal'                              => ['data/../VERSION'],
            'php://filter'                           => ['php://filter/convert.base64-encode/resource=VERSION'],
            'SVG'                                    => ['data/firma_logo.svg'],
        ];
    }

    #[DataProvider('fremdeLogoPfade')]
    public function testFremderLogoPfadErzeugtKeinBild(string $pfad): void
    {
        $html = (new BelegPdfService())->buildHtml(
            ['nummer' => 'R-1', 'datum' => '2026-09-29'],
            [],
            null,
            ['firma_name' => 'Müller GmbH', 'firma_logo_url' => $pfad],
            'rechnung',
        );

        $this->assertStringContainsString('Müller GmbH', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('data:', $html);
    }
}
