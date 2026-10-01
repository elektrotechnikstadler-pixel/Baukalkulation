<?php
// ============================================================
// DIN EN 1090 – Separater API-Endpunkt
// ============================================================
// Nutzt Auth/DB der Hauptapplikation, routet an Din1090Actions.
// ============================================================

// ── Deprecation-Warnungen unterdrücken ───────────────────────
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & E_DEPRECATED) return true;
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('[DIN1090] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['error' => 'Interner Serverfehler.'], JSON_UNESCAPED_UNICODE);
    exit;
});

// ── Composer Autoloader (Hauptapp) ───────────────────────────
// Webroot ist public/, Code und Daten liegen eine Ebene darüber.
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use App\Database;
use App\Auth;

// ── Session (identisch mit api.php) ─────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
ini_set('session.gc_maxlifetime', 2592000);
ini_set('session.cookie_lifetime', 2592000);
session_set_cookie_params([
    'lifetime' => 2592000,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();

// Session-Timeout (Inaktivität) wird nach dem DB-Connect geprüft
// (konfigurierbarer Wert aus den Admin-Einstellungen).

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── CSRF-Schutz: Origin-Header bei POST prüfen (identisch mit api.php) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])) {
    $serverHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?? '';
    if ($originHost !== '' && $serverHost !== '' && $originHost !== $serverHost) {
        $isPrivateOrigin = preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $originHost);
        $isPrivateServer = preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $serverHost);
        if (!$isPrivateOrigin || !$isPrivateServer) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-Origin-Anfrage abgelehnt.']);
            exit;
        }
    }
}

// ── Auth-Check ───────────────────────────────────────────────
if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

// ── Konstanten (benötigt für Database::connect()) ────────────
if (!defined('DATA_DIR')) {
    define('DATA_DIR', rtrim(getenv('BK_DATA_DIR') ?: APP_ROOT . '/data', '/\\') . '/');
}
$_pathsCfg = file_exists(DATA_DIR . 'paths_config.json')
    ? (json_decode(file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
    : [];
if (!defined('BACKUP_DIR'))   define('BACKUP_DIR',   $_pathsCfg['backups']     ?? DATA_DIR . 'backups/');
if (!defined('ARCHIVE_DIR'))  define('ARCHIVE_DIR',  $_pathsCfg['archiv']      ?? DATA_DIR . 'archiv/');
if (!defined('EXPORT_DIR'))   define('EXPORT_DIR',   $_pathsCfg['exports']     ?? DATA_DIR . 'exports/');
if (!defined('TAGEBUCH_DIR')) define('TAGEBUCH_DIR', $_pathsCfg['bautagebuch'] ?? DATA_DIR . 'bautagebuch/');
if (!defined('UPLOADS_DIR'))  define('UPLOADS_DIR',  $_pathsCfg['uploads']     ?? DATA_DIR . 'uploads/');

foreach ([DATA_DIR] as $_d) {
    if (!is_dir($_d) && !@mkdir($_d, 0750, true) && !is_dir($_d)) {
        error_log('[Baukalkulation] Verzeichnis nicht anlegbar: ' . $_d);
    }
}

// ── Wartungsmodus während eines Updates (gleiche Regel wie api.php) ──
if (\App\Services\UpdateAuftrag::wartungSperrt($_SERVER['REQUEST_METHOD'] ?? 'GET', '', [], DATA_DIR . 'update', time())) {
    jsonOut(['error' => \App\Services\UpdateAuftrag::WARTUNG_MELDUNG], 503);
}

// ── DB verbinden ─────────────────────────────────────────────
try {
    $db = Database::connect();
} catch (\App\Database\SchemaTooNewException $e) {
    error_log('[DIN1090] ' . $e->getMessage());
    jsonOut(['error' => $e->getMessage()], 503);
}

// Automatische Abmeldung nach konfigurierbarer Inaktivität (Admin-Einstellungen).
if (!empty($_SESSION['authenticated'])) {
    $sessionTimeout = Auth::sessionTimeoutSeconds($db);
    $lastActivity   = $_SESSION['_last_activity'] ?? 0;
    if ($lastActivity && (time() - $lastActivity > $sessionTimeout)) {
        session_unset();
        session_destroy();
        session_start();
    } else {
        $_SESSION['_last_activity'] = time();
    }
}

// ── Request parsen ───────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?? [];
// Bei GET-Requests sub-Parameter aus Query-String lesen
if (empty($body['sub']) && !empty($_GET['sub'])) {
    $body['sub'] = $_GET['sub'];
}
// Alle GET-Parameter in body mergen (für einfache Aufrufe)
foreach ($_GET as $k => $v) {
    if (!isset($body[$k])) $body[$k] = $v;
}

session_write_close();

// ── Dispatcher ───────────────────────────────────────────────
require_once APP_ROOT . '/modules/din1090/Din1090Actions.php';

$handler = new Din1090Actions($db, $body);
$handler->dispatch();
