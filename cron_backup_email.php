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

use App\Backup\BackupArchive;
use App\Backup\BackupWriter;
use App\Services\MailService;
use App\Services\SecretBox;

define('DATA_DIR', rtrim(getenv('BK_DATA_DIR') ?: __DIR__ . '/data', '/\\') . '/');

$_pathsCfg = file_exists(DATA_DIR . 'paths_config.json')
    ? (json_decode(file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
    : [];
define('BACKUP_DIR',   $_pathsCfg['backups']   ?? DATA_DIR . 'backups/');
$__vf = __DIR__ . '/VERSION';
define('APP_VERSION', is_file($__vf) ? trim((string)file_get_contents($__vf)) : '0.0.0');

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
    'smtp_pass'       => SecretBox::decrypt((string)($settings['smtp_pass'] ?? '')),
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

// -- ZIP erstellen (Format 2: manifest.json + konsistenter DB-Snapshot) --
logMsg("Erstelle Backup-ZIP ...");

$zipName = 'baukalkulation_backup_' . date('Y-m-d_H-i') . '.zip';
$zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bk_mail_' . bin2hex(random_bytes(6)) . '.zip';
$tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bk_mail_' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;

$password = '';
try {
    $password = SecretBox::decrypt((string)($erData['backup_email_passwort'] ?? ''));
} catch (Throwable $e) {
    // Niemals unverschlüsselt senden, wenn eine Verschlüsselung eingerichtet ist.
    logMsg("FEHLER: Backup-Passwort nicht lesbar (" . $e->getMessage() . "). Bitte in den Einstellungen neu setzen.");
    exit(1);
}

try {
    $manifest = BackupWriter::writeDir($db, $tmpDir);
    BackupWriter::zipDir($tmpDir, $zipPath, $password !== '' ? $password : null);
} catch (Throwable $e) {
    logMsg("FEHLER: Sicherung konnte nicht erstellt werden: " . $e->getMessage());
    BackupArchive::removeDir($tmpDir);
    if (is_file($zipPath)) unlink($zipPath);
    exit(1);
}
BackupArchive::removeDir($tmpDir);
$zipSize = filesize($zipPath);
$zipHash = hash_file('sha256', $zipPath);
logMsg("ZIP erstellt: $zipName (" . round($zipSize / 1024, 1) . " KB" . ($password !== '' ? ', AES-256' : ', UNVERSCHLUESSELT') . ")");
if ($password === '') {
    logMsg("WARNUNG: Kein Backup-Passwort gesetzt – die Sicherung wird unverschluesselt versendet.");
}

// -- E-Mail versenden --
$firmaName = $smtpCfg['smtp_from_name'] ?: 'Baukalkulation';
$subject   = "Automatisches Backup – $firmaName – " . date('d.m.Y');
$maxBytes  = max(1, (int)($erData['backup_email_max_mb'] ?? 20)) * 1024 * 1024;
$tooLarge  = $zipSize > $maxBytes;

$htmlBody  = '<p>Automatisches Backup der Baukalkulation vom <strong>' . date('d.m.Y H:i') . ' Uhr</strong>.</p>';
if ($tooLarge) {
    $htmlBody .= '<p><strong>Die Sicherung ist mit ' . round($zipSize / 1024 / 1024, 1) . ' MB größer als das eingestellte Limit ('
               . round($maxBytes / 1024 / 1024) . ' MB) und wurde deshalb nicht angehängt.</strong> '
               . 'Die tägliche Sicherung liegt weiterhin auf dem Server (Einstellungen → Datensicherung).</p>';
} else {
    $htmlBody .= '<p>Im Anhang befindet sich das vollständige Backup als ZIP-Datei'
               . ($password !== ''
                   ? ' (<strong>AES-256-verschlüsselt</strong> – öffnen mit 7-Zip/WinZip und dem hinterlegten Backup-Passwort).'
                   : '. <strong>Achtung: unverschlüsselt</strong> – bitte in den Einstellungen ein Backup-Passwort hinterlegen.')
               . '</p>';
}
$htmlBody .= '<ul>'
           . '<li>Format ' . (int)$manifest['format'] . ', Schema ' . htmlspecialchars((string)$manifest['schemaVersion']) . '</li>'
           . '<li>SHA-256 der ZIP-Datei: <code>' . $zipHash . '</code></li>'
           . '</ul>'
           . '<p><em>Baukalkulation_ES v' . htmlspecialchars(APP_VERSION) . ' – ' . date('d.m.Y H:i') . '</em></p>';

try {
    $zipBytes = $tooLarge ? null : file_get_contents($zipPath);
    if ($zipBytes === false) throw new RuntimeException("ZIP-Datei konnte nicht gelesen werden.");
    MailService::send(
        $smtpCfg,
        $empfaenger,
        '',
        $subject,
        $htmlBody,
        $zipBytes,
        $tooLarge ? null : $zipName,
        'application/zip'
    );
    logMsg("E-Mail erfolgreich gesendet an: $empfaenger" . ($tooLarge ? ' (ohne Anhang, zu gross)' : ''));
} catch (RuntimeException $e) {
    logMsg("FEHLER beim E-Mail-Versand: " . $e->getMessage());
    exit(1);
} finally {
    if (is_file($zipPath)) unlink($zipPath);
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
