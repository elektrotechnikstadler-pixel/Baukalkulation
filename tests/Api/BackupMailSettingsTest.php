<?php

declare(strict_types=1);

namespace Tests\Api;

final class BackupMailSettingsTest extends ApiTestCase
{
    private function gespeicherterWert(): string
    {
        $pdo = $this->server->db();
        $data = json_decode((string) $pdo->query('SELECT data FROM erinnerung_settings WHERE id = 1')->fetchColumn(), true);
        return (string) ($data['backup_email_passwort'] ?? '');
    }

    public function testPasswortWirdVerschluesseltGespeichertUndNieAusgeliefert(): void
    {
        $this->setupAdmin();
        $this->assertFalse($this->assertOk($this->api->get('load_erinnerung_settings'))['settings']['backup_email_passwort_gesetzt']);

        $this->assertOk($this->api->post('save_erinnerung_settings', ['backup_email_passwort' => 'Sehr-Geheim-2026', 'backup_email_max_mb' => 15]));

        $settings = $this->assertOk($this->api->get('load_erinnerung_settings'))['settings'];
        $this->assertTrue($settings['backup_email_passwort_gesetzt']);
        $this->assertArrayNotHasKey('backup_email_passwort', $settings);
        $this->assertSame(15, $settings['backup_email_max_mb']);
        $this->assertStringStartsWith('enc:v1:', $this->gespeicherterWert());
        $this->assertFileExists($this->server->dataPath('secret.key'));
    }

    public function testSpeichernOhnePasswortBehaeltEsUndLoeschenEntferntEs(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_erinnerung_settings', ['backup_email_passwort' => 'Sehr-Geheim-2026']));
        $vorher = $this->gespeicherterWert();

        $this->assertOk($this->api->post('save_erinnerung_settings', ['backup_email_aktiv' => true]));
        $this->assertSame($vorher, $this->gespeicherterWert());

        $this->assertOk($this->api->post('save_erinnerung_settings', ['backup_email_passwort_loeschen' => true]));
        $this->assertSame('', $this->gespeicherterWert());
    }

    public function testZuKurzesPasswortWirdAbgelehnt(): void
    {
        $this->setupAdmin();

        $this->assertStatus(400, $this->api->post('save_erinnerung_settings', ['backup_email_passwort' => 'kurz']));
    }
}
