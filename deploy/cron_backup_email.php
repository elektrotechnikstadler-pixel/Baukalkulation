<?php
// ============================================================
// CRON: Automatisches Backup per E-Mail
// ============================================================
// Erstellt einen ZIP (baukalkulation.json + database.sqlite)
// und sendet ihn an die konfigurierte E-Mail-Adresse.
//
// Einrichtung auf Synology NAS:
//   Systemsteuerung -> Aufgabenplaner -> Erstellen -> Geplante Aufgabe
//   Zeitplan: Taeglich um 07:00 Uhr (oder konfigurierte Uhrzeit)
//   Befehl:  php /volume1/web/baukalkulation/cron_backup_email.php
//
// Alternativ per SSH (crontab -e):
//   0 7 * * * php /volume1/web/baukalkulation/cron_backup_email.php >> /volume1/web/baukalkulation/data/cron.log 2>&1
//
// Flag --force: Uhrzeit- und Lock-Pruefung ueberspringen (fuer manuellen Test)

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\MailService;
use App\DataService;

define('DATA_DIR', __DIR__ . '/data/');

$_pathsCfg = file_exists(DATA_DIR . 'paths_config.json')
    ? (json_decode(file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
    : [];
define('BACKUP_DIR',   $_pathsCfg['backups']   ?? DATA_DIR . 'backups/');
// SW-Version dynamisch aus manifest.json (wird pro Release gebumpt) statt fest verdrahtet.
$__ver = '0.0.0';
$__mf  = __DIR__ . '/manifest.json';
if (is_file($__mf)) {
    $__m = json_decode((string)file_get_contents($__mf), true);
    if (!empty($__m['version'])) $__ver = (string)$__m['version'];
}
define('APP_VERSION',  $__ver);

$force = in_array('--force', $argv ?? [], true);

// -- Log-Hilfsfunktion --
function logMsg(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
    $logFile = DATA_DIR . 'erinnerung.log';
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

logMsg("=== Backup-E-Mail gestartet" . ($force ? " [FORCE]" : "") . " ===");

// -- DB verbinden --
$dbPath = DATA_DIR . 'database.sqlite';
if (!file_exists($dbPath)) {
    logMsg("FEHLER: Datenbank nicht gefunden: $dbPath");
    exit(1);
}
try {
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
} catch (PDOException $e) {
    logMsg("FEHLER: DB-Verbindung fehlgeschlagen: " . $e->getMessage());
    exit(1);
}

// -- Einstellungen laden --
$settingsRow = $db->query("SELECT data FROM settings WHERE id = 1")->fetch();
$settings    = $settingsRow ? (json_decode($settingsRow['data'], true) ?? []) : [];

$smtpCfg = [
    'smtp_host'       => $settings['smtp_host']       ?? '',
    'smtp_port'       => $settings['smtp_port']        ?? '587',
    'smtp_user'       => $settings['smtp_user']        ?? '',
    'smtp_pass'       => $settings['smtp_pass']        ?? '',
    'smtp_from_email' => $settings['smtp_from_email']  ?? $settings['firma_email'] ?? '',
    'smtp_from_name'  => $settings['smtp_from_name']   ?? $settings['firma_name']  ?? '',
    'smtp_security'   => $settings['smtp_security']    ?? 'tls',
];

// -- Erinnerungs-Einstellungen laden --
$erRow   = $db->query("SELECT data FROM erinnerung_settings WHERE id = 1")->fetch();
$erData  = $erRow ? (json_decode($erRow['data'], true) ?? []) : [];

$aktiv     = (bool)($erData['backup_email_aktiv']      ?? false);
$empfaenger = trim($erData['backup_email_empfaenger']  ?? '');
$zyklus    = $erData['backup_email_zyklus']            ?? 'woechentlich';
$uhrzeit   = $erData['backup_email_uhrzeit']           ?? '07:00';

// Fallback: Absende-E-Mail als Empfaenger
if ($empfaenger === '') {
    $empfaenger = $smtpCfg['smtp_from_email'];
}

// -- Aktiv-Pruefung (ausser bei --force) --
if (!$aktiv && !$force) {
    logMsg("Backup-E-Mail ist deaktiviert.");
    exit(0);
}

if (!$empfaenger || !filter_var($empfaenger, FILTER_VALIDATE_EMAIL)) {
    logMsg("FEHLER: Keine gueltige Empfaenger-E-Mail konfiguriert ($empfaenger).");
    exit(1);
}

// -- Lock-File pruefen (ausser bei --force) --
$lockFile = DATA_DIR . 'backup_email_sent.txt';

if (!$force) {
    // Zyklus-Schluessel berechnen
    switch ($zyklus) {
        case 'taeglich':     $zyklusKey = date('Y-m-d'); break;
        case 'monatlich':    $zyklusKey = date('Y-m'); break;
        case 'woechentlich':
        default:             $zyklusKey = date('Y-W'); break;
    }

    if (file_exists($lockFile) && trim(file_get_contents($lockFile)) === $zyklusKey) {
        logMsg("Backup-E-Mail fuer diesen Zyklus ($zyklusKey) bereits gesendet.");
        exit(0);
    }

    // Uhrzeit pruefen
    $now = date('H:i');
    if ($now < $uhrzeit) {
        logMsg("Uhrzeit $now < $uhrzeit -> noch nicht faellig.");
        exit(0);
    }
}

// -- ZIP erstellen --
logMsg("Erstelle Backup-ZIP ...");

$zipName = 'baukalkulation_backup_' . date('Y-m-d_H-i') . '.zip';
$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    logMsg("FEHLER: ZIP konnte nicht erstellt werden: $zipPath");
    exit(1);
}

// baukalkulation.json on-the-fly generieren
try {
    $data = DataService::loadAllData($db);
    $snap = ['ts' => date('c'), 'v' => APP_VERSION, 'data' => $data];
    $jsonBytes = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $zip->addFromString('baukalkulation.json', $jsonBytes);
    logMsg("baukalkulation.json hinzugefuegt (" . round(strlen($jsonBytes) / 1024, 1) . " KB)");
} catch (Throwable $e) {
    logMsg("WARNUNG: baukalkulation.json konnte nicht erstellt werden: " . $e->getMessage());
}

// database.sqlite hinzufuegen (WAL-Checkpoint erzwingen, damit alle
// committed Daten in der Hauptdatei stehen – wichtig bei WAL-Modus)
if (file_exists($dbPath)) {
    try {
        $db->exec('PRAGMA wal_checkpoint(FULL)');
    } catch (Throwable $e) {
        logMsg("WARNUNG: WAL-Checkpoint fehlgeschlagen: " . $e->getMessage());
    }
    // Integritaetspruefung: schlaegt Alarm, falls die Datenbank beschaedigt ist,
    // bevor eine (moeglicherweise korrupte) Datei ins Backup wandert. Rein
    // additiv – das Backup wird trotzdem erstellt, damit im Fehlerfall ueberhaupt
    // eine Kopie existiert; der Log-Eintrag macht das Problem aber sichtbar.
    try {
        $integrity = $db->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity === 'ok') {
            logMsg("Integritaetspruefung: ok");
        } else {
            logMsg("ACHTUNG: Datenbank-Integritaetspruefung fehlgeschlagen: " . $integrity);
        }
    } catch (Throwable $e) {
        logMsg("WARNUNG: Integritaetspruefung konnte nicht ausgefuehrt werden: " . $e->getMessage());
    }
    $zip->addFile($dbPath, 'database.sqlite');
    logMsg("database.sqlite hinzugefuegt (" . round(filesize($dbPath) / 1024 / 1024, 2) . " MB)");
} else {
    logMsg("WARNUNG: database.sqlite nicht gefunden.");
}

$zip->close();
$zipSize = filesize($zipPath);
logMsg("ZIP erstellt: $zipName (" . round($zipSize / 1024, 1) . " KB)");

// -- E-Mail versenden --
$firmaName = $smtpCfg['smtp_from_name'] ?: 'Baukalkulation';
$subject   = "Automatisches Backup – $firmaName – " . date('d.m.Y');
$htmlBody  = '<p>Automatisches Backup der Baukalkulation vom <strong>' . date('d.m.Y H:i') . ' Uhr</strong>.</p>'
           . '<p>Im Anhang befindet sich das vollständige Backup als ZIP-Datei.</p>'
           . '<ul>'
           . '<li><strong>baukalkulation.json</strong> – alle Projektdaten</li>'
           . '<li><strong>database.sqlite</strong> – vollständige Datenbank</li>'
           . '</ul>'
           . '<p><em>Baukalkulation_ES v' . APP_VERSION . ' – ' . date('d.m.Y H:i') . '</em></p>';

try {
    $zipBytes = file_get_contents($zipPath);
    if ($zipBytes === false) throw new RuntimeException("ZIP-Datei konnte nicht gelesen werden.");
    MailService::send(
        $smtpCfg,
        $empfaenger,
        '',
        $subject,
        $htmlBody,
        $zipBytes,
        $zipName,
        'application/zip'
    );
    logMsg("E-Mail erfolgreich gesendet an: $empfaenger");
} catch (RuntimeException $e) {
    logMsg("FEHLER beim E-Mail-Versand: " . $e->getMessage());
    @unlink($zipPath);
    exit(1);
} finally {
    @unlink($zipPath);
}

// -- Lock-File schreiben (ausser bei --force) --
if (!$force) {
    switch ($zyklus) {
        case 'taeglich':  $zyklusKey = date('Y-m-d'); break;
        case 'monatlich': $zyklusKey = date('Y-m');   break;
        default:          $zyklusKey = date('Y-W');   break;
    }
    file_put_contents($lockFile, $zyklusKey);
    logMsg("Lock-File geschrieben: $zyklusKey");
}

logMsg("=== Backup-E-Mail abgeschlossen ===\n");
