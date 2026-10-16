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
use App\Services\Jobs\JobProtokoll;
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
if (\App\Database\ConnectionConfig::driver() === 'sqlite' && !file_exists($dbPath)) {
    logMsg("FEHLER: Datenbank nicht gefunden: $dbPath");
    exit(1);
}
try {
    $db = \App\Database\ConnectionConfig::open();
} catch (PDOException $e) {
    logMsg("FEHLER: DB-Verbindung fehlgeschlagen: " . $e->getMessage());
    exit(1);
}

// -- Job-Protokoll (AP-20261012-datensicherung-nas, T2) --
// Konstruktion defensiv abgesichert: Eine fehlschlagende Protokollierung darf den
// Mailablauf nie unterbrechen (Befund Runde 2) – $protokoll bleibt dann null.
$protokoll = null;
try {
    $protokoll = new JobProtokoll($db);
} catch (\Throwable $e) {
    error_log('[cron_backup_email] Protokoll-Erstellung fehlgeschlagen: ' . $e->getMessage());
}

/**
 * Protokolliert einen Fehlschlag des Jobs "backupmail". Läuft das Skript alle 15 min
 * mit demselben Fehlercode im selben Zyklus weiter, entsteht kein weiterer
 * Laufeintrag – nur letzterFehlerAm wird aktualisiert.
 * Nur per error_log(): eine fehlschlagende Protokollierung darf den Mailablauf nie
 * abbrechen (Befund Runde 2 – auch PDOException ist eine RuntimeException).
 */
function protokolliereBackupmailFehler(?JobProtokoll $protokoll, bool $force, string $zyklusKey, string $code, string $meldung): void {
    if ($protokoll === null) {
        return;
    }
    try {
        if (!$force) {
            $status = $protokoll->status('backupmail');
            if (($status['fehlerCode'] ?? '') === $code && ($status['tageslaufDatum'] ?? '') === $zyklusKey) {
                $protokoll->fehlerZeitAktualisieren('backupmail');
                return;
            }
        }
        $laufId = $protokoll->start('backupmail', $force ? 'manuell' : 'planer', $zyklusKey);
        $protokoll->fehler($laufId, $code, $meldung);
    } catch (\Throwable $e) {
        error_log('[cron_backup_email] Protokollierung (Fehler-Helfer) fehlgeschlagen: ' . $e->getMessage());
    }
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

// Zyklusschluessel (R6: "nicht faellig" bleibt ohne Protokolleintrag und zaehlt nie als Erfolg)
$zyklusKey = match ($zyklus) {
    'taeglich'  => date('Y-m-d'),
    'monatlich' => date('Y-m'),
    default     => date('Y-W'),
};

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
    protokolliereBackupmailFehler($protokoll, $force, $zyklusKey, 'BACKUPMAIL_EMPFAENGER', 'Kein gültiger Backup-Empfänger konfiguriert.');
    exit(1);
}

// -- Lock-File pruefen (ausser bei --force) --
$lockFile = DATA_DIR . 'backup_email_sent.txt';

if (!$force) {
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
    protokolliereBackupmailFehler($protokoll, $force, $zyklusKey, 'BACKUPMAIL_PASSWORT', 'Backup-Passwort kann nicht entschlüsselt werden.');
    exit(1);
}

try {
    $manifest = BackupWriter::writeDir($db, $tmpDir);
    BackupWriter::zipDir($tmpDir, $zipPath, $password !== '' ? $password : null);
} catch (Throwable $e) {
    logMsg("FEHLER: Sicherung konnte nicht erstellt werden: " . $e->getMessage());
    BackupArchive::removeDir($tmpDir);
    if (is_file($zipPath)) unlink($zipPath);
    protokolliereBackupmailFehler($protokoll, $force, $zyklusKey, 'BACKUPMAIL_ERSTELLEN', 'Sicherung für den Versand konnte nicht erstellt werden.');
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
} catch (RuntimeException $e) {
    logMsg("FEHLER beim E-Mail-Versand: " . $e->getMessage());
    protokolliereBackupmailFehler($protokoll, $force, $zyklusKey, 'BACKUPMAIL_VERSAND', 'Backup-E-Mail konnte nicht gesendet werden.');
    exit(1);
} finally {
    if (is_file($zipPath)) unlink($zipPath);
}

logMsg("E-Mail erfolgreich gesendet an: $empfaenger" . ($tooLarge ? ' (ohne Anhang, zu gross)' : ''));

// Protokollierung getrennt vom Versand-try: Ein Protokollfehler (z. B. PDOException,
// die ebenfalls eine RuntimeException ist) darf den bereits erfolgreichen Versand
// nicht mehr als Fehlschlag erscheinen lassen (Befund Runde 2 – sonst Doppelversand,
// da das Lock-File dann nicht geschrieben würde).
if ($protokoll !== null) {
    try {
        $laufId = $protokoll->start('backupmail', $force ? 'manuell' : 'planer', $zyklusKey);
        $protokoll->erfolg($laufId, ['groesseBytes' => $zipSize, 'verschluesselt' => $password !== '', 'ohneAnhang' => $tooLarge]);
    } catch (\Throwable $e) {
        error_log('[cron_backup_email] Protokollierung (Erfolg) fehlgeschlagen: ' . $e->getMessage());
    }
}

// -- Lock-File schreiben (ausser bei --force) --
if (!$force) {
    file_put_contents($lockFile, $zyklusKey);
    logMsg("Lock-File geschrieben: $zyklusKey");
}

logMsg("=== Backup-E-Mail abgeschlossen ===\n");
