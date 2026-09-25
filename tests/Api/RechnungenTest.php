<?php

declare(strict_types=1);

namespace Tests\Api;

final class RechnungenTest extends ApiTestCase
{
    public function testRechnungAnlegenListenAendernLoeschen(): void
    {
        $this->setupAdmin();
        $kundeId = $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH']))['kunde']['id'];
        $positionen = [['bezeichnung' => 'Montage', 'menge' => 2, 'einheit' => 'h', 'einzelpreis' => 58.0]];

        $re = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => [
            'typ' => 'rechnung', 'kundeId' => $kundeId, 'datum' => '2026-03-10', 'positionen' => $positionen,
        ]]))['rechnung'];
        $this->assertMatchesRegularExpression('/^RE-\d{4}-0001$/', $re['nummer']);
        $this->assertNotEmpty($re['id']);
        $this->assertSame(self::ADMIN_USER, $re['createdBy']);

        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
        $this->assertSame($re['nummer'], $list[0]['nummer']);
        $this->assertSame('Muster GmbH', $list[0]['kundeName']);
        $this->assertEquals($positionen, $list[0]['positionen']);
        $this->assertSame('offen', $list[0]['status']);

        $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['id' => $re['id'], 'typ' => 'rechnung', 'status' => 'bezahlt']]));
        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('bezahlt', $list[0]['status']);
        $this->assertSame($re['nummer'], $list[0]['nummer'], 'Nummer bleibt beim Ändern erhalten');

        $this->assertOk($this->api->post('delete_rechnung', ['id' => $re['id']]));
        $this->assertSame([], $this->assertOk($this->api->get('list_rechnungen'))['rechnungen']);
        $this->assertStatus(404, $this->api->post('delete_rechnung', ['id' => $re['id']]));
    }

    public function testRechnungsUndAngebotsnummernLaufenGetrennt(): void
    {
        $this->setupAdmin();

        $re1 = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung']]))['rechnung'];
        $an1 = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'angebot']]))['rechnung'];
        $re2 = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung']]))['rechnung'];

        $this->assertStringEndsWith('-0001', $re1['nummer']);
        $this->assertStringStartsWith('AN-', $an1['nummer']);
        $this->assertStringEndsWith('-0001', $an1['nummer']);
        $this->assertStringEndsWith('-0002', $re2['nummer']);
    }

    public function testUngueltigerTypWirdAbgelehnt(): void
    {
        $this->setupAdmin();

        $this->assertStatus(400, $this->api->post('save_rechnung', ['rechnung' => ['typ' => 'gutschrift']]));
    }

    public function testNormalerBenutzerDarfKeineRechnungenVerwalten(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung']]));
    }
}
