<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiClient;

/**
 * B2a (AP-20261119-sammel-aenderungen, Rechte-Härtung): M1, M2 (nur api.php-Teil),
 * M3, M4, M10, M11, M13, M14 + F-B2-3a, F-B2-3b, F-B2-4.
 *
 * Je Maßnahme mindestens ein Negativ- (403 ohne Fix) und ein Positivtest
 * (berechtigter Nutzer kommt durch das neue Recht). E-Mail-Versand: SMTP ist
 * in der Testumgebung nicht konfiguriert – ein Status ungleich 403 genügt als
 * Nachweis, dass die Rechteprüfung passiert wurde (Versand selbst scheitert
 * danach ggf. an SMTP, das ist nicht Gegenstand dieser Tests).
 */
final class RechteHaertungTest extends ApiTestCase
{
    /** Setzt einzelne Permission-Keys für eine Rolle (Admin-Session $this->api). */
    private function grantPermission(string $role, array $overrides): void
    {
        $perms = $this->assertOk($this->api->get('get_permissions'))['permissions'];
        foreach ($overrides as $key => $val) {
            $perms[$role][$key] = $val;
        }
        $this->assertOk($this->api->post('set_permissions', ['permissions' => $perms]));
    }

    /** Schränkt die Sichtbarkeit eines Users ein und meldet ihn neu an (K2: sessionInvalidatedAt). */
    private function restrictVisibility(string $username, string $password, array $ids): ApiClient
    {
        $this->assertOk($this->api->post('set_visibility', ['username' => $username, 'visibleBaustellen' => $ids]));
        $client = $this->server->client();
        $this->assertOk($client->post('login', ['username' => $username, 'password' => $password]));
        return $client;
    }

    private function createBeleg(string $typ, ?int $baustelleId = null): array
    {
        $rechnung = ['typ' => $typ, 'positionen' => []];
        if ($baustelleId !== null) {
            $rechnung['baustelleId'] = $baustelleId;
        }
        return $this->assertOk($this->api->post('save_rechnung', ['rechnung' => $rechnung]))['rechnung'];
    }

    // ── M1: send_rechnung / send_angebot – Typrecht + canSeePrices (F-B2-3b) ──

    public function testM1NormalOhneBelegrechtKannRechnungNichtMailen(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $re = $this->createBeleg('rechnung');

        $this->assertStatus(403, $monteur->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']));
    }

    public function testM1MasterKannRechnungMailenVersuchen(): void
    {
        $this->setupAdmin();
        $re = $this->createBeleg('rechnung');

        $r = $this->api->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testM1NormalOhneBelegrechtKannAngebotNichtMailen(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $an = $this->createBeleg('angebot');

        $this->assertStatus(403, $monteur->post('send_angebot', ['angebotId' => $an['id'], 'to' => 'kunde@example.com']));
    }

    public function testM1SeeRechtOhnePreisrechtBleibt403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]); // canSeePrices bleibt false
        $re = $this->createBeleg('rechnung');

        $this->assertStatus(403, $monteur->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']));
    }

    public function testM1SeeUndPreisrechtKommtDurch(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $re = $this->createBeleg('rechnung');

        $r = $monteur->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── F-B2-3a: Belege nur für sichtbare Baustellen (Liste, PDF, Versand) ──

    public function testFB2_3aVersandAnUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2);

        $this->assertStatus(403, $monteur->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']));
    }

    public function testFB2_3aVersandAnSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 1);

        $r = $monteur->post('send_rechnung', ['rechnungId' => $re['id'], 'to' => 'kunde@example.com']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testFB2_3aPdfAnUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2);

        $this->assertStatus(403, $monteur->get('render_beleg_pdf', ['rechnungId' => $re['id']]));
    }

    public function testFB2_3aPdfAnSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 1);

        $r = $monteur->get('render_beleg_pdf', ['rechnungId' => $re['id']]);
        $this->assertNotSame(403, $r->status, $r->describe());
    }
    public function testFB2_3aListeFiltertUnsichtbareBaustelle(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $this->createBeleg('rechnung', 1);
        $this->createBeleg('rechnung', 2);

        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
        $this->assertSame(1, $list[0]['baustelleId']);
    }

    public function testFB2_3aListeMitSichtbarerBaustelleZeigtBeleg(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $this->createBeleg('rechnung', 1);

        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
        $this->assertSame(1, $list[0]['baustelleId']);
    }
    public function testFB2_3aBelegOhneBaustelleNurMitManageRechtSichtbar(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $this->createBeleg('rechnung'); // kein baustelleId

        // normal hat 'all'-Sichtbarkeit verloren und kein canManageRechnungen → Beleg ohne
        // Baustellenbezug bleibt unsichtbar (Entscheidung F-B2-3a: all ODER Manage-Recht).
        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(0, $list);
    }

    // ── B2a Korrekturrunde 1 (Review-Befunde): Positivfälle F-B2-3a ────────
    // (Manage-Recht, Sichtbarkeit 'all', Unterprojekt-Cascade), zuvor fehlend.

    public function testFB2_3aBelegOhneBaustelleMitManageRechtSichtbar(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $this->createBeleg('rechnung'); // kein baustelleId

        // Gegenprobe zu testFB2_3aBelegOhneBaustelleNurMitManageRechtSichtbar:
        // MIT canManageRechnungen bleibt der Beleg trotz eingeschränkter Sichtbarkeit sichtbar.
        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
    }

    public function testFB2_3aBelegOhneBaustelleMitSichtbarkeitAllSichtbar(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]);
        // Keine restrictVisibility() → monteur behält die Standard-Sichtbarkeit 'all'.
        $this->createBeleg('rechnung'); // kein baustelleId, kein Manage-Recht nötig

        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
    }

    public function testFB2_3aBelegAnUnterprojektEinesSichtbarenOberprojektsSichtbar(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 10, 'name' => 'Ober'],
            ['id' => 11, 'name' => 'Unter', 'parentId' => 10],
        ], $rev);
        // Nur das Oberprojekt wird explizit freigegeben; Cascade muss das Unterprojekt einschließen.
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [10]);
        $this->createBeleg('rechnung', 11);

        $list = $this->assertOk($monteur->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
        $this->assertSame(11, $list[0]['baustelleId']);
    }

    // ── B2a Korrekturrunde 1 (Major-Befund): Formularmodus-IDOR in render_beleg_pdf ──

    public function testFB2_3aRenderBelegPdfFormularModusMitFremderGespeicherterUndManipulierterBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2); // fremd/unsichtbar für monteur

        // IDOR-Versuch: gespeicherte fremde ID + manipulierte, eigene baustelleId im Formular
        // überlagert den gespeicherten Wert NICHT mehr vor der Sichtbarkeitsprüfung.
        $r = $monteur->post('render_beleg_pdf', ['beleg' => ['id' => $re['id'], 'typ' => 'rechnung', 'baustelleId' => 1]]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testFB2_3aRenderBelegPdfFormularModusMitSichtbarerBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 1);

        $r = $monteur->post('render_beleg_pdf', ['beleg' => ['id' => $re['id'], 'typ' => 'rechnung', 'baustelleId' => 1]]);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── B2a Korrekturrunde 1 (Major-Befund): generate_zugferd fehlte F-B2-3a ──

    public function testFB2_3aZugferdAnUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2);

        $this->assertStatus(403, $monteur->post('generate_zugferd', ['rechnungId' => $re['id']]));
    }

    public function testFB2_3aZugferdAnSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['firma_steuernr' => '12/345/67890']));
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeRechnungen' => true, 'canSeePrices' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 1);

        $r = $monteur->post('generate_zugferd', ['rechnungId' => $re['id']]);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── B2a Korrekturrunde 1 (Minor-Befund): save_rechnung/delete_rechnung ohne Sichtbarkeit ──

    public function testSaveRechnungAenderungAnFremderGespeicherterBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2); // admin legt Beleg an fremder Baustelle an

        $r = $monteur->post('save_rechnung', ['rechnung' => ['id' => $re['id'], 'typ' => 'rechnung', 'status' => 'bezahlt']]);
        $this->assertSame(403, $r->status, $r->describe());

        // Kein „Lesen über Speichern“-Leak, Beleg bleibt unverändert (Status weiterhin 'offen').
        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('offen', $list[0]['status']);
    }

    // ── B2a Korrekturrunde 2 (Test, E-094): Request-baustelleId einer
    // sichtbaren Baustelle darf die Prüfung der gespeicherten fremden
    // Baustelle nicht umgehen. ──

    public function testSaveRechnungMitSichtbarerRequestBaustelleAberFremderGespeicherterBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2); // admin legt Beleg an fremder Baustelle an

        // Request versucht, die gespeicherte fremde Baustelle (2) durch eine
        // sichtbare (1) zu überschreiben – die erste Prüfung (gespeichert vor
        // dem Merge) muss das eigenständig abfangen.
        $r = $monteur->post('save_rechnung', ['rechnung' => ['id' => $re['id'], 'typ' => 'rechnung', 'baustelleId' => 1, 'status' => 'bezahlt']]);
        $this->assertSame(403, $r->status, $r->describe());

        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('offen', $list[0]['status']);
        $this->assertSame(2, $list[0]['baustelleId']);
    }

    public function testSaveRechnungNeuAnFremderBaustelleAusRequestIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung', 'baustelleId' => 2]]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testSaveRechnungAnEigenerSichtbarerBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('save_rechnung', ['rechnung' => ['typ' => 'rechnung', 'baustelleId' => 1]]));
    }

    // ── B2a Korrekturrunde 2 (Minor-Befund, Typwechsel-IDOR): save_rechnung
    // prüft bei Update den gespeicherten Typ gegen das Typrecht und lehnt
    // einen Typwechsel ab. ──

    public function testSaveRechnungTypwechselVonAngebotZuRechnungIstAbgelehnt(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        // Nur Rechnungsrecht, kein canManageAngebote.
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $angebot = $this->createBeleg('angebot');

        $r = $monteur->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'rechnung']]);
        $this->assertSame(403, $r->status, $r->describe());

        // Angebot bleibt unverändert (Typ und Nummer).
        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('angebot', $list[0]['typ']);
        $this->assertSame($angebot['nummer'], $list[0]['nummer']);
    }

    public function testSaveRechnungTypwechselAnEigenemBelegMitBeidenRechtenIstDennochAbgelehnt(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        // Beide Verwaltungsrechte vorhanden – der Typwechsel bleibt trotzdem verboten.
        $this->grantPermission('normal', ['canManageRechnungen' => true, 'canManageAngebote' => true]);
        $angebot = $this->createBeleg('angebot');

        $r = $monteur->post('save_rechnung', ['rechnung' => ['id' => $angebot['id'], 'typ' => 'rechnung']]);
        $this->assertSame(409, $r->status, $r->describe());

        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('angebot', $list[0]['typ']);
    }

    public function testSaveRechnungUpdateOhneTypwechselKommtDurch(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $re = $this->createBeleg('rechnung');

        $this->assertOk($monteur->post('save_rechnung', ['rechnung' => ['id' => $re['id'], 'typ' => 'rechnung', 'status' => 'bezahlt']]));

        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertSame('bezahlt', $list[0]['status']);
    }

    public function testDeleteRechnungAnFremderBaustelleIst403UndBleibtInDbErhalten(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 2);

        $r = $monteur->post('delete_rechnung', ['id' => $re['id']]);
        $this->assertSame(403, $r->status, $r->describe());

        // Tatsächlicher Nachweis: Beleg bleibt in der DB erhalten (Admin mit 'all'-Sicht sieht ihn weiter).
        $list = $this->assertOk($this->api->get('list_rechnungen'))['rechnungen'];
        $this->assertCount(1, $list);
        $this->assertSame($re['id'], $list[0]['id']);
    }

    public function testDeleteRechnungAnEigenerSichtbarerBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);
        $re = $this->createBeleg('rechnung', 1);

        $this->assertOk($monteur->post('delete_rechnung', ['id' => $re['id']]));
    }

    // ── M2: send_vde / send_din1090 – Leserecht ────────────────────────────

    public function testM2SendVdeOhneLeserechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canReadVde0100' => false]);

        $r = $monteur->post('send_vde', ['protokollId' => 1, 'to' => 'kunde@example.com']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM2SendVdeMitLeserechtKommtDurch(): void
    {
        $this->setupAdmin();
        // 'normal' hat canReadVde0100 per Default (true).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('send_vde', ['protokollId' => 1, 'to' => 'kunde@example.com']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testM2SendDin1090OhneLeserechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canReadDin1090' => false]);

        $r = $monteur->post('send_din1090', ['pdf_base64' => base64_encode(str_repeat('x', 200)), 'to' => 'kunde@example.com']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM2SendDin1090MitLeserechtKommtDurch(): void
    {
        $this->setupAdmin();
        // 'normal' hat canReadDin1090 per Default (true); F-B2-5: Schreibrecht nicht nötig.
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('send_din1090', ['pdf_base64' => base64_encode(str_repeat('x', 200)), 'to' => 'kunde@example.com']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── M3: ai_scan_material – Material- oder Arbeitszeitrecht + POST-Pflicht ──

    public function testM3OhneRechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('ai_scan_material', ['mimeType' => 'image/jpeg', 'data' => base64_encode('x')]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM3AnonymIst401VorRechtepruefung(): void
    {
        $this->setupAdmin();

        $this->assertStatus(401, $this->server->client()->get('ai_scan_material'));
    }

    public function testM3OhneRechtBeiGetWeiterhin403Vor405(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->get('ai_scan_material'));
    }
    public function testM3MitArbeitszeitrechtKeine403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canEditArbeitszeit' => true]);

        $r = $monteur->post('ai_scan_material', ['mimeType' => 'image/jpeg', 'data' => base64_encode('x')]);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testM3MitMaterialrechtKeine403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canEditMaterial' => true]);

        $r = $monteur->post('ai_scan_material', ['mimeType' => 'image/jpeg', 'data' => base64_encode('x')]);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testM3GetMitRechtIst405(): void
    {
        $this->setupAdmin();

        $r = $this->api->get('ai_scan_material');
        $this->assertSame(405, $r->status, $r->describe());
    }

    // ── M4: Lager foto_ai – Schreibrecht ────────────────────────────────────

    public function testM4FotoAiOhneSchreibrechtIst403(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lager' => true]));
        // 'normal' hat per Default canReadLager=true, canWriteLager=false.
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('module', [], ['module' => 'lager', 'sub' => 'foto_ai']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM4FotoAiMitSchreibrechtKommtDurch(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lager' => true]));
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canWriteLager' => true]);

        $r = $monteur->post('module', [], ['module' => 'lager', 'sub' => 'foto_ai']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    public function testM4FotoAiAvailableBleibtBeimLeserecht(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['modul_lager' => true]));
        // Keine Verschärfung für foto_ai_available (liefert nur ein bool).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $r = $monteur->post('module', [], ['module' => 'lager', 'sub' => 'foto_ai_available']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── M10: Baustellen-Sichtbarkeit (8 Aktionen, Testplan-Stichprobe) ──────

    public function testM10SaveTagesberichtUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_tagesbericht', ['baustelleId' => 2, 'htmlReport' => '<p>x</p>']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10SaveTagesberichtSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('save_tagesbericht', ['baustelleId' => 1, 'htmlReport' => '<p>x</p>']));
    }

    public function testM10SaveSubSortOrderUnsichtbaresOberprojektIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 10, 'name' => 'Ober'],
            ['id' => 11, 'name' => 'Unter', 'parentId' => 10],
            ['id' => 1, 'name' => 'Andere'],
        ], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_sub_sort_order', ['parentId' => 10, 'order' => [11]]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10SaveSubSortOrderSichtbaresOberprojektKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 10, 'name' => 'Ober'],
            ['id' => 11, 'name' => 'Unter', 'parentId' => 10],
        ], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [10]);

        $this->assertOk($monteur->post('save_sub_sort_order', ['parentId' => 10, 'order' => [11]]));
    }

    public function testM10SaveSchnellnotizUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_schnellnotiz', ['text' => 'Testnotiz', 'baustelleId' => 2]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10SaveSchnellnotizSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('save_schnellnotiz', ['text' => 'Testnotiz', 'baustelleId' => 1]));
    }

    public function testM10LoadSchnellnotizenUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertStatus(403, $monteur->get('load_schnellnotizen', ['baustelleId' => 2]));
    }

    public function testM10LoadSchnellnotizenSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->assertOk($this->api->post('save_schnellnotiz', ['text' => 'Adminnotiz', 'baustelleId' => 1]));
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $notes = $this->assertOk($monteur->get('load_schnellnotizen', ['baustelleId' => 1]))['notes'];
        $this->assertCount(1, $notes);
        $this->assertSame(1, $notes[0]['baustelleId']);
    }

    // ── B2a Korrekturrunde 1 (Minor-Befund): update_schnellnotiz ohne Sichtbarkeit ──

    public function testUpdateSchnellnotizUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $note = $this->assertOk($this->api->post('save_schnellnotiz', ['text' => 'Adminnotiz', 'baustelleId' => 2]))['note'];
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('update_schnellnotiz', ['id' => $note['id'], 'text' => 'Geaendert']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testUpdateSchnellnotizSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $note = $this->assertOk($this->api->post('save_schnellnotiz', ['text' => 'Adminnotiz', 'baustelleId' => 1]))['note'];
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('update_schnellnotiz', ['id' => $note['id'], 'text' => 'Geaendert']));
    }

    // ── B2a Korrekturrunde 2 (Minor-Befund): kannSchnellnotizSehen() darf die
    // übrigen Freigaben (Ersteller/Zuweisung) nicht durch die neue
    // Baustellen-Prüfung ersetzen. ──

    public function testUpdateSchnellnotizZugewiesenAnUnsichtbarerBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        // Notiz an unsichtbarer Baustelle (2), aber an den Monteur zugewiesen.
        $note = $this->assertOk($this->api->post('save_schnellnotiz', [
            'text' => 'Adminnotiz', 'baustelleId' => 2, 'zugewiesen_an' => 'monteur',
        ]))['note'];
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('update_schnellnotiz', ['id' => $note['id'], 'text' => 'Geaendert']));
    }

    public function testM10SaveTagebuchExportUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_tagebuch_export', ['html' => '<p>x</p>'], ['baustelleId' => 2]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10SaveTagebuchExportSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('save_tagebuch_export', ['html' => '<p>x</p>'], ['baustelleId' => 1]));
    }
    public function testM10SaveTerminUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageTermine' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('save_termin', ['titel' => 'Termin', 'datum' => '2026-05-01', 'baustelleId' => 2]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10SaveTerminSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canManageTermine' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('save_termin', ['titel' => 'Termin', 'datum' => '2026-05-01', 'baustelleId' => 1]));
    }
    public function testM10MarkOrderedUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canOrderMaterial' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued', 'fehlendesMaterial' => [['id' => 'm1', 'status' => 'offen']]]], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('mark_ordered', ['baustelleId' => 2, 'materialIds' => ['m1']]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10MarkOrderedSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canOrderMaterial' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord', 'fehlendesMaterial' => [['id' => 'm1', 'status' => 'offen']]]], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('mark_ordered', ['baustelleId' => 1, 'materialIds' => ['m1']]));
    }
    public function testM10ToggleAuswertungFlagUnsichtbareBaustelleIst403(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeAuswertung' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord'], ['id' => 2, 'name' => 'Sued']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $r = $monteur->post('toggle_auswertung_flag', ['baustelleId' => 2, 'include' => false]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM10ToggleAuswertungFlagSichtbareBaustelleKommtDurch(): void
    {
        $this->setupAdmin();
        $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canSeeAuswertung' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $monteur = $this->restrictVisibility('monteur', 'Monteur-Pass-1', [1]);

        $this->assertOk($monteur->post('toggle_auswertung_flag', ['baustelleId' => 1, 'include' => false]));
    }
    // ── M11 / F-B2-4: Dateien – Ordner-Schreibrecht ────────────────────────

    private function createTestFile(ApiClient $client, int $baustelleId, string $category, string $fileName): void
    {
        $this->assertOk($client->post('save_generated_file', [
            'baustelleId' => $baustelleId,
            'category'    => $category,
            'fileName'    => $fileName,
            'fileData'    => base64_encode('Testinhalt'),
        ]));
    }

    public function testM11DeleteFileOhneKategorieVerlangtCanWriteDateien(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Dateien', 'altablage.txt');
        $this->grantPermission('normal', ['canWriteDateien' => false]);

        $r = $monteur->post('delete_file', ['baustelleId' => 1, 'fileName' => 'altablage.txt']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11DeleteFileOhneKategorieMitRechtLoescht(): void
    {
        $this->setupAdmin();
        // 'normal' hat canWriteDateien per Default (true).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Dateien', 'altablage.txt');

        $this->assertOk($monteur->post('delete_file', ['baustelleId' => 1, 'fileName' => 'altablage.txt']));
    }

    public function testM11RenameFileOhneSchreibrechtIst403(): void
    {
        $this->setupAdmin();
        // 'normal' hat canWriteEingangsbelege per Default (false).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Eingangsbelege', 'beleg.pdf');

        $r = $monteur->post('rename_file', ['baustelleId' => 1, 'fileName' => 'beleg.pdf', 'category' => 'Eingangsbelege', 'alias' => 'Neuer Name']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11RenameFileMitSchreibrechtKommtDurch(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $this->grantPermission('normal', ['canWriteEingangsbelege' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Eingangsbelege', 'beleg.pdf');

        $this->assertOk($monteur->post('rename_file', ['baustelleId' => 1, 'fileName' => 'beleg.pdf', 'category' => 'Eingangsbelege', 'alias' => 'Neuer Name']));
    }

    public function testM11MoveFileOhneQuellrechtIst403(): void
    {
        $this->setupAdmin();
        // Quelle Eingangsbelege (kein Schreibrecht), Ziel Dateien (Schreibrecht vorhanden).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Eingangsbelege', 'beleg.pdf');

        $r = $monteur->post('move_file', ['baustelleId' => 1, 'fileName' => 'beleg.pdf', 'fromCategory' => 'Eingangsbelege', 'toCategory' => 'Dateien']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11MoveFileOhneZielrechtIst403(): void
    {
        $this->setupAdmin();
        // Quelle Dateien (Schreibrecht vorhanden), Ziel Eingangsbelege (kein Recht).
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Dateien', 'datei.txt');

        $r = $monteur->post('move_file', ['baustelleId' => 1, 'fileName' => 'datei.txt', 'fromCategory' => 'Dateien', 'toCategory' => 'Eingangsbelege']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11MoveFileMitBeidenRechtenKommtDurch(): void
    {
        $this->setupAdmin();
        // Dateien und Fotos sind für 'normal' per Default beide schreibbar.
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Dateien', 'datei.txt');

        // Hinweis: Der realpath()-Guard in moveFile() vergleicht Pfade mit
        // angehängtem '/', was auf Windows wegen Backslash-Trennzeichen scheitert
        // (vorbestehender, von B2a unabhängiger Befund – nicht Teil dieses APs,
        // daher kein assertOk auf den tatsächlichen Datei-Erfolg, nur auf „kein 403").
        $r = $monteur->post('move_file', ['baustelleId' => 1, 'fileName' => 'datei.txt', 'fromCategory' => 'Dateien', 'toCategory' => 'Fotos']);
        $this->assertNotSame(403, $r->status, $r->describe());
    }

    // ── B2a Korrekturrunde 1 (Leitstand-Präzisierung F-B2-4): Belegrecht
    // darf move_file NICHT als Ersatz für das Ordner-Schreibrecht dienen. ──

    public function testM11MoveFileNurMitBelegrechtOhneOrdnerrechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        // Belegrecht vorhanden, aber kein canWriteAusgangsbelege – darf move_file
        // (anders als save_generated_file) nicht ersetzen.
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Dateien', 'datei.txt');

        $r = $monteur->post('move_file', ['baustelleId' => 1, 'fileName' => 'datei.txt', 'fromCategory' => 'Dateien', 'toCategory' => 'Ausgangsbelege']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11RenameFileNurMitBelegrechtOhneOrdnerrechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        // Belegrecht vorhanden, aber kein canWriteAusgangsbelege – darf rename_file
        // (anders als save_generated_file) nicht ersetzen.
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);
        $this->createTestFile($this->api, 1, 'Ausgangsbelege', 'rechnung.pdf');

        $r = $monteur->post('rename_file', ['baustelleId' => 1, 'fileName' => 'rechnung.pdf', 'category' => 'Ausgangsbelege', 'alias' => 'Neuer Name']);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11SaveGeneratedFileOhneOrdnerrechtIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);

        $r = $monteur->post('save_generated_file', ['baustelleId' => 1, 'category' => 'Eingangsbelege', 'fileName' => 'x.pdf', 'fileData' => base64_encode('x')]);
        $this->assertSame(403, $r->status, $r->describe());
    }

    public function testM11SaveGeneratedFileBelegrechtErsatzFuerAusgangsbelege(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        // Kein canWriteAusgangsbelege, aber Belegrecht als Ersatz (F-B2-4).
        $this->grantPermission('normal', ['canManageRechnungen' => true]);
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);

        $this->assertOk($monteur->post('save_generated_file', ['baustelleId' => 1, 'category' => 'Ausgangsbelege', 'fileName' => 're.pdf', 'fileData' => base64_encode('x')]));
    }

    public function testM11SaveGeneratedFileMitOrdnerrechtKommtDurch(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');
        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([['id' => 1, 'name' => 'Nord']], $rev);

        $this->assertOk($monteur->post('save_generated_file', ['baustelleId' => 1, 'category' => 'Dateien', 'fileName' => 'd.txt', 'fileData' => base64_encode('x')]));
    }

    // ── M13: get_permissions nur Admin ───────────────────────────────────

    public function testM13GetPermissionsNormalIst403(): void
    {
        $this->setupAdmin();
        $monteur = $this->createActiveUser('monteur', 'Monteur-Pass-1');

        $this->assertStatus(403, $monteur->get('get_permissions'));
    }

    public function testM13GetPermissionsMasterIst403(): void
    {
        $this->setupAdmin();
        $master = $this->createActiveUser('master1', 'Master-Pass-1', 'master');

        $this->assertStatus(403, $master->get('get_permissions'));
    }

    public function testM13GetPermissionsAdminKommtDurch(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->get('get_permissions'));
    }

    // ── M14: Admin-Rechteliste vollständig (aus getDefaultPermissions abgeleitet) ──

    public function testM14AdminPermsEnthaeltDokumentenordnerUndDin1090(): void
    {
        $this->setupAdmin();

        $r = $this->api->get('check');
        $this->assertSame(200, $r->status, $r->describe());
        $perms = $r->json()['permissions'];
        $this->assertTrue($perms['canReadDin1090'] ?? false, 'canReadDin1090 fehlt in der Admin-Liste');
        $this->assertTrue($perms['canWriteDin1090'] ?? false, 'canWriteDin1090 fehlt in der Admin-Liste');
        $this->assertTrue($perms['canWriteEingangsbelege'] ?? false, 'canWriteEingangsbelege fehlt in der Admin-Liste');
        $this->assertTrue($perms['canReadFotos'] ?? false, 'canReadFotos fehlt in der Admin-Liste');
        $this->assertTrue($perms['canWriteSonstiges'] ?? false, 'canWriteSonstiges fehlt in der Admin-Liste');
    }
}
