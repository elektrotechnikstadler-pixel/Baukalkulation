<?php

declare(strict_types=1);

namespace Tests\Api;

/**
 * Lexware Office – Bündel B2: Kundenfelder und Kunden-Abgleich (AP-20261126-lexoffice-schnittstelle).
 * Nur Aufzeichnungen unter tests/fixtures/lexoffice/fake/fake-ok/, nie die echte API (E-037).
 */
final class LexofficeKundenTest extends ApiTestCase
{
    private const MUSTER = '11111111-1111-4111-8111-111111111111';
    private const NEU    = '77777777-7777-4777-8777-777777777777';

    private function verbinden(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true, 'lexoffice_api_key' => 'fake-ok']));
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
    }

    private function kunde(array $daten): int
    {
        return (int) $this->assertOk($this->api->post('save_kunde', $daten))['kunde']['id'];
    }

    /** @return list<string> gesendete Anfragen „METHODE pfad“ */
    private function anfragen(): array
    {
        $datei = $this->server->dataPath('lexoffice-fake.jsonl');
        if (!is_file($datei)) {
            return [];
        }
        return array_map(static function (string $z): string {
            $a = json_decode($z, true);
            return $a['methode'] . ' ' . explode('?', $a['pfad'])[0];
        }, file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function testNeueKundenfelderWerdenGeprueftUndVonAltenClientsNichtGeleert(): void
    {
        $this->setupAdmin();
        $id = $this->kunde(['firma' => 'Bau AG', 'land' => 'at', 'ustId' => 'atu 123 456 78', 'leitwegId' => '991-12345-67']);
        $this->assertStatus(400, $this->api->post('save_kunde', ['firma' => 'X', 'land' => 'Österreich']));

        // Alter Client: Speichern ohne die neuen Felder
        $this->assertOk($this->api->post('save_kunde', ['id' => $id, 'firma' => 'Bau AG', 'ort' => 'Wien']));
        $k = array_values(array_filter($this->assertOk($this->api->get('load_kunden'))['kunden'], fn($k) => $k['id'] === $id))[0];
        $this->assertSame(['AT', 'ATU12345678', '991-12345-67', 'Wien'], [$k['land'], $k['ustId'], $k['leitwegId'], $k['ort']]);

        $ohneLand = $this->kunde(['nachname' => 'Ohne Land']);
        $this->assertSame('DE', array_values(array_filter($this->assertOk($this->api->get('load_kunden'))['kunden'], fn($k) => $k['id'] === $ohneLand))[0]['land']);
    }

    public function testOhneVerbindungstestKeinAbgleich(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true, 'lexoffice_api_key' => 'fake-ok']));
        $this->assertFalse($this->assertOk($this->api->module('lexoffice', 'kunden_status'))['verbunden']);
        $this->assertSame('nicht_geprueft', $this->assertStatus(409, $this->api->module('lexoffice', 'kunden_abgleich_vorschau'))['fehlerCode']);
    }

    public function testVorschauSchlaegtPassendeKontakteVor(): void
    {
        $this->verbinden();
        $muster = $this->kunde(['firma' => 'Muster GmbH', 'email' => 'info@muster.example']);
        $huber  = $this->kunde(['vorname' => 'Anna', 'nachname' => 'Huber']);
        $doppel = $this->kunde(['firma' => 'Doppel KG']);
        $neu    = $this->kunde(['firma' => 'Neu Firma']);

        $vorschau = array_column($this->assertOk($this->api->module('lexoffice', 'kunden_abgleich_vorschau'))['kunden'], 'vorschlag', 'kundeId');
        $this->assertSame(['treffer', self::MUSTER, 'email'], [$vorschau[$muster]['status'], $vorschau[$muster]['lexId'], $vorschau[$muster]['grund']]);
        $this->assertSame('treffer', $vorschau[$huber]['status']);
        $this->assertSame('mehrdeutig', $vorschau[$doppel]['status']);
        $this->assertSame('keiner', $vorschau[$neu]['status']);
    }

    public function testAbgleichVerknuepftLegtAnUndErzeugtKeineDubletten(): void
    {
        $this->verbinden();
        $muster = $this->kunde(['firma' => 'Muster GmbH']);
        $doppel = $this->kunde(['firma' => 'Doppel KG']);
        $neu    = $this->kunde(['firma' => 'Neu Firma', 'ort' => 'Augsburg']);

        $this->assertStatus(400, $this->api->module('lexoffice', 'kunden_abgleich_ausfuehren', ['zuordnungen' => array_fill(0, 11, ['kundeId' => $neu, 'aktion' => 'anlegen'])]));
        $ergebnisse = array_column($this->assertOk($this->api->module('lexoffice', 'kunden_abgleich_ausfuehren', ['zuordnungen' => [
            ['kundeId' => $muster, 'aktion' => 'verknuepfen', 'lexId' => self::MUSTER],
            ['kundeId' => $doppel, 'aktion' => 'anlegen'],
            ['kundeId' => $neu, 'aktion' => 'anlegen'],
            ['kundeId' => $neu, 'aktion' => 'verknuepfen', 'lexId' => '../../etc'],
        ]]))['ergebnisse'], null, 'kundeId');
        $this->assertTrue($ergebnisse[$muster]['ok']);
        $this->assertFalse($ergebnisse[$doppel]['ok'], 'mehrdeutig darf nicht anlegen');

        $status = $this->assertOk($this->api->module('lexoffice', 'kunden_status'))['kunden'];
        $this->assertSame(self::MUSTER, $status[$muster]['lexId']);
        $this->assertSame(self::NEU, $status[$neu]['lexId']);
        $this->assertArrayNotHasKey((string) $doppel, $status);

        // Erneut übertragen: vorhandene Verknüpfung, kein zweiter Kontakt
        $this->assertSame(self::NEU, $this->assertOk($this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $neu]))['lexId']);
        $this->assertSame(1, count(array_filter($this->anfragen(), fn($a) => $a === 'POST /v1/contacts')));

        // Lösen → wieder unverknüpft; beim Löschen des Kunden verschwindet die Verknüpfung
        $this->assertOk($this->api->module('lexoffice', 'kunde_verknuepfung_loesen', ['kundeId' => $muster]));
        $this->assertOk($this->api->post('delete_kunde', ['id' => $neu]));
        $this->assertSame([], (array) $this->assertOk($this->api->module('lexoffice', 'kunden_status'))['kunden']);
    }

    public function testZeitueberschreitungErzeugtKeineDubletteUndClaimSperrt(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true, 'lexoffice_api_key' => 'fake-503']));
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $muster = $this->kunde(['firma' => 'Muster GmbH']);
        $li     = $this->kunde(['nachname' => 'Li']);

        // POST bleibt ohne Antwort → Verknüpfung „fehler“, Kontakt könnte trotzdem entstanden sein
        $this->assertSame('nicht_erreichbar', $this->assertStatus(502, $this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $muster]))['fehlerCode']);
        $this->assertSame('fehler', $this->assertOk($this->api->module('lexoffice', 'kunden_status'))['kunden'][$muster]['status']);

        // Anderer Schlüssel, gleiches Konto: Suche findet den Kontakt → verknüpfen statt zweitem POST
        $this->assertOk($this->api->post('save_settings', ['lexoffice_api_key' => 'fake-ok']));
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $this->assertSame(self::MUSTER, $this->assertOk($this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $muster]))['lexId']);
        $this->assertSame(1, count(array_filter($this->anfragen(), fn($a) => $a === 'POST /v1/contacts')));

        // Manuelle Zuordnung auf einen anderen Kontakt wird abgelehnt
        $ergebnis = $this->assertOk($this->api->module('lexoffice', 'kunden_abgleich_ausfuehren', ['zuordnungen' => [
            ['kundeId' => $muster, 'aktion' => 'verknuepfen', 'lexId' => '22222222-2222-4222-8222-222222222222'],
        ]]))['ergebnisse'][0];
        $this->assertFalse($ergebnis['ok']);

        // Unklarer Vorversuch ohne Suchmerkmal (Name < 3 Zeichen, keine E-Mail) → nicht blind anlegen
        $db = $this->server->db();
        $db->prepare("INSERT INTO lexoffice_verknuepfung (objektTyp, objektId, organisationId, status, fehlerCode) VALUES ('kunde', ?, '00000000-0000-4000-8000-000000000001', 'fehler', 'nicht_erreichbar')")
            ->execute([(string) $li]);
        $this->assertSame('unklar', $this->assertStatus(409, $this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $li]))['fehlerCode']);

        // Laufender Claim sperrt, abgelaufener wird übernommen
        $db->prepare("UPDATE lexoffice_verknuepfung SET status = 'sendet', lexId = 'claim:fremd', geprueftAm = ? WHERE objektId = ?")
            ->execute([date('c'), (string) $li]);
        $this->assertSame('laeuft', $this->assertStatus(409, $this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $li]))['fehlerCode']);
        $this->assertSame('', $this->assertOk($this->api->module('lexoffice', 'kunden_status'))['kunden'][$li]['lexId'], 'Claim-Token nie ausliefern');
        $db->prepare("UPDATE lexoffice_verknuepfung SET geprueftAm = ? WHERE objektId = ?")
            ->execute([date('c', time() - 600), (string) $li]);
        $this->assertSame('unklar', $this->assertStatus(409, $this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $li]))['fehlerCode'], 'abgelaufener Claim wird übernommen');
        $this->assertSame(1, count(array_filter($this->anfragen(), fn($a) => $a === 'POST /v1/contacts')));
    }

    public function testFremdesKontoWirdNichtBeschrieben(): void
    {
        $this->verbinden();
        $muster = $this->kunde(['firma' => 'Muster GmbH']);
        $db = $this->server->db();
        $db->prepare("INSERT INTO lexoffice_verknuepfung (objektTyp, objektId, lexId, organisationId, status) VALUES ('kunde', ?, ?, 'fremd-org', 'uebertragen')")
            ->execute([(string) $muster, self::MUSTER]);
        $this->assertSame([], (array) $this->assertOk($this->api->module('lexoffice', 'kunden_status'))['kunden'], 'Verknüpfung aus fremder Sicherung');

        // Gespeichertes Profil passt nicht mehr zum Konto des Schlüssels → kein Schreiben
        $settings = json_decode((string) $db->query('SELECT data FROM settings WHERE id = 1')->fetchColumn(), true);
        $settings['lexoffice_profil']['organisationId'] = 'andere-org';
        $db->prepare('UPDATE settings SET data = ? WHERE id = 1')->execute([json_encode($settings)]);
        $this->assertSame('anderes_konto', $this->assertStatus(409, $this->api->module('lexoffice', 'kunde_uebertragen', ['kundeId' => $muster]))['fehlerCode']);
        $this->assertNotContains('POST /v1/contacts', $this->anfragen());
    }

    public function testRechte(): void
    {
        $this->verbinden();
        $normal = $this->createActiveUser('monteur', 'Monteur-Pass-123');
        $this->assertStatus(403, $normal->module('lexoffice', 'kunden_status'));
        $master = $this->createActiveUser('chef', 'Chef-Pass-12345', 'master');
        $this->assertOk($master->module('lexoffice', 'kunden_status'));
        $this->assertStatus(403, $master->module('lexoffice', 'kunden_abgleich_vorschau'));
        $this->assertStatus(403, $master->module('lexoffice', 'kunde_verknuepfung_loesen', ['kundeId' => 1]));
        $this->assertStatus(405, $this->api->get('module', ['module' => 'lexoffice', 'sub' => 'kunde_uebertragen']));

        // Lösen ist rein lokal und funktioniert auch ohne geprüfte Verbindung
        $this->assertOk($this->api->post('save_settings', ['lexoffice_api_key' => 'fake-401']));
        $this->assertOk($this->api->module('lexoffice', 'kunde_verknuepfung_loesen', ['kundeId' => 1]));
    }
}
