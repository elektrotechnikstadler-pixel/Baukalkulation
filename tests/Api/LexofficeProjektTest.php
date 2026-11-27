<?php

declare(strict_types=1);

namespace Tests\Api;

/**
 * Lexware Office – Bündel B4: Projekt übertragen (AP-20261126-lexoffice-schnittstelle, AK 9–11).
 * Nur Aufzeichnungen (fake-ok), nie die echte API (E-037). Belegbildung im Detail: Unit LexofficeBelegTest.
 */
final class LexofficeProjektTest extends ApiTestCase
{
    private const ANGEBOT  = 'a1a1a1a1-0000-4000-8000-000000000001';
    private const RECHNUNG = 'b2b2b2b2-0000-4000-8000-000000000002';

    private int $rev = 0;

    private function vorbereiten(bool $mitKunde = true, bool $lexwareModus = true, string $schluessel = 'fake-ok', bool $projekt13b = false): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lexoffice' => true, 'lexoffice_api_key' => $schluessel]));
        $this->assertOk($this->api->module('lexoffice', 'verbindung_testen'));
        $kundeId = $mitKunde ? (int) $this->assertOk($this->api->post('save_kunde', ['firma' => 'Muster GmbH', 'email' => 'info@muster.example']))['kunde']['id'] : null;
        $this->rev = (int) $this->assertOk($this->api->post('save', ['data' => [
            'baustellen'     => [$this->projekt($kundeId, []) + ($projekt13b ? ['reverseCharge13b' => true] : [])],
            'pauschalen'     => [['id' => 1, 'name' => 'Anfahrt', 'preis' => 45.5]],
            'stundenKatalog' => [['id' => 1, 'kategorie' => 'Geselle', 'preis' => 58, 'fixkosten' => 12.25]],
        ]]))['rev'];
        if ($lexwareModus) {
            $this->assertOk($this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));
        }
    }

    private function projekt(?int $kundeId, array $weitereArbeitszeit): array
    {
        return ['id' => 1, 'name' => 'Neubau Musterstraße', 'kundeId' => $kundeId,
            'material'    => [['id' => 1, 'bezeichnung' => 'NYM-J 3x1,5', 'anzahl' => 100, 'einheit' => 'm', 'ek' => 0.88, 'aufschlag' => 25]],
            'arbeitszeit' => array_merge([['id' => 1, 'beschreibung' => 'Montage', 'stunden' => 8, 'stundenpreis' => 58, 'stundenKatId' => 1, 'datum' => '2026-03-02']], $weitereArbeitszeit),
            'pauschalen'  => [['entryId' => 1, 'id' => 1, 'anzahl' => 2]],
        ];
    }

    private function vorschau(array $auswahl = []): array
    {
        return $this->assertOk($this->api->module('lexoffice', 'projekt_vorschau', ['baustelleId' => 1, 'auswahl' => $auswahl]));
    }

    /** @return list<array{methode:string,pfad:string,body:?string}> */
    private function anfragen(string $methode, string $pfadAnfang): array
    {
        $datei = $this->server->dataPath('lexoffice-fake.jsonl');
        $alle = is_file($datei) ? array_map(fn($z) => json_decode($z, true), file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        return array_values(array_filter($alle, fn($a) => $a['methode'] === $methode && str_starts_with($a['pfad'], $pfadAnfang)));
    }

    public function testNurInBetriebsartLexwareUndMitRechten(): void
    {
        $this->vorbereiten(true, false);
        $this->assertSame('modus', $this->assertStatus(409, $this->api->module('lexoffice', 'projekt_vorschau', ['baustelleId' => 1]))['fehlerCode']);
        $this->assertOk($this->api->module('lexoffice', 'modus_setzen', ['modus' => 'lexware']));

        $normal = $this->createActiveUser('monteur', 'Monteur-Pass-123');
        $this->assertStatus(403, $normal->module('lexoffice', 'projekt_vorschau', ['baustelleId' => 1]));
        $this->assertStatus(403, $normal->module('lexoffice', 'projekt_senden', ['baustelleId' => 1]));
        $this->assertSame('projekt_fehlt', $this->assertStatus(404, $this->api->module('lexoffice', 'projekt_vorschau', ['baustelleId' => 999]))['fehlerCode']);
        $this->assertStatus(405, $this->api->get('module', ['module' => 'lexoffice', 'sub' => 'projekt_senden', 'baustelleId' => 1]));
    }

    public function testAngebotWirdAlsEntwurfAngelegtUndRechnetNichtAb(): void
    {
        $this->vorbereiten();
        $vorschau = $this->vorschau();
        $this->assertEquals(['netto' => 665.0, 'steuer' => 126.35, 'brutto' => 791.35], $vorschau['summen']);
        $this->assertSame(['Muster GmbH', 3], [$vorschau['projekt']['kunde'], count($vorschau['positionen'])]);

        $ergebnis = $this->assertOk($this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'angebot']]));
        $this->assertSame(self::ANGEBOT, $ergebnis['lexId']);
        $this->assertStringEndsWith('/permalink/quotations/edit/' . self::ANGEBOT, $ergebnis['link']);

        $gesendet = $this->anfragen('POST', '/v1/quotations');
        $this->assertCount(1, $gesendet);
        $this->assertSame('/v1/quotations', $gesendet[0]['pfad'], 'nie finalize');
        $body = json_decode((string) $gesendet[0]['body'], true);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $body['address']['contactId'], 'vorhandener Kontakt per E-Mail verknüpft');
        $this->assertCount(0, $this->anfragen('POST', '/v1/contacts'));

        $belege = $this->assertOk($this->api->module('lexoffice', 'projekt_belege', ['baustelleId' => 1]))['belege'];
        $this->assertSame([['quotation', 'draft']], array_map(fn($b) => [$b['belegart'], $b['status']], $belege));
        $this->assertSame([false, false, false], array_column($this->vorschau()['positionen'], 'abgerechnet'), 'Angebot rechnet nichts ab');
    }

    public function testRechnungsentwurfRechnetAbUndNaechsterEntwurfEnthaeltNurNeues(): void
    {
        $this->vorbereiten();
        $this->assertSame(self::RECHNUNG, $this->assertOk($this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'rechnung']]))['lexId']);
        $body = json_decode((string) $this->anfragen('POST', '/v1/invoices')[0]['body'], true);
        $this->assertSame('service', $body['shippingConditions']['shippingType']);
        $this->assertStringStartsWith('2026-03-02T00:00:00.000', $body['shippingConditions']['shippingDate']);

        $danach = $this->vorschau(['belegart' => 'rechnung']);
        $this->assertSame([true, true, true], array_column($danach['positionen'], 'abgerechnet'));
        $this->assertSame(0.0, (float) $danach['summen']['netto']);
        $this->assertSame('keine_positionen', $this->assertStatus(400, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'rechnung']]))['fehlerCode']);

        // Neue Arbeitszeit kommt hinzu → nur sie ist offen; „alles erneut“ zeigt wieder alle
        $this->saveBaustellen([$this->projekt(1, [['id' => 2, 'beschreibung' => 'Nacharbeit', 'stunden' => 2, 'stundenpreis' => 58, 'stundenKatId' => 1, 'datum' => '2026-03-09']])], $this->rev);
        $this->assertSame(116.0, (float) $this->vorschau(['belegart' => 'rechnung'])['summen']['netto']);
        $this->assertSame(781.0, (float) $this->vorschau(['belegart' => 'rechnung', 'nurOffene' => false])['summen']['netto']);
    }

    public function testSperreUnklarerVersandUndUmschaltsperre(): void
    {
        $this->vorbereiten();
        $db = $this->server->db();
        $db->prepare("INSERT INTO lexoffice_verknuepfung (objektTyp, objektId, lexId, organisationId, status, geprueftAm) VALUES ('projekt', '1', 'sperre:alt', '00000000-0000-4000-8000-000000000001', 'sendet', ?)")
            ->execute([date('c')]);
        $this->assertSame('laeuft', $this->assertStatus(409, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1]))['fehlerCode']);
        $this->assertStatus(409, $this->api->module('lexoffice', 'modus_setzen', ['modus' => 'baukalkulation']));

        $db->exec("UPDATE lexoffice_verknuepfung SET status = 'fehler' WHERE objektTyp = 'projekt'");
        $this->assertSame('unklar_beleg', $this->assertStatus(409, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1]))['fehlerCode']);
        $this->assertCount(0, $this->anfragen('POST', '/v1/quotations'));
        $this->assertOk($this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'trotzdem' => true]));
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM lexoffice_verknuepfung WHERE objektTyp = 'projekt'")->fetchColumn(), 'Sperre nach Erfolg frei');
    }

    public function testBelegPostOhneAntwortSperrtUndAbgelehnterGibtFrei(): void
    {
        $this->vorbereiten(true, true, 'fake-beleg');
        $sperre = fn() => $this->server->db()->query("SELECT status FROM lexoffice_verknuepfung WHERE objektTyp = 'projekt'")->fetchColumn();

        // 503 beim Angebot: Beleg evtl. angelegt → Sperre bleibt „fehler“, kein stiller Neuversand
        $this->assertSame('nicht_erreichbar', $this->assertStatus(502, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'angebot']]))['fehlerCode']);
        $this->assertSame('fehler', $sperre());
        $this->assertStatus(409, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'angebot']]));
        $this->assertCount(1, $this->anfragen('POST', '/v1/quotations'));

        // 406 beim Rechnungsentwurf (bewusst „trotzdem“): eindeutig abgelehnt → Sperre frei, nichts vermerkt
        $json = $this->assertStatus(502, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'rechnung'], 'trotzdem' => true]));
        $this->assertSame('fehler', $json['fehlerCode']);
        $this->assertFalse($sperre());
        $this->assertSame(0, (int) $this->server->db()->query('SELECT COUNT(*) FROM lexoffice_abrechnung')->fetchColumn());
    }

    public function testOhnePreisrechtKeineUebertragungUndStornoGibtPositionenFrei(): void
    {
        $this->vorbereiten();
        $rechte = $this->assertOk($this->api->get('get_permissions'))['permissions'];
        $rechte['master']['canSeePrices'] = false;
        $this->assertOk($this->api->post('set_permissions', ['permissions' => $rechte]));
        $master = $this->createActiveUser('chef', 'Chef-Pass-12345', 'master');
        $this->assertStatus(403, $master->module('lexoffice', 'projekt_vorschau', ['baustelleId' => 1]));

        $this->assertOk($this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1, 'auswahl' => ['belegart' => 'rechnung']]));
        $this->server->db()->exec("UPDATE lexoffice_belege SET status = 'voided'");
        $this->assertSame([false, false, false], array_column($this->vorschau(['belegart' => 'rechnung'])['positionen'], 'abgerechnet'));
    }

    public function testParagraph13bAusDemProjekt(): void
    {
        $this->vorbereiten(true, true, 'fake-ok', true);
        $v = $this->vorschau();
        $this->assertTrue($v['auswahl']['reverseCharge13b']);
        $this->assertSame(['constructionService13b', 0.0], [$v['taxType'], (float) $v['summen']['steuer']]);
        $this->assertSame('net', $this->vorschau(['reverseCharge13b' => false])['taxType']);
    }

    public function testOhneKundeKeinVersand(): void
    {
        $this->vorbereiten(false);
        $this->assertNotEmpty($this->vorschau()['hinweise']);
        $this->assertSame('kein_kunde', $this->assertStatus(400, $this->api->module('lexoffice', 'projekt_senden', ['baustelleId' => 1]))['fehlerCode']);
    }

    public function testInformExportRechnetWieDieUebertragung(): void
    {
        $this->vorbereiten(true, false);
        $csv = $this->api->post('export_inform_csv', ['baustelleId' => 1]);
        $this->assertSame(200, $csv->status, $csv->describe());
        $zeilen = array_slice(explode("\r\n", trim(substr($csv->body, 3))), 1);
        $this->assertSame([
            '1;"";"NYM-J 3x1,5";100;m;1,1;110;Material',
            '2;"";"Montage";8;Std;58;464;Arbeitszeit',
            '3;"";"Anfahrt";2;psch;45,5;91;Pauschale',
        ], $zeilen);
    }
}
