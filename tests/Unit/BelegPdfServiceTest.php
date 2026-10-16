<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BelegPdfService;
use App\Services\ZugferdService;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Rechnungs-PDF einheitlich: „📄 PDF“, ZUGFeRD, E-Mail und „In Dateien speichern“
 * nutzen dasselbe Layout und dieselbe Summenbildung.
 */
final class BelegPdfServiceTest extends TestCase
{
    private const SETTINGS = [
        'firma_name' => 'Muster GmbH', 'firma_strasse' => 'Hauptstr. 1', 'firma_plz' => '12345',
        'firma_ort' => 'Musterstadt', 'firma_steuernr' => '12/345/67890', 'firma_mwst_satz' => 19,
    ];

    /** @return array<int,array<string,mixed>> */
    private static function positionen(): array
    {
        return [
            ['id' => 'a', 'posTyp' => 'normal', 'bezeichnung' => 'Kabel', 'menge' => 3, 'einheit' => 'm³', 'einzelpreis' => 10.0, 'rabatt' => 10],
            ['id' => 'g', 'posTyp' => 'gruppe', 'bezeichnung' => 'Elektro', 'menge' => 0, 'einheit' => '', 'einzelpreis' => 0],
            ['id' => 'g1', 'parentId' => 'g', 'posTyp' => 'normal', 'bezeichnung' => 'Steckdose', 'menge' => 2, 'einheit' => 'Stück', 'einzelpreis' => 12.5],
            ['id' => 'g2', 'parentId' => 'g', 'posTyp' => 'freitext', 'bezeichnung' => 'Hinweis', 'menge' => 0, 'einheit' => '', 'einzelpreis' => 0],
            ['id' => 'e', 'posTyp' => 'eventual', 'bezeichnung' => 'Option', 'menge' => 1, 'einheit' => 'psch', 'einzelpreis' => 100],
            ['id' => 'k', 'typ' => 'material', 'bezeichnung' => 'Altposition ohne posTyp', 'menge' => 1, 'einheit' => 'h', 'einzelpreis' => 58],
        ];
    }

    public function testSummeMitRabattGruppenUndOhneEventualpositionen(): void
    {
        // 3 × 10 − 10 % = 27,00 · Gruppe 2 × 12,50 = 25,00 · Altposition 58,00 · Eventual zählt nicht
        $this->assertSame(110.0, BelegPdfService::nettoSumme(self::positionen()));
        $this->assertSame(0.0, BelegPdfService::positionsNetto(['posTyp' => 'freitext', 'menge' => 5, 'einzelpreis' => 9]));
        $this->assertSame(0.33, BelegPdfService::positionsNetto(['menge' => 1, 'einzelpreis' => 0.333]));
    }

    public function testPositionsnummernMitUnterpositionen(): void
    {
        $nummern = array_column(BelegPdfService::nummerierePositionen(self::positionen()), 'nr');
        $this->assertSame(['1.', '2.', '2.1', '2.2', '3.', '4.'], $nummern);
    }

    public function testPdfZeigtGespeicherteEinheitenUndSummen(): void
    {
        $html = (new BelegPdfService())->buildHtml(
            ['nummer' => 'RE-2026-0001', 'datum' => '2026-10-16'],
            self::positionen(),
            null,
            self::SETTINGS,
            'rechnung',
        );

        $this->assertStringContainsString('<td class="c-ein">m³</td>', $html);
        $this->assertStringContainsString('<td class="c-ein">Stück</td>', $html);
        $this->assertStringContainsString('vom 16.10.2026', $html);
        $this->assertStringContainsString('130,90', $html, 'Brutto = 110,00 + 19 % MwSt');
        $this->assertStringNotContainsString('ZUGFeRD', $html, 'Plain-PDF ist keine E-Rechnung');
    }

    public function testUngespeicherterBelegOhneNummer(): void
    {
        $html = (new BelegPdfService())->buildHtml(['datum' => '2026-10-16'], [], null, self::SETTINGS, 'angebot');

        $this->assertStringContainsString('Angebot vom 16.10.2026', $html);
        $this->assertStringNotContainsString('Nr. </', $html);
    }

    public function testLayoutBleibtDompdfTauglich(): void
    {
        $html = (new BelegPdfService())->buildHtml(['nummer' => 'R-1'], self::positionen(), null, self::SETTINGS, 'rechnung');

        // Dompdf verteilt Spalten bei table-layout:fixed gleichmäßig → Bezeichnung wäre zu schmal.
        $this->assertStringNotContainsString('table-layout: fixed;', $html);
        $this->assertMatchesRegularExpression('/<th style="width:\d+mm;">Pos\.<\/th><th>Bezeichnung<\/th>/', $html);
        // Falz-/Lochmarken und Fußzeile liegen im Seitenrand, nicht im Inhaltsbereich.
        $this->assertMatchesRegularExpression('/\.foldmark\s*\{[^}]*left:\s*-20mm/', $html);
        $this->assertMatchesRegularExpression('/\.page-footer\s*\{[^}]*bottom:\s*-\d+mm/', $html);
    }

    public function testZugferdXmlStimmtMitSichtbarerSummeUeberein(): void
    {
        $pdf = (new ZugferdService())->generate(
            ['nummer' => 'RE-2026-0002', 'datum' => '2026-10-16'],
            [],
            self::positionen(),
            null,
            self::SETTINGS,
            'Neubau Musterweg',
        );

        $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($pdf);
        $grand = $due = $line = $charge = $allowance = $basis = $tax = $rounding = $prepaid = null;
        $reader->getDocumentSummation($grand, $due, $line, $charge, $allowance, $basis, $tax, $rounding, $prepaid);
        $this->assertEqualsWithDelta(110.0, $line, 0.001);
        $this->assertEqualsWithDelta(130.9, $grand, 0.001);

        $ids = [];
        if ($reader->firstDocumentPosition()) {
            do {
                $id = $status = $reason = null;
                $reader->getDocumentPositionGenerals($id, $status, $reason);
                $ids[] = $id;
            } while ($reader->nextDocumentPosition());
        }
        $this->assertSame(['1', '2.1', '4'], $ids, 'Nur berechnete Positionen, Nummern wie im PDF');
    }
}
