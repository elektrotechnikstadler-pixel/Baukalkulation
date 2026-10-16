<?php

declare(strict_types=1);

namespace Tests\Api;

/** Beleg-PDF aus Liste und Formular kommen serverseitig aus demselben Renderer. */
final class BelegPdfTest extends ApiTestCase
{
    public function testGespeicherteRechnungAlsPdf(): void
    {
        $this->setupAdmin();
        $re = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => [
            'typ' => 'rechnung', 'datum' => '2026-10-16',
            'positionen' => [['bezeichnung' => 'Montage', 'menge' => 2, 'einheit' => 'h', 'einzelpreis' => 58.0]],
        ]]))['rechnung'];

        $r = $this->api->get('render_beleg_pdf', ['rechnungId' => $re['id']]);
        $this->assertSame(200, $r->status, $r->describe());
        $this->assertStringContainsString('application/pdf', (string) $r->header('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->body);
    }

    public function testUngespeichertesFormularAlsPdf(): void
    {
        $this->setupAdmin();

        $r = $this->api->post('render_beleg_pdf', ['beleg' => [
            'typ' => 'angebot', 'datum' => '2026-10-16',
            'positionen' => [['bezeichnung' => 'Erdarbeiten', 'menge' => 4, 'einheit' => 'm³', 'einzelpreis' => 35.0]],
        ]]);
        $this->assertSame(200, $r->status, $r->describe());
        $this->assertStringStartsWith('%PDF', $r->body);
    }

    public function testFormularPdfNurMitBearbeitungsrecht(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('render_beleg_pdf', ['beleg' => ['typ' => 'rechnung', 'positionen' => []]]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testFormularTypMussZumGespeichertenBelegPassen(): void
    {
        $this->setupAdmin();
        $an = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'angebot']]))['rechnung'];

        $this->assertStatus(400, $this->api->post('render_beleg_pdf', ['beleg' => ['id' => $an['id'], 'typ' => 'rechnung']]));
    }

    public function testAngebotMailAkzeptiertAngebotId(): void
    {
        $this->setupAdmin();
        $an = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => [
            'typ' => 'angebot', 'positionen' => [['bezeichnung' => 'Montage', 'menge' => 1, 'einheit' => 'h', 'einzelpreis' => 58.0]],
        ]]))['rechnung'];

        // Ohne SMTP scheitert erst der Versand – die ID wurde also erkannt und das PDF erzeugt.
        $r = $this->api->post('send_angebot', ['angebotId' => $an['id'], 'to' => 'kunde@example.com']);
        $this->assertNotSame(400, $r->status, $r->describe());
        $this->assertNotSame(404, $r->status, $r->describe());
        $this->assertStringNotContainsString('PDF konnte nicht erzeugt werden', $r->body);
    }
}
