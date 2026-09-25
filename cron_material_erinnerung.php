<?php
// ============================================================
// CRON: Material-Erinnerung
// ============================================================
//
// Prueft ob offenes Material (status='offen') vorhanden ist
// und noch nicht bestellt wurde. Wenn die konfigurierte Uhrzeit
// erreicht ist, wird eine Erinnerung geloggt und gespeichert.
//
// Einrichtung auf Synology NAS:
//   Systemsteuerung -> Aufgabenplaner -> Erstellen -> Geplante Aufgabe
//   Zeitplan: Taeglich, jede Stunde (oder halbstuendlich)
//   Befehl:  php /volume1/web/baukalkulation/cron_material_erinnerung.php
//
// Alternativ per SSH (crontab -e):
//   */30 * * * * php /volume1/web/baukalkulation/cron_material_erinnerung.php >> /volume1/web/baukalkulation/data/cron.log 2>&1
//
// ============================================================

define('DATA_DIR', rtrim(getenv('BK_DATA_DIR') ?: __DIR__ . '/data', '/\\') . '/');

// -- Hilfsfunktionen --
function loadJsonFile($path) {
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    return json_decode($raw, true) ?? [];
}

function logMsg($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
    $logFile = DATA_DIR . 'erinnerung.log';
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

// == HAUPTLOGIK ==
logMsg("=== Material-Erinnerung gestartet ===");

// -- Einstellungen laden --
$settingsFile = DATA_DIR . 'erinnerung_settings.json';
$settings = file_exists($settingsFile)
    ? (json_decode(file_get_contents($settingsFile), true) ?? [])
    : [];

$materialAktiv = $settings['material_aktiv'] ?? true;
$materialZeit  = $settings['material_uhrzeit'] ?? '14:00';

if (!$materialAktiv) {
    logMsg("Material-Erinnerung ist deaktiviert.");
    exit(0);
}

// -- Uhrzeit pruefen --
$now     = date('H:i');
$today   = date('Y-m-d');
$lockFile = DATA_DIR . 'material_erinnerung_sent.txt';

// Bereits heute verarbeitet?
if (file_exists($lockFile) && trim(file_get_contents($lockFile)) === $today) {
    logMsg("Heute ($today) bereits verarbeitet.");
    exit(0);
}

// Uhrzeit noch nicht erreicht?
if ($now < $materialZeit) {
    logMsg("Uhrzeit $now < $materialZeit -> noch nicht faellig.");
    exit(0);
}

logMsg("Uhrzeit $now >= $materialZeit -> pruefe offenes Material.");

// -- Wochenende ueberspringen --
$dayOfWeek = date('N'); // 1=Mo .. 7=So
if ($dayOfWeek >= 6) {
    logMsg("Wochenende -> keine Erinnerung.");
    exit(0);
}

// -- Daten laden --
$appData = loadJsonFile(DATA_DIR . 'baukalkulation.json');
$baustellen = $appData['baustellen'] ?? [];

// Offenes Material sammeln
$offenesMaterial = [];
foreach ($baustellen as $b) {
    $fm = $b['fehlendesMaterial'] ?? [];
    foreach ($fm as $m) {
        if (($m['status'] ?? '') === 'offen') {
            $offenesMaterial[] = [
                'baustelleName' => $b['name'] ?? 'Unbekannt',
                'bezeichnung'   => $m['bezeichnung'] ?? '',
                'menge'         => $m['menge'] ?? 0,
                'einheit'       => $m['einheit'] ?? '',
                'datum'         => $m['datum'] ?? '',
            ];
        }
    }
}

if (empty($offenesMaterial)) {
    logMsg("Kein offenes Material vorhanden -> keine Erinnerung noetig.");
    file_put_contents($lockFile, $today);
    exit(0);
}

logMsg(count($offenesMaterial) . " offene Positionen gefunden.");

// -- Ergebnis nach Baustelle gruppiert speichern --
$grouped = [];
foreach ($offenesMaterial as $om) {
    $grouped[$om['baustelleName']][] = $om;
}

$resultFile = DATA_DIR . 'material_erinnerung_result.json';
$result = [
    'datum' => $today,
    'erstellt' => date('Y-m-d H:i:s'),
    'anzahl_offen' => count($offenesMaterial),
    'baustellen' => [],
];

foreach ($grouped as $bName => $items) {
    $positionen = [];
    foreach ($items as $item) {
        $positionen[] = [
            'bezeichnung' => $item['bezeichnung'],
            'menge' => $item['menge'],
            'einheit' => $item['einheit'],
        ];
        logMsg("  $bName: {$item['bezeichnung']} ({$item['menge']} {$item['einheit']})");
    }
    $result['baustellen'][] = [
        'name' => $bName,
        'positionen' => $positionen,
    ];
}

file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Lock-Datei schreiben, damit heute nicht nochmal verarbeitet wird
file_put_contents($lockFile, $today);
logMsg("=== Fertig: " . count($offenesMaterial) . " offene Material-Position(en) geloggt ===\n");