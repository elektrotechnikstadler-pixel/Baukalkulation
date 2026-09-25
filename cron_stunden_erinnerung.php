<?php
// ============================================================
// CRON: Stunden-Erinnerung
// ============================================================
//
// Prueft ob Monteure in der Wochenplanung eingeplant waren,
// aber fuer den Vortag keine Stunden gebucht haben.
// Loggt fehlende Eintraege und speichert sie als Benachrichtigung.
//
// Einrichtung auf Synology NAS:
//   Systemsteuerung -> Aufgabenplaner -> Erstellen -> Geplante Aufgabe
//   Zeitplan: Taeglich um 07:00 Uhr
//   Befehl:  php /volume1/web/baukalkulation/cron_stunden_erinnerung.php
//
// Alternativ per SSH (crontab -e):
//   0 7 * * * php /volume1/web/baukalkulation/cron_stunden_erinnerung.php >> /volume1/web/baukalkulation/data/cron.log 2>&1
//
// ============================================================

define('DATA_DIR', __DIR__ . '/data/');

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
logMsg("=== Stunden-Erinnerung gestartet ===");

// -- Einstellungen pruefen --
$settingsFile = DATA_DIR . 'erinnerung_settings.json';
$settings = file_exists($settingsFile)
    ? (json_decode(file_get_contents($settingsFile), true) ?? [])
    : [];
$stundenAktiv = $settings['stunden_aktiv'] ?? true;

if (!$stundenAktiv) {
    logMsg("Stunden-Erinnerung ist deaktiviert.");
    exit(0);
}

// Welcher Tag soll geprueft werden? -> Gestern
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayOfWeek = date('N', strtotime($yesterday)); // 1=Mo .. 7=So

// Am Montag pruefen wir nicht den Sonntag (kein Arbeitstag)
if ($dayOfWeek == 7) {
    logMsg("Gestern war Sonntag - keine Pruefung.");
    exit(0);
}

logMsg("Pruefe Stunden fuer: $yesterday");

// -- Daten laden --
$wochenplanung = loadJsonFile(DATA_DIR . 'wochenplanung.json');
$zeiterfassung = loadJsonFile(DATA_DIR . 'zeiterfassung.json');

// Users laden
$usersFile = DATA_DIR . 'users.json';
$users = loadJsonFile($usersFile);
$userMap = []; // username -> display_name
foreach ($users as $u) {
    $userMap[$u['username']] = $u['display_name'] ?? $u['username'];
}

// -- Baustellen-Namen laden --
$appData = loadJsonFile(DATA_DIR . 'baukalkulation.json');
$baustelleNames = [];
$bList = $appData['baustellen'] ?? [];
foreach ($bList as $b) {
    if (isset($b['id']) && isset($b['name'])) {
        $baustelleNames[$b['id']] = $b['name'];
    }
}

// -- Pruefung pro Benutzer --
$fehlend = [];

foreach ($wochenplanung as $username => $tage) {
    $dayPlan = $tage[$yesterday] ?? null;
    if (empty($dayPlan) || !is_array($dayPlan)) continue;

    $baustellenGeplant = array_filter($dayPlan, function($entry) {
        return ($entry['typ'] ?? '') === 'baustelle' && !empty($entry['baustelleId']);
    });
    if (empty($baustellenGeplant)) continue;

    // Hat der Benutzer Stunden fuer gestern gebucht?
    $userZeit = $zeiterfassung[$username]['entries'] ?? [];
    $hasStunden = false;
    foreach ($userZeit as $entry) {
        if (($entry['datum'] ?? '') === $yesterday && ($entry['typ'] ?? '') === 'arbeit') {
            $hasStunden = true;
            break;
        }
    }

    if ($hasStunden) {
        logMsg("  $username: Stunden fuer $yesterday gebucht.");
        continue;
    }

    $baustellenNamen = [];
    foreach ($baustellenGeplant as $bp) {
        $bId = $bp['baustelleId'];
        $baustellenNamen[] = $baustelleNames[$bId] ?? "Baustelle #$bId";
    }

    $displayName = $userMap[$username] ?? $username;
    $fehlend[] = [
        'username' => $username,
        'display_name' => $displayName,
        'baustellen' => $baustellenNamen,
    ];

    logMsg("  $username: KEINE Stunden fuer $yesterday (geplant: " . implode(', ', $baustellenNamen) . ")");
}

// -- Ergebnis speichern --
if (!empty($fehlend)) {
    $notifFile = DATA_DIR . 'stunden_erinnerung_result.json';
    $result = [
        'datum' => $yesterday,
        'erstellt' => date('Y-m-d H:i:s'),
        'fehlend' => $fehlend,
    ];
    file_put_contents($notifFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    logMsg(count($fehlend) . " Benutzer ohne Stundenbuchung fuer $yesterday.");
} else {
    logMsg("Alle eingeplanten Benutzer haben Stunden gebucht.");
}

logMsg("=== Fertig: " . count($fehlend) . " fehlende Buchung(en) ===\n");