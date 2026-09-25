<?php
declare(strict_types=1);

namespace Tests\Api;

final class KundenTest extends ApiTestCase
{
    public function testKundeAnlegenLadenAendernLoeschen(): void
    {
        $this->setupAdmin();

        $created = $this->assertOk($this->api->post('save_kunde', [
            'firma' => 'Muster GmbH', 'nachname' => 'Muster', 'ort' => 'Augsburg', 'email' => 'info@muster.de',
        ]));
        $id = $created['kunde']['id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame('000001', $created['kunde']['kundennummer']);

        $kunden = $this->assertOk($this->api->get('load_kunden'))['kunden'];
        $this->assertCount(1, $kunden);
        $this->assertSame('Muster GmbH', $kunden[0]['firma']);
        $this->assertSame('Augsburg', $kunden[0]['ort']);

        $updated = $this->assertOk($this->api->post('save_kunde', ['id' => $id, 'firma' => 'Muster GmbH', 'ort' => 'München']));
        $this->assertSame('000001', $updated['kunde']['kundennummer'], 'Kundennummer bleibt beim Ändern erhalten');
        $this->assertSame('München', $this->assertOk($this->api->get('load_kunden'))['kunden'][0]['ort']);

        $this->assertOk($this->api->post('delete_kunde', ['id' => $id]));
        $this->assertSame([], $this->assertOk($this->api->get('load_kunden'))['kunden']);
        $this->assertStatus(404, $this->api->post('delete_kunde', ['id' => $id]));
    }

    public function testKundennummernWerdenFortlaufendVergeben(): void
    {
        $this->setupAdmin();

        $a = $this->assertOk($this->api->post('save_kunde', ['firma' => 'A']))['kunde'];
        $b = $this->assertOk($this->api->post('save_kunde', ['firma' => 'B']))['kunde'];

        $this->assertSame('000001', $a['kundennummer']);
        $this->assertSame('000002', $b['kundennummer']);
        $this->assertNotSame($a['id'], $b['id']);
    }

    public function testKundeBrauchtFirmaOderNachname(): void
    {
        $this->setupAdmin();

        $this->assertStatus(400, $this->api->post('save_kunde', ['ort' => 'Irgendwo']));
    }

    public function testUnbekannteKundenIdBeimAendernLiefert404(): void
    {
        $this->setupAdmin();

        $this->assertStatus(404, $this->api->post('save_kunde', ['id' => 999, 'firma' => 'X']));
    }

    public function testSonderzeichenBleibenErhalten(): void
    {
        $this->setupAdmin();
        $firma = "Müller & Söhne <GbR> 'Ä€ß'";

        $this->assertOk($this->api->post('save_kunde', ['firma' => $firma]));

        $this->assertSame($firma, $this->assertOk($this->api->get('load_kunden'))['kunden'][0]['firma']);
    }
}
