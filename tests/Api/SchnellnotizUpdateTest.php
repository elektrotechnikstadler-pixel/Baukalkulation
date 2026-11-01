<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ApiClient;

final class SchnellnotizUpdateTest extends ApiTestCase
{
    private ApiClient $alice;
    private ApiClient $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupAdmin();
        $this->alice = $this->createActiveUser('alice', 'Alice-Pass-1');
        $this->bob = $this->createActiveUser('bob', 'Bob-Pass-1');

        $rev = $this->assertOk($this->api->get('load'))['data']['rev'];
        $this->saveBaustellen([
            ['id' => 1, 'name' => 'Baustelle Nord'],
        ], $rev);
    }

    private function createNote(ApiClient $client, string $text, array $extra = []): int
    {
        $json = $this->assertOk($client->post('save_schnellnotiz', ['text' => $text] + $extra));
        return (int) $json['note']['id'];
    }

    /** @return array<string, mixed> */
    private function loadNote(ApiClient $client, int $id): array
    {
        $notes = $this->assertOk($client->get('load_schnellnotizen'))['notes'];
        foreach ($notes as $note) {
            if ((int) ($note['id'] ?? 0) === $id) {
                return $note;
            }
        }
        self::fail("Notiz {$id} wurde nicht geladen.");
    }

    public function testErstellerKannTextAendernUndLoadZeigtNeuenText(): void
    {
        $noteId = $this->createNote($this->alice, 'Alttext äöü');

        $updated = $this->assertOk($this->alice->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'Neuer Text äöü',
        ]));

        $this->assertSame('Neuer Text äöü', $updated['note']['text']);
        $this->assertSame('Neuer Text äöü', $this->loadNote($this->alice, $noteId)['text']);
    }

    public function testNichtSichtbareNotizLiefert403(): void
    {
        $noteId = $this->createNote($this->api, 'Nur Admin sieht das');

        $this->assertStatus(403, $this->bob->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'unerlaubt',
        ]));
    }

    public function testZuweisungAnAlleOderEigeneGruppeErlaubtBearbeiten(): void
    {
        $alleId = $this->createNote($this->api, 'Alle alt', ['zugewiesen_an' => 'alle']);
        $this->assertOk($this->bob->post('update_schnellnotiz', ['id' => $alleId, 'text' => 'Alle neu']));

        $groupId = (int) $this->assertOk($this->api->post('save_gruppe', [
            'name' => 'Team Bob',
            'mitglieder' => ['bob'],
        ]))['id'];
        $gruppeId = $this->createNote($this->api, 'Gruppe alt', ['zugewiesen_an' => 'gruppe:' . $groupId]);

        $this->assertOk($this->bob->post('update_schnellnotiz', [
            'id' => $gruppeId,
            'text' => 'Gruppe neu',
        ]));
    }

    public function testBaustellenNotizDarfVonJedemAngemeldetenBearbeitetWerden(): void
    {
        $noteId = $this->createNote($this->api, 'Baustelle alt', [
            'baustelleId' => 1,
            'baustelleName' => 'Baustelle Nord',
        ]);

        $updated = $this->assertOk($this->bob->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'Baustelle neu',
        ]));

        $this->assertSame('Baustelle neu', $updated['note']['text']);
    }

    public function testUnbekannteIdLiefert404(): void
    {
        $this->assertStatus(404, $this->api->post('update_schnellnotiz', [
            'id' => 999999,
            'text' => 'x',
        ]));
    }

    public function testGetMitSichtbarerIdLiefert405UndMitUnsichtbarerId403(): void
    {
        $sichtbarId = $this->createNote($this->api, 'Sichtbar', ['zugewiesen_an' => 'alle']);
        $unsichtbarId = $this->createNote($this->api, 'Unsichtbar');

        $this->assertStatus(405, $this->bob->get('update_schnellnotiz', ['id' => $sichtbarId]));
        $this->assertStatus(403, $this->bob->get('update_schnellnotiz', ['id' => $unsichtbarId]));
    }

    public function testArchivierteNotizLiefert409(): void
    {
        $noteId = $this->createNote($this->alice, 'Archivtest');
        $this->assertOk($this->api->post('archive_schnellnotiz', ['id' => $noteId]));

        $this->assertStatus(409, $this->alice->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'darf nicht',
        ]));
    }

    public function testLeererTextLiefert400(): void
    {
        $noteId = $this->createNote($this->alice, 'Vorhanden');

        $this->assertStatus(400, $this->alice->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => '   ',
        ]));
    }

    public function testNormalerNutzerKannZugewiesenAnNichtAendern(): void
    {
        $noteId = $this->createNote($this->api, 'Zuordnung bleibt', ['zugewiesen_an' => 'alle']);

        $updated = $this->assertOk($this->bob->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'Text neu',
            'zugewiesen_an' => 'bob',
        ]));

        $this->assertSame('alle', $updated['note']['zugewiesen_an']);
        $this->assertSame('alle', $this->loadNote($this->api, $noteId)['zugewiesen_an']);
    }

    public function testAdminKannZugewiesenAnAendern(): void
    {
        $noteId = $this->createNote($this->api, 'Admin-Änderung', ['zugewiesen_an' => 'alle']);

        $updated = $this->assertOk($this->api->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'Admin Text neu',
            'zugewiesen_an' => 'bob',
        ]));

        $this->assertSame('bob', $updated['note']['zugewiesen_an']);
    }

    public function testGastUndAbgemeldeterNutzerErhalten401(): void
    {
        $noteId = $this->createNote($this->api, 'Nur mit Login');

        $gast = $this->server->client();
        $this->assertStatus(401, $gast->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'x',
        ]));

        $this->assertOk($this->alice->post('logout'));
        $this->assertStatus(401, $this->alice->post('update_schnellnotiz', [
            'id' => $noteId,
            'text' => 'x',
        ]));
    }
}
