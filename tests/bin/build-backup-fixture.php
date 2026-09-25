<?php

declare(strict_types=1);

/**
 * Erzeugt eine Backup-Fixture der aktuellen App-Version:
 *   php tests/bin/build-backup-fixture.php
 * Ergebnis: tests/fixtures/backups/v<Version>.zip + v<Version>.expected.json.
 * Einmal pro Release mit Schemaänderung ausführen und einchecken – die Tests
 * importieren danach jede Fixture und sichern so die Abwärtskompatibilität.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Tests\Support\ApiClient;
use Tests\Support\ApiResponse;
use Tests\Support\TestServer;

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'Admin-Test-123';

function ok(ApiResponse $r): array
{
    $json = $r->status === 200 ? $r->json() : [];
    if (!($json['ok'] ?? false)) {
        fwrite(STDERR, "Fehlgeschlagen: " . $r->describe() . "\n");
        exit(1);
    }
    return $json;
}

$server = TestServer::instance();
$server->resetData();
$api = $server->client();

ok($api->post('setup', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]));
$version = $api->get('check')->json()['version'];

$kunde1 = ok($api->post('save_kunde', ['firma' => 'Muster GmbH', 'ort' => 'Augsburg']))['kunde']['id'];
$kunde2 = ok($api->post('save_kunde', ['nachname' => 'Huber', 'vorname' => 'Anna', 'ort' => 'Friedberg']))['kunde']['id'];

ok($api->post('save', ['data' => [
    'baustellen' => [
        ['id' => 1, 'name' => 'Neubau Musterstraße', 'kundeId' => $kunde1, 'projektNr' => 'P-001',
            'material' => [['id' => 1, 'bezeichnung' => 'NYM-J 3x1,5', 'menge' => 100, 'ek' => 0.89]]],
        ['id' => 2, 'name' => 'Garage Huber', 'kundeId' => $kunde2],
    ],
    'pauschalen'      => [['id' => 1, 'name' => 'Anfahrt', 'preis' => 45.5]],
    'stundenKatalog'  => [['id' => 1, 'kategorie' => 'Geselle', 'preis' => 58, 'fixkosten' => 12.25]],
    'materialKatalog' => [['id' => 1, 'bezeichnung' => 'Schalter', 'einheit' => 'Stk', 'ek' => 3.99, 'aufschlag' => 25, 'artikelNr' => 'S-1']],
]]));

ok($api->post('save_zeiterfassung', ['entries' => [
    ['clientUuid' => 'fx-1', 'datum' => '2026-03-02', 'typ' => 'arbeit', 'baustelleId' => 1, 'stunden' => 8, 'von' => '07:00', 'bis' => '15:00', 'pause' => 0],
    ['clientUuid' => 'fx-2', 'datum' => '2026-03-03', 'typ' => 'arbeit', 'baustelleId' => 2, 'stunden' => 4, 'von' => '07:00', 'bis' => '11:00', 'pause' => 0],
]]));

$re = ok($api->post('save_rechnung', ['rechnung' => [
    'typ' => 'rechnung', 'kundeId' => $kunde1, 'baustelleId' => 1, 'datum' => '2026-03-10',
    'positionen' => [['bezeichnung' => 'Montage', 'menge' => 8, 'einheit' => 'h', 'einzelpreis' => 58]],
]]))['rechnung'];

ok($api->post('add_dienstleister', ['firma' => 'Gerüstbau Schmid']));

$zip = $api->get('backup_download');
if ($zip->status !== 200 || $zip->header('Content-Type') !== 'application/zip') {
    fwrite(STDERR, "Download fehlgeschlagen: " . $zip->describe() . "\n");
    exit(1);
}

$outDir = __DIR__ . '/../fixtures/backups';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents("{$outDir}/v{$version}.zip", $zip->body);
file_put_contents("{$outDir}/v{$version}.expected.json", json_encode([
    'appVersion'    => $version,
    'adminUser'     => ADMIN_USER,
    'adminPass'     => ADMIN_PASS,
    'baustellen'    => ['Neubau Musterstraße', 'Garage Huber'],
    'kunden'        => ['Muster GmbH', ''],
    'zeiterfassung' => ['fx-1', 'fx-2'],
    'rechnungen'    => [$re['nummer']],
    'dienstleister' => ['Gerüstbau Schmid'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo "Fixture geschrieben: tests/fixtures/backups/v{$version}.zip\n";
