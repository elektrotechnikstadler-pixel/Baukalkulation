<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiResponse;

/**
 * Lexware Office – Bündel B1 (AP-20261126-lexoffice-schnittstelle, AK 1–3, 9 und Ein-/Ausschalten aus AK 7).
 * Die echte API wird nie aufgerufen: TestServer setzt BK_LEXOFFICE_FAKE_DIR, der API-Schlüssel
 * wählt die Aufzeichnung unter tests/fixtures/lexoffice/fake/<schlüssel>/.
 */
final class LexofficeActionsTest extends ApiTestCase
{
    private function aktivieren(string $schluessel = 'fake-ok'): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true, 'lexoffice_api_key' => $schluessel]));
    }

    private function neueRechnung(): ApiResponse
    {
        return $this->api->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung', 'status' => 'offen', 'positionen' => []]]);
    }

    public function testModulAusSperrtAlleAktionen(): void
    {
        $this->setupAdmin();
        $this->assertStatus(403, $this->api->module('lexoffice', 'status'));
        $this->assertStatus(403, $this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertStatus(403, $this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));
    }

    public function testSchluesselWirdVerschluesseltGespeichertUndNieAusgeliefert(): void
    {
        $this->aktivieren('fake-ok');
        $settings = $this->assertOk($this->api->get('load_settings'))['settings'];
        $this->assertTrue($settings['lexoffice_api_key_gesetzt']);
        $this->assertSame('', $settings['lexoffice_api_key']);
        $roh = json_decode((string) $this->server->db()->query('SELECT data FROM settings WHERE id = 1')->fetchColumn(), true);
        $this->assertStringStartsWith('enc:v1:', $roh['lexoffice_api_key']);
    }

    public function testVerbindungTestenLiefertProfilUndSpeichertEs(): void
    {
        $this->aktivieren('fake-ok');
        $json = $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertSame('Musterbau Test GmbH', $json['profil']['firma']);
        $this->assertSame('net', $json['profil']['steuerart']);
        $this->assertSame([], $json['hinweise']);

        $status = $this->assertOk($this->api->module('lexoffice', 'status'));
        $this->assertTrue($status['schluesselGesetzt']);
        $this->assertSame('00000000-0000-4000-8000-000000000001', $status['profil']['organisationId']);
    }

    public function testFehlerDerApiWerdenOhneDetailsGemeldet(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true]));
        $this->assertSame('schluessel_fehlt', $this->assertStatus(400, $this->api->module('lexoffice', 'verbindung_testen'))['fehlerCode']);

        foreach (['fake-401' => 'schluessel_ungueltig', 'fake-429' => 'rate_limit', 'fake-unbekannt' => 'fehler'] as $schluessel => $code) {
            $this->assertOk($this->api->post('save_settings', ['lexoffice_api_key' => $schluessel]));
            $json = $this->assertStatus(502, $this->api->module('lexoffice', 'verbindung_testen'));
            $this->assertSame($code, $json['fehlerCode'], $schluessel);
            $this->assertStringNotContainsString('HTTP', $json['error']);
        }
    }

    public function testAenderndeAktionenNurAdminUndPost(): void
    {
        $this->aktivieren('fake-ok');
        $this->assertStatus(405, $this->api->get('module', ['module' => 'lexoffice', 'sub' => 'verbindung_testen']));

        $normal = $this->createActiveUser('monteur', 'Monteur-Pass-123');
        $this->assertStatus(403, $normal->module('lexoffice', 'status'));
        $this->assertStatus(403, $normal->module('lexoffice', 'modus_setzen', ['modus' => 'baukalkulation']));
    }

    public function testSchluesselwechselVerwirftDieAlteVerbindungspruefung(): void
    {
        $this->aktivieren('fake-ok');
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertOk($this->api->post('save_settings', ['lexoffice_api_key' => 'fake-401']));

        $this->assertSame([], $this->assertOk($this->api->module('lexoffice', 'status'))['profil']);
        $this->assertStatus(409, $this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));
    }

    public function testArchivLaesstNurAdminsDenStatusAendern(): void
    {
        $this->aktivieren('fake-ok');
        $angebot = $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['typ' => 'angebot', 'status' => 'offen', 'positionen' => []]]))['rechnung'];
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertOk($this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));

        $this->assertStatus(403, $this->api->post('send_angebot', ['id' => $angebot['id']]));
        $this->assertOk($this->api->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'angebot', 'status' => 'angenommen']]));
        // Alter Client mit vollem Formular, Typwechsel, ungültiger Status
        $this->assertStatus(409, $this->api->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'angebot', 'status' => 'offen', 'positionen' => []]]));
        $this->assertStatus(409, $this->api->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'rechnung', 'status' => 'bezahlt']]));
        $this->assertStatus(400, $this->api->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'angebot', 'status' => 'bezahlt']]));

        $master = $this->createActiveUser('chef', 'Chef-Pass-12345', 'master');
        $this->assertStatus(403, $master->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'angebot', 'status' => 'abgelehnt']]));
    }

    public function testBetriebsartLexwareSperrtNeueBelegeUndLaesstNurStatusZu(): void
    {
        $this->aktivieren('fake-ok');
        $alt = $this->assertOk($this->neueRechnung())['rechnung'];

        $this->assertStatus(409, $this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertStatus(400, $this->api->module('lexoffice', 'modus_setzen', ['modus' => 'irgendwas']));
        $this->assertFalse($this->assertOk($this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']))['rechnungenAktiv']);

        $this->assertStatus(403, $this->neueRechnung());
        $this->assertStatus(403, $this->api->post('delete_rechnung', ['id' => $alt['id']]));
        $this->assertStatus(403, $this->api->post('send_rechnung', ['id' => $alt['id']]));

        $this->assertOk($this->api->post('save_rechnung', ['rechnung' => [
            'id' => $alt['id'], 'typ' => 'rechnung', 'status' => 'bezahlt',
        ]]));
        $stmt = $this->server->db()->prepare('SELECT status, notizen FROM rechnungen WHERE id = ?');
        $stmt->execute([$alt['id']]);
        $this->assertSame(['status' => 'bezahlt', 'notizen' => ''], $stmt->fetch(\PDO::FETCH_ASSOC));

        // save_settings darf die Betriebsart nicht umgehen; Modul aus gibt die Rechnungen wieder frei.
        $this->assertOk($this->api->post('save_settings', ['rechnung_erzeugung' => 'baukalkulation']));
        $this->assertStatus(403, $this->neueRechnung());
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => false]));
        $this->assertOk($this->neueRechnung());

        // Wiedereinschalten: Betriebsart und Profil bleiben erhalten, Rückweg über modus_setzen.
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true]));
        $this->assertStatus(403, $this->neueRechnung());
        $this->assertTrue($this->assertOk($this->api->module('lexoffice', 'modus_setzen', ['modus' => 'baukalkulation']))['rechnungenAktiv']);
        $this->assertOk($this->neueRechnung());
    }
}
