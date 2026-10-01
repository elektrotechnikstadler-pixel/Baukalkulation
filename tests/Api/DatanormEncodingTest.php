<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;

final class DatanormEncodingTest extends ApiTestCase
{
    public static function datanormEncodings(): iterable
    {
        yield 'cp850' => ['CP850', false, 'CP850'];
        yield 'windows-1252' => ['Windows-1252', false, 'Windows-1252'];
        yield 'utf-8' => ['UTF-8', false, 'UTF-8'];
        yield 'utf-8-bom' => ['UTF-8', true, 'UTF-8'];
    }

    public static function legacyEncodingsAfterAsciiPrefix(): iterable
    {
        yield 'windows-1252' => ['Windows-1252', 'Artikel für Kupferleiter größer Querschnitt'];
        yield 'cp850' => ['CP850', 'Artikel für Kupferleiter größer Querschnitt'];
    }

    #[DataProvider('datanormEncodings')]
    public function testReindexAndSearchPreserveUmlauts(string $encoding, bool $withBom, string $expectedEncoding): void
    {
        $this->setupAdmin();
        $this->writeDatanormFiles($encoding, $withBom);

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));
        $this->assertSame(1, $reindex['count']);

        foreach (['kabelschuh', 'größer'] as $query) {
            $search = $this->assertStatus(200, $this->api->get('datanorm_search', ['q' => $query]));
            $this->assertCount(1, $search['results'], "Suchbegriff: {$query}");
            $this->assertSame('Kabelschuh für Kupferleiter größer Querschnitt', $search['results'][0]['bezeichnung']);
            $this->assertSame('Zubehör Ösen', $search['results'][0]['warengruppe']);
        }
    }

    #[DataProvider('datanormEncodings')]
    public function testReindexReportsDetectedEncoding(string $encoding, bool $withBom, string $expectedEncoding): void
    {
        $this->setupAdmin();
        $this->writeDatanormFiles($encoding, $withBom);

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));

        $this->assertSame($expectedEncoding, $reindex['encoding'] ?? null);
    }

    #[DataProvider('legacyEncodingsAfterAsciiPrefix')]
    public function testDetectsLegacyEncodingAfterAsciiPrefixLargerThanSample(string $encoding, string $expectedText): void
    {
        $this->setupAdmin();
        $asciiPrefix = str_repeat('A;N;ascii;ASCII-' . str_repeat('x', 900) . ";STK;0;1;1234;;1;01\r\n", 300);
        $this->assertGreaterThan(262144, strlen($asciiPrefix));

        $article = "A;N;legacy;" . $expectedText . ";;STK;0;1;1234;;1;01\r\n";
        $this->writeRawDatanormFiles($asciiPrefix . mb_convert_encoding($article, $encoding, 'UTF-8'));

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));
        $this->assertSame(301, $reindex['count']);
        $this->assertIndexedArticle('legacy', $expectedText);
    }

    public function testMixedCp850AndWindows1252ArticlesAreDecodedPerLine(): void
    {
        $this->setupAdmin();
        $cp850Article = "A;N;cp850;CP850 Artikel für größer;Querschnitt;STK;0;1;1234;;1;01\r\n";
        $windows1252Article = "A;N;win1252;Windows-1252 Artikel ß ä;Leitung;STK;0;1;1234;;1;01\r\n";
        $articles = mb_convert_encoding($cp850Article, 'CP850', 'UTF-8')
            . mb_convert_encoding($windows1252Article, 'Windows-1252', 'UTF-8');
        $this->writeRawDatanormFiles($articles);

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));
        $this->assertSame(2, $reindex['count']);
        $this->assertIndexedArticle('cp850', 'CP850 Artikel für größer Querschnitt');
        $this->assertIndexedArticle('win1252', 'Windows-1252 Artikel ß ä Leitung');
    }

    public function testCp850LineInUtf8FileIsDecodedAsCp850(): void
    {
        $this->setupAdmin();
        $utf8Article = "A;N;utf8;UTF-8 Artikel für " . str_repeat('x', 900) . ";Leitung;STK;0;1;1234;;1;01\r\n";
        $utf8Prefix = str_repeat($utf8Article, 300);
        $cp850Article = "A;N;cp850;CP850 Artikel für größer;Querschnitt;STK;0;1;1234;;1;01\r\n";
        $this->assertGreaterThan(262144, strlen($utf8Prefix));
        $this->writeRawDatanormFiles($utf8Prefix . mb_convert_encoding($cp850Article, 'CP850', 'UTF-8'));

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));
        $this->assertSame(301, $reindex['count']);
        $this->assertIndexedArticle('cp850', 'CP850 Artikel für größer Querschnitt');
    }

    public function testBomAtStartOfLaterArticleTextIsPreservedAndArticleIndexed(): void
    {
        $this->setupAdmin();
        $firstArticle = "A;N;first;Erster Artikel;Leitung;STK;0;1;1234;;1;01\r\n";
        $laterArticle = "A;N;later;\xEF\xBB\xBFBOM-Artikel;Leitung;STK;0;1;1234;;1;01\r\n";
        $this->writeRawDatanormFiles($firstArticle . $laterArticle);

        $reindex = $this->assertOk($this->api->post('datanorm_reindex'));
        $this->assertSame(2, $reindex['count']);
        $this->assertIndexedArticle('later', "\u{FEFF}BOM-Artikel Leitung");
    }

    private function writeDatanormFiles(string $encoding, bool $withBom): void
    {
        $directory = $this->server->dataPath('datanorm');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $article = "A;N;4711;Kabelschuh für Kupferleiter;größer Querschnitt;STK;0;1;1234;;1;01\r\n";
        $group = "S;1;01;Zubehör Ösen\r\n";
        $bom = $withBom ? "\xEF\xBB\xBF" : '';

        file_put_contents($this->server->dataPath('datanorm/datanorm.001'), $bom . mb_convert_encoding($article, $encoding, 'UTF-8'));
        file_put_contents($this->server->dataPath('datanorm/datanorm.wrg'), mb_convert_encoding($group, $encoding, 'UTF-8'));
    }

    private function writeRawDatanormFiles(string $articles): void
    {
        $directory = $this->server->dataPath('datanorm');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->server->dataPath('datanorm/datanorm.001'), $articles);
        file_put_contents($this->server->dataPath('datanorm/datanorm.wrg'), "S;1;01;Zubehör Ösen\r\n");
    }

    private function assertIndexedArticle(string $query, string $expectedText): void
    {
        $search = $this->assertStatus(200, $this->api->get('datanorm_search', ['q' => $query]));
        $this->assertCount(1, $search['results'], "Artikelnummer: {$query}");
        $this->assertSame($expectedText, $search['results'][0]['bezeichnung']);
    }
}
