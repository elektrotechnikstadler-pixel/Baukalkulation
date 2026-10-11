<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiClient;

/**
 * Abgleich Zeiterfassung ↔ Projektbuchung (AP-20261011-projektbuchung-abgleich).
 *
 * R1: Änderungen im Projekt ändern nie die Zeiterfassung.
 * R2: Nur das Verschieben in ein anderes Projekt ändert den Projektbezug der Zeiterfassung.
 */
final class ZeiterfassungProjektbuchungTest extends ApiTestCase
{
    private ApiClient $monteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_settings', ['auto_import_stunden' => true]));
        $this->assertOk($this->api->post('add_user', [
            'username' => 'monteur',
            'password' => 'Start-Passwort-1',
            'role'     => 'normal',
            'kuerzel'  => 'MM',
        ]));
        $this->monteur = $this->server->client();
        $this->assertOk($this->monteur->post('login', ['username' => 'monteur', 'password' => 'Start-Passwort-1']));
        $this->assertOk($this->monteur->post('change_password', ['old' => 'Start-Passwort-1', 'new' => 'Monteur-Pass-1']));
        $this->assertOk($this->api->post('save', ['data' => [
            'baustellen' => [
                ['id' => 1, 'name' => 'Neubau', 'arbeitszeit' => []],
                ['id' => 2, 'name' => 'Umbau', 'arbeitszeit' => []],
            ],
            'stundenKatalog' => [
                ['id' => 10, 'kategorie' => 'Monteur', 'preis' => 50, 'fixkosten' => 0],
                ['id' => 11, 'kategorie' => 'Meister', 'preis' => 70, 'fixkosten' => 5],
            ],
        ]]));
    }

    private function eintrag(int $id, string $datum, float $stunden, int $baustelleId = 1, array $extra = []): array
    {
        return $extra + [
            'id'           => $id,
            'clientUuid'   => 'uuid-' . $id,
            'datum'        => $datum,
            'typ'          => 'arbeit',
            'baustelleId'  => $baustelleId,
            'stunden'      => $stunden,
            'stundenKatId' => 10,
            'von'          => '07:00',
            'bis'          => sprintf('%02d:00', 7 + (int) $stunden),
            'pause'        => 0,
            'bemerkung'    => '',
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function speichern(array $entries): array
    {
        $rev = $this->assertOk($this->monteur->get('load_zeiterfassung'))['zeitRev'];
        return $this->assertOk($this->monteur->post('save_zeiterfassung', [
            'entries'     => $entries,
            'baseZeitRev' => $rev,
        ]));
    }

    /** @return array<int, array<string, mixed>> Baustellen nach ID */
    private function baustellen(): array
    {
        return array_column($this->ladeDaten()['baustellen'], null, 'id');
    }

    /** @return array<string, mixed> */
    private function ladeDaten(): array
    {
        return $this->assertOk($this->api->get('load'))['data'];
    }

    /** @return list<array<string, mixed>> */
    private function buchungen(int $baustelleId): array
    {
        return array_values(array_filter(
            $this->baustellen()[$baustelleId]['arbeitszeit'] ?? [],
            static fn(array $a): bool => !empty($a['autoImport']),
        ));
    }

    /** @return array<int, array<string, mixed>> Zeiteinträge des Monteurs nach entryId */
    private function zeiteintraege(): array
    {
        return array_column($this->assertOk($this->monteur->get('load_zeiterfassung'))['entries'], null, 'id');
    }

    /** Ändert eine Projektbuchung von Hand wie die Projektansicht (Voll-Save der Baustellen). */
    private function bucheImProjektUm(int $baustelleId, callable $aenderung): void
    {
        $daten = $this->ladeDaten();
        $baustellen = $daten['baustellen'];
        foreach ($baustellen as &$b) {
            if ((int) $b['id'] !== $baustelleId) {
                continue;
            }
            foreach ($b['arbeitszeit'] as &$a) {
                if (!empty($a['autoImport'])) {
                    $a = $aenderung($a);
                }
            }
        }
        unset($b, $a);
        $this->saveBaustellen($baustellen, (int) $daten['rev']);
    }

    public function testAdminNachtragBuchtSofortInsProjekt(): void
    {
        $result = $this->assertOk($this->api->post('admin_add_zeiterfassung', [
            'username'    => 'monteur',
            'datum'       => '2026-03-02',
            'typ'         => 'arbeit',
            'stunden'     => 8,
            'baustelleId' => 1,
        ]));

        $buchungen = $this->buchungen(1);
        self::assertCount(1, $buchungen);
        self::assertSame($result['inserted'][0]['entryId'], $buchungen[0]['zeitEntryId']);
        self::assertEquals(8, $buchungen[0]['stunden']);
        self::assertSame(0, $this->assertOk($this->api->get('reconcile_bookings'))['counts']['orphans']);
    }

    public function testGeaenderteStundenUndDatumWerdenNachgezogen(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);

        $saved = $this->speichern([$this->eintrag(101, '2026-03-03', 4)]);

        self::assertSame(1, $saved['autoUpdated']);
        $buchungen = $this->buchungen(1);
        self::assertCount(1, $buchungen);
        self::assertEquals(4, $buchungen[0]['stunden']);
        self::assertSame('2026-03-03', $buchungen[0]['datum']);
        self::assertEquals(4, $buchungen[0]['zeitStunden']);
        self::assertSame('2026-03-03', $buchungen[0]['zeitDatum']);
    }

    public function testGeaenderteKategorieZiehtKategorieUndPreisNach(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);

        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1, ['stundenKatId' => 11])]);

        $buchung = $this->buchungen(1)[0];
        self::assertSame(11, $buchung['stundenKatId']);
        self::assertEquals(70, $buchung['stundenpreis']);
        self::assertEquals(5, $buchung['fixkosten']);
    }

    public function testHandaenderungImProjektBleibtUndAendertZeiterfassungNicht(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $this->bucheImProjektUm(1, static fn(array $a): array => ['stunden' => 6] + $a);

        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1, ['bemerkung' => 'erneut gespeichert'])]);

        self::assertEquals(8, $this->zeiteintraege()[101]['stunden']);
        $buchung = $this->buchungen(1)[0];
        self::assertEquals(6, $buchung['stunden']);
        self::assertEquals(8, $buchung['zeitStunden']);
        self::assertSame('ZE013', $this->zeiteintraege()[101]['errorCode']);
        self::assertSame(1, $this->assertOk($this->api->get('reconcile_bookings'))['counts']['abweichungen']);
    }

    public function testHandgeaenderteBuchungFolgtGeaendertemZeiteintragNicht(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $this->bucheImProjektUm(1, static fn(array $a): array => ['stunden' => 6] + $a);

        $this->speichern([$this->eintrag(101, '2026-03-02', 7)]);

        $buchung = $this->buchungen(1)[0];
        self::assertEquals(6, $buchung['stunden']);
        self::assertEquals(7, $buchung['zeitStunden']);
    }

    public function testZeiterfassungUebernehmenSetztNurDieProjektbuchung(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $this->bucheImProjektUm(1, static fn(array $a): array => ['stunden' => 6] + $a);
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);

        $this->assertOk($this->api->post('repair_booking', [
            'username' => 'monteur',
            'entryId'  => 101,
            'aktion'   => 'uebernehmen',
        ]));

        self::assertEquals(8, $this->buchungen(1)[0]['stunden']);
        self::assertEquals(8, $this->zeiteintraege()[101]['stunden']);
        self::assertSame('', $this->zeiteintraege()[101]['errorCode']);
    }

    public function testAbweichungBestaetigenBlendetZe013AusBisSichEtwasAendert(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $this->bucheImProjektUm(1, static fn(array $a): array => ['stunden' => 6] + $a);
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);

        $this->assertOk($this->api->post('repair_booking', [
            'username' => 'monteur',
            'entryId'  => 101,
            'aktion'   => 'bestaetigen',
        ]));

        self::assertEquals(6, $this->buchungen(1)[0]['stunden']);
        self::assertEquals(8, $this->zeiteintraege()[101]['stunden']);
        self::assertSame('', $this->zeiteintraege()[101]['errorCode']);
        self::assertSame(0, $this->assertOk($this->api->get('reconcile_bookings'))['counts']['abweichungen']);

        $this->speichern([$this->eintrag(101, '2026-03-02', 7)]);
        self::assertSame('ZE013', $this->zeiteintraege()[101]['errorCode']);
    }

    public function testVerschiebenAendertNurProjektbezugUndErhoehtZeitRev(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $alteRev = $this->assertOk($this->monteur->get('load_zeiterfassung'))['zeitRev'];

        $moved = $this->assertOk($this->api->post('move_position', [
            'zeitEntryId'     => 101,
            'zeitUser'        => 'monteur',
            'destBaustelleId' => 2,
        ]));

        self::assertGreaterThan($alteRev, $moved['zeitRev']);
        $eintrag = $this->zeiteintraege()[101];
        self::assertSame(2, $eintrag['baustelleId']);
        self::assertEquals(8, $eintrag['stunden']);
        self::assertSame('2026-03-02', $eintrag['datum']);
        $this->assertStatus(409, $this->monteur->post('save_zeiterfassung', [
            'entries'     => [$this->eintrag(101, '2026-03-02', 8)],
            'baseZeitRev' => $alteRev,
        ]));
    }

    public function testEigenerProjektwechselEntferntBuchungImAltenProjekt(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1)]);

        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 2)]);

        self::assertCount(0, $this->buchungen(1));
        self::assertCount(1, $this->buchungen(2));
        self::assertSame(0, $this->assertOk($this->api->get('reconcile_bookings'))['counts']['ghosts']);
    }

    public function testAdminLoeschenDesLetztenEintragsEntferntBuchung(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1)]);

        $this->assertOk($this->api->post('admin_delete_zeiterfassung', ['username' => 'monteur', 'entryId' => 101]));

        self::assertCount(0, $this->buchungen(1));
    }

    public function testAdminBearbeitungZiehtProjektbuchungNach(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1)]);

        $this->assertOk($this->api->post('admin_edit_zeiterfassung', [
            'username'    => 'monteur',
            'entryId'     => 101,
            'datum'       => '2026-03-02',
            'typ'         => 'arbeit',
            'stunden'     => 3,
            'baustelleId' => 2,
        ]));

        self::assertCount(0, $this->buchungen(1));
        $buchungen = $this->buchungen(2);
        self::assertCount(1, $buchungen);
        self::assertEquals(3, $buchungen[0]['stunden']);
    }

    public function testAdminBearbeitungOhneProjektUndUhrzeitBehaeltDiese(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1, ['von' => '06:30', 'bis' => '15:00', 'pause' => 0.5])]);

        $this->assertOk($this->api->post('admin_edit_zeiterfassung', [
            'username'  => 'monteur',
            'entryId'   => 101,
            'datum'     => '2026-03-02',
            'typ'       => 'arbeit',
            'stunden'   => 7,
            'bemerkung' => 'korrigiert',
        ]));

        $eintrag = $this->zeiteintraege()[101];
        self::assertSame(1, $eintrag['baustelleId']);
        self::assertSame('06:30', $eintrag['von']);
        self::assertSame('15:00', $eintrag['bis']);
        self::assertEquals(0.5, $eintrag['pause']);
        self::assertEquals(7, $this->buchungen(1)[0]['stunden']);
    }

    public function testJetztImportierenBuchtHandgeaenderteUndGesperrteBuchungenNichtDoppelt(): void
    {
        $this->speichern([
            $this->eintrag(101, '2026-03-02', 8, 1),
            $this->eintrag(102, '2026-03-03', 4, 2),
        ]);
        $this->bucheImProjektUm(1, static fn(array $a): array => ['stunden' => 6] + $a);
        $daten = $this->ladeDaten();
        $baustellen = $daten['baustellen'];
        foreach ($baustellen as &$b) {
            if ((int) $b['id'] === 2) {
                $b['arbeitszeit'] = [];
                $b['azImportBlocked'] = ['id:monteur:102'];
            }
        }
        unset($b);
        $this->saveBaustellen($baustellen, (int) $daten['rev']);

        $this->assertOk($this->api->post('auto_import_stunden', []));

        $buchungen = $this->buchungen(1);
        self::assertCount(1, $buchungen);
        self::assertEquals(6, $buchungen[0]['stunden']);
        self::assertCount(0, $this->buchungen(2));
    }

    public function testAltbuchungOhneSpiegelFolgtErsterAenderung(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        // Stand vor 3.0.15: verknüpfte Buchung ohne Spiegelfelder.
        $this->bucheImProjektUm(1, static function (array $a): array {
            unset($a['zeitStunden'], $a['zeitDatum'], $a['zeitStundenKatId']);
            return $a;
        });

        $this->speichern([$this->eintrag(101, '2026-03-02', 4)]);

        $buchung = $this->buchungen(1)[0];
        self::assertEquals(4, $buchung['stunden']);
        self::assertEquals(4, $buchung['zeitStunden']);
        self::assertSame('', $this->zeiteintraege()[101]['errorCode']);
    }

    public function testAltbuchungMitAbweichungWirdNichtUeberschrieben(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $this->bucheImProjektUm(1, static function (array $a): array {
            unset($a['zeitStunden'], $a['zeitDatum'], $a['zeitStundenKatId']);
            return ['stunden' => 6] + $a;
        });

        $this->speichern([$this->eintrag(101, '2026-03-02', 4)]);

        self::assertEquals(6, $this->buchungen(1)[0]['stunden']);
        self::assertSame('ZE013', $this->zeiteintraege()[101]['errorCode']);
    }

    public function testAltbuchungMitStandardkategorieFolgtKategoriewechsel(): void
    {
        $this->assertOk($this->api->post('set_stunden_kategorie', ['username' => 'monteur', 'stundenKategorie' => 'Monteur']));
        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1, ['stundenKatId' => null])]);
        self::assertSame(10, $this->buchungen(1)[0]['stundenKatId']);
        $this->bucheImProjektUm(1, static function (array $a): array {
            unset($a['zeitStunden'], $a['zeitDatum'], $a['zeitStundenKatId']);
            return $a;
        });

        $this->speichern([$this->eintrag(101, '2026-03-02', 8, 1, ['stundenKatId' => 11])]);

        $buchung = $this->buchungen(1)[0];
        self::assertSame(11, $buchung['stundenKatId']);
        self::assertEquals(70, $buchung['stundenpreis']);
    }

    public function testGesperrteBuchungWirdNichtNeuGebuchtUndNichtGemeldet(): void
    {
        $this->speichern([$this->eintrag(101, '2026-03-02', 8)]);
        $daten = $this->ladeDaten();
        $baustellen = $daten['baustellen'];
        foreach ($baustellen as &$b) {
            if ((int) $b['id'] === 1) {
                $b['arbeitszeit'] = [];
                $b['azImportBlocked'] = ['id:monteur:101'];
            }
        }
        unset($b);
        $this->saveBaustellen($baustellen, (int) $daten['rev']);

        $this->speichern([$this->eintrag(101, '2026-03-02', 7)]);

        self::assertCount(0, $this->buchungen(1));
        self::assertSame('', $this->zeiteintraege()[101]['errorCode']);
        $counts = $this->assertOk($this->api->get('reconcile_bookings'))['counts'];
        self::assertSame(0, $counts['orphans']);
        self::assertSame(0, $counts['abweichungen']);
    }
}
