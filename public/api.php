<?php
// ============================================================
// Baukalkulation – REST API (SQLite + Framework)
// ============================================================
// Entry-Point / Dispatcher: Initialisiert Session, DB, routet
// alle Aktionen an die passenden Handler-Klassen.
// ============================================================

// ── Anwendungs-Version (SemVer) – einzige Quelle: Datei VERSION ───────────────────────
if (!defined('APP_VERSION')) {
    $_vf = dirname(__DIR__) . '/VERSION';
    define('APP_VERSION', is_file($_vf) ? trim((string)file_get_contents($_vf)) : '0.0.0');
}

// ── Deprecation-Warnungen unterdrücken (PHP 8.4 + Dompdf) ───
error_reporting(E_ALL & ~E_DEPRECATED);

// ── Globaler Error-Handler (JSON statt HTML) ─────────────────
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & E_DEPRECATED) return true; // Deprecations ignorieren
    if ($severity & E_WARNING && str_contains($message, 'session_regenerate_id')) return true;
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // Details ins Logfile UND temporär an Client für Debugging
    $detail = $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[Baukalkulation] ' . $detail);
    echo json_encode(['error' => 'Interner Serverfehler. Bitte Administrator kontaktieren.'], JSON_UNESCAPED_UNICODE);
    exit;
});

// ── Composer Autoloader ──────────────────────────────────────
// Webroot ist public/, Code und Daten liegen eine Ebene darüber.
define('APP_ROOT', dirname(__DIR__));
if (!file_exists(APP_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'vendor/ fehlt. Bitte "composer install" ausführen.']);
    exit;
}
require_once APP_ROOT . '/vendor/autoload.php';

use App\Database;
use App\Auth;

// ── Session Setup ────────────────────────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
// Session-GC-Lifetime auf 30 Tage setzen (Runtime-Fix, greift auch ohne Image-Rebuild).
// Verhindert, dass PHP die Session-Datei nach dem Default von 1440s (~24 Min) löscht.
// Die tatsächliche Abmeldung steuert unsere eigene _last_activity-Logik weiter unten.
ini_set('session.gc_maxlifetime', 2592000);
ini_set('session.cookie_lifetime', 2592000);
session_set_cookie_params([
    // Cookie-Lebensdauer großzügig (30 Tage). Die tatsächliche automatische
    // Abmeldung steuert die serverseitige Inaktivitätsprüfung weiter unten
    // (konfigurierbar in den Admin-Einstellungen), sobald die DB verfügbar ist.
    'lifetime' => 2592000,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly'  => true,
    'samesite' => 'Lax',
]);
session_start();

// Automatische Abmeldung: Die Inaktivitätsprüfung erfolgt weiter unten nach dem
// DB-Connect, da der Timeout (Minuten) aus den Admin-Einstellungen gelesen wird.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── CSRF-Schutz: Origin-Header bei POST-Requests prüfen ──────
// SameSite=Lax (gesetzt bei Session-Cookie) ist der primäre CSRF-Schutz.
// Zusätzlich: Origin-Header prüfen falls vorhanden.
// Ausnahme: oci_hook empfängt legitime Cross-Origin-POSTs vom Lieferanten-Browser.
$_csrfExemptActions = ['oci_hook'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_ORIGIN'])
    && !in_array($_GET['action'] ?? '', $_csrfExemptActions, true)) {
    $serverHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) ?? '';
    // Nur blockieren wenn beide gesetzt UND unterschiedlich sind
    // Lokale/private IPs und gleichen Hostnamen durchlassen
    if ($originHost !== '' && $serverHost !== '' && $originHost !== $serverHost) {
        // Prüfen ob beides private/lokale Adressen sind (VPN-Szenario)
        $isPrivateOrigin = preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $originHost);
        $isPrivateServer = preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $serverHost);
        if (!$isPrivateOrigin || !$isPrivateServer) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-Origin-Anfrage abgelehnt.']);
            exit;
        }
    }
}

// ── Konstanten ───────────────────────────────────────────────
// BK_DATA_DIR erlaubt ein abweichendes Datenverzeichnis (z. B. für Tests).
define('DATA_DIR', rtrim(getenv('BK_DATA_DIR') ?: APP_ROOT . '/data', '/\\') . '/');

$_pathsCfg = file_exists(DATA_DIR . 'paths_config.json')
    ? (json_decode(file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
    : [];

define('BACKUP_DIR',   $_pathsCfg['backups']     ?? DATA_DIR . 'backups/');
define('ARCHIVE_DIR',  $_pathsCfg['archiv']      ?? DATA_DIR . 'archiv/');
define('EXPORT_DIR',   $_pathsCfg['exports']     ?? DATA_DIR . 'exports/');
define('TAGEBUCH_DIR', $_pathsCfg['bautagebuch'] ?? DATA_DIR . 'bautagebuch/');
define('UPLOADS_DIR',  $_pathsCfg['uploads']     ?? DATA_DIR . 'uploads/');
define('DATANORM_DIR', $_pathsCfg['datanorm']    ?? DATA_DIR . 'datanorm/');
define('BACKUP_MAX', 7);

foreach ([DATA_DIR, BACKUP_DIR, ARCHIVE_DIR, EXPORT_DIR, TAGEBUCH_DIR, UPLOADS_DIR, DATANORM_DIR] as $_d) {
    if (!is_dir($_d)) @mkdir($_d, 0750, true);
}

// ── Datenbankverbindung ──────────────────────────────────────
try {
    $db = Database::connect();
} catch (\App\Database\SchemaTooNewException $e) {
    error_log('[Baukalkulation] ' . $e->getMessage());
    jsonOut(['error' => $e->getMessage()], 503);
}

// ── Systemadmin sicherstellen ────────────────────────────────
Auth::ensureSystemadmin($db);

// Automatische Abmeldung nach konfigurierbarer Inaktivität.
// Timeout (Minuten) stammt aus den Admin-Einstellungen; Standard 8 Stunden.
// Requests mit &_idle=1 (z.B. Polling) aktualisieren _last_activity NICHT,
// damit Hintergrund-Polls den Timeout nicht permanent zurücksetzen.
if (!empty($_SESSION['authenticated'])) {
    $sessionTimeout = Auth::sessionTimeoutSeconds($db);
    $lastActivity   = $_SESSION['_last_activity'] ?? 0;
    if ($lastActivity && (time() - $lastActivity > $sessionTimeout)) {
        session_unset();
        session_destroy();
        session_start();
    } else {
        $isIdlePoll = !empty($_GET['_idle']) || !empty($_POST['_idle']);
        if (!$isIdlePoll) {
            $_SESSION['_last_activity'] = time();
        }
    }
}

// ── ModuleLoader (v2.0.0) ────────────────────────────────────
// Lädt modules/<name>/module.json, führt deren Migrationen aus und
// stellt die Aktionen ?action=list_modules / ?action=module bereit.
// Bestehende Routen + din1090_api.php bleiben parallel verfügbar.
if (!defined('MODULES_DIR')) {
    define('MODULES_DIR', APP_ROOT . '/modules/');
}
try {
    $moduleLoader = new \App\Core\ModuleLoader($db);
    $moduleLoader->migrateAll();
} catch (\Throwable $e) {
    error_log('[ModuleLoader] Initialisierung fehlgeschlagen: ' . $e->getMessage());
    $moduleLoader = null;
}

// ── Request parsen ───────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Wartungsmodus während eines Updates (Marker vom Updater-Sidecar) ──
if (\App\Services\UpdateAuftrag::wartungSperrt(
    $_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($action) ? $action : '', ['check', 'logout', 'update_status'], DATA_DIR . 'update', time()
)) {
    jsonOut(['error' => \App\Services\UpdateAuftrag::WARTUNG_MELDUNG], 503);
}

// ── Session-Lock für Nicht-Session-Aktionen früh freigeben ──
$sessionWriteActions = ['check', 'setup', 'login', 'logout'];
if (!in_array($action, $sessionWriteActions, true)) {
    session_write_close();
}

// ── Feature-Flags (für Frontend-Gating) ──────────────────────
// Liefert, welche optionalen Module im aktuellen Build aktiv sind.
// Keine Auth nötig, damit Login-Seite ggf. WhatsApp-/OCR-Hinweise anpassen kann.
if ($action === 'features') {
    jsonOut([
        'ok'       => true,
        'features' => [
            'ocr'       => feature_enabled('ocr'),
            'pdf'       => feature_enabled('pdf'),
            'pdfparser' => feature_enabled('pdfparser'),
            'zugferd'   => feature_enabled('zugferd'),
            'din1090'   => feature_enabled('din1090'),
            'whatsapp'  => feature_enabled('whatsapp'),
            'hicad_lib' => feature_enabled('hicad_lib'),
        ],
    ]);
}

// ── Modul-System (v2.0.0) ────────────────────────────────────
// list_modules: liefert dem Frontend die für den User sichtbaren
// Module inkl. Asset-Pfade. module: leitet an den ModuleLoader.
if ($action === 'list_modules') {
    Auth::requireAuth();
    if (!$moduleLoader) jsonOut(['ok' => true, 'modules' => []]);
    jsonOut(['ok' => true, 'modules' => $moduleLoader->listForFrontend()]);
}
if ($action === 'module') {
    if (!$moduleLoader) jsonOut(['error' => 'Modul-System nicht verfügbar.'], 500);
    $modName = $_GET['module'] ?? ($body['module'] ?? '');
    $sub     = $_GET['sub']    ?? ($body['sub']    ?? ($body['action'] ?? ''));
    if ($modName === '' || $sub === '') {
        jsonOut(['error' => "Parameter 'module' und 'sub' erforderlich."], 400);
    }
    $moduleLoader->dispatch($modName, $sub, $body);
    exit;
}

// ── Route Map ────────────────────────────────────────────────
$routes = [
    // Auth
    'check'                        => ['App\\Handlers\\AuthActions',           'check'],
    'setup'                        => ['App\\Handlers\\AuthActions',           'setup'],
    'login'                        => ['App\\Handlers\\AuthActions',           'login'],
    'logout'                       => ['App\\Handlers\\AuthActions',           'logout'],
    'change_password'              => ['App\\Handlers\\AuthActions',           'changePassword'],
    // Benutzerverwaltung
    'add_user'                     => ['App\\Handlers\\UserActions',           'addUser'],
    'delete_user'                  => ['App\\Handlers\\UserActions',           'deleteUser'],
    'list_users'                   => ['App\\Handlers\\UserActions',           'listUsers'],
    'list_users_basic'             => ['App\\Handlers\\UserActions',           'listUsersBasic'],
    'set_role'                     => ['App\\Handlers\\UserActions',           'setRole'],
    'set_kuerzel'                  => ['App\\Handlers\\UserActions',           'setKuerzel'],
    'set_personalnummer'           => ['App\\Handlers\\UserActions',           'setPersonalnummer'],
    'set_visibility'               => ['App\\Handlers\\UserActions',           'setVisibility'],
    'set_show_in_zeitverwaltung'   => ['App\\Handlers\\UserActions',           'setShowInZeitverwaltung'],
    'set_show_in_wochenplanung'    => ['App\\Handlers\\UserActions',           'setShowInWochenplanung'],
    'admin_reset_password'         => ['App\\Handlers\\UserActions',           'adminResetPassword'],
    'unlock_user'                  => ['App\\Handlers\\UserActions',           'unlockUser'],
    'set_subunternehmer'           => ['App\\Handlers\\UserActions',           'setSubunternehmer'],
    'set_stunden_kategorie'        => ['App\\Handlers\\UserActions',           'setStundenKategorie'],
    'set_theme'                    => ['App\\Handlers\\UserActions',           'setTheme'],
    'get_user_profile'             => ['App\\Handlers\\UserActions',           'getUserProfile'],
    'save_user_profile'            => ['App\\Handlers\\UserActions',           'saveUserProfile'],

    // Hauptdaten (Baustellen + Kataloge)
    'load'                         => ['App\\Handlers\\DataActions',           'load'],
    'save'                         => ['App\\Handlers\\DataActions',           'save'],
    'project_heartbeat'            => ['App\\Handlers\\DataActions',           'projectHeartbeat'],
    'project_presence'             => ['App\\Handlers\\DataActions',           'projectPresence'],
    'backups'                      => ['App\\Handlers\\DataActions',           'listBackups'],
    'restore'                      => ['App\\Handlers\\DataActions',           'restore'],
    'backup_create'                => ['App\\Handlers\\DataActions',           'backupCreate'],
    'backup_download'              => ['App\\Handlers\\DataActions',           'backupDownload'],
    'backup_upload'                => ['App\\Handlers\\DataActions',           'backupUpload'],
    'backup_upload_replace'        => ['App\\Handlers\\DataActions',           'backupUploadReplace'],

    // E-Mail-Versand
    'send_vde'                     => ['App\\Handlers\\EmailActions',          'sendVde'],
    'send_rechnung'                => ['App\\Handlers\\EmailActions',          'sendRechnung'],
    'send_angebot'                 => ['App\\Handlers\\EmailActions',          'sendAngebot'],
    'send_din1090'                 => ['App\\Handlers\\EmailActions',          'sendDin1090'],
    'test_smtp'                    => ['App\\Handlers\\EmailActions',          'testSmtp'],

    // Baustellen-Operationen
    'archive_baustelle'            => ['App\\Handlers\\BaustelleActions',      'archive'],
    'list_archive'                 => ['App\\Handlers\\BaustelleActions',      'listArchive'],
    'search_archive'               => ['App\\Handlers\\BaustelleActions',      'searchArchive'],
    'view_archive'                 => ['App\\Handlers\\BaustelleActions',      'viewArchive'],
    'download_archive_excel'       => ['App\\Handlers\\BaustelleActions',      'downloadArchiveExcel'],
    'download_archive_json'        => ['App\\Handlers\\BaustelleActions',      'downloadArchiveJson'],
    'download_archive_all'         => ['App\\Handlers\\BaustelleActions',      'downloadArchiveAll'],
    'upload_archive_json'          => ['App\\Handlers\\BaustelleActions',      'uploadArchiveJson'],
    'upload_archive_all'           => ['App\\Handlers\\BaustelleActions',      'uploadArchiveAll'],
    'reimport_baustelle'           => ['App\\Handlers\\BaustelleActions',      'reimport'],
    'repair_archiv_projekte'       => ['App\\Handlers\\BaustelleActions',      'repairArchivProjekte'],
    'delete_archive'               => ['App\\Handlers\\BaustelleActions',      'deleteArchive'],
    'clear_archive'                => ['App\\Handlers\\BaustelleActions',      'clearArchive'],
    'mark_ordered'                 => ['App\\Handlers\\BaustelleActions',      'markOrdered'],
    'archive_offenes_material'     => ['App\\Handlers\\BaustelleActions',      'archiveOffenesMaterial'],
    'load_auswertung_archives'     => ['App\\Handlers\\BaustelleActions',      'loadAuswertungArchives'],
    'toggle_auswertung_flag'       => ['App\\Handlers\\BaustelleActions',      'toggleAuswertungFlag'],
    'save_sub_sort_order'          => ['App\\Handlers\\BaustelleActions',      'saveSubSortOrder'],
    'move_position'                => ['App\\Handlers\\BaustelleActions',      'movePosition'],

    // Zeiterfassung
    'save_zeiterfassung'           => ['App\\Handlers\\ZeiterfassungActions',  'save'],
    'load_zeiterfassung'           => ['App\\Handlers\\ZeiterfassungActions',  'load'],
    'load_all_zeiterfassung'       => ['App\\Handlers\\ZeiterfassungActions',  'loadAll'],
    'reconcile_bookings'           => ['App\\Handlers\\ZeiterfassungActions',  'reconcileBookings'],
    'diagnose_fehlbuchungen'       => ['App\\Handlers\\ZeiterfassungActions',  'diagnoseFehlbuchungen'],
    'get_zeiterfassung_historie'   => ['App\\Handlers\\ZeiterfassungActions',  'getHistorie'],
    'repair_booking'               => ['App\\Handlers\\ZeiterfassungActions',  'repairBooking'],
    'repair_bookings_bulk'         => ['App\\Handlers\\ZeiterfassungActions',  'repairBookingsBulk'],
    'set_sollstunden'              => ['App\\Handlers\\ZeiterfassungActions',  'setSollstunden'],
    'get_sollstunden'              => ['App\\Handlers\\ZeiterfassungActions',  'getSollstunden'],
    'set_sollstunden_tag'          => ['App\\Handlers\\ZeiterfassungActions',  'setSollstundenTag'],
    'set_soll_tage_woche'          => ['App\\Handlers\\ZeiterfassungActions',  'setSollTageWoche'],
    'set_urlaubstage'              => ['App\\Handlers\\ZeiterfassungActions',  'setUrlaubstage'],
    'get_sollstunden_extended'     => ['App\\Handlers\\ZeiterfassungActions',  'getSollstundenExtended'],
    'auto_import_stunden'          => ['App\\Handlers\\ZeiterfassungActions',  'autoImport'],
    'get_user_sollstunden'         => ['App\\Handlers\\ZeiterfassungActions',  'getUserSollstunden'],
    'get_gleitzeitkonto_buchungen' => ['App\\Handlers\\ZeiterfassungActions',  'getGleitzeitBuchungen'],
    'save_gleitzeitkonto_buchung'  => ['App\\Handlers\\ZeiterfassungActions',  'saveGleitzeitBuchung'],
    'get_jahreswechsel_data'       => ['App\\Handlers\\ZeiterfassungActions',  'getJahreswechselData'],
    'admin_edit_zeiterfassung'     => ['App\\Handlers\\ZeiterfassungActions',  'adminEditEntry'],
    'admin_delete_zeiterfassung'   => ['App\\Handlers\\ZeiterfassungActions',  'adminDeleteEntry'],
    'admin_add_zeiterfassung'      => ['App\\Handlers\\ZeiterfassungActions',  'adminAddEntry'],
    'load_wochenpruefung'          => ['App\\Handlers\\ZeiterfassungActions',  'loadWochenpruefung'],
    'save_wochenpruefung'          => ['App\\Handlers\\ZeiterfassungActions',  'saveWochenpruefung'],
    'load_tagespruefung'           => ['App\\Handlers\\ZeiterfassungActions',  'loadTagespruefung'],
    'save_tagespruefung'           => ['App\\Handlers\\ZeiterfassungActions',  'saveTagespruefung'],

    // Wochenplanung
    'save_wochenplanung'           => ['App\\Handlers\\PlanActions',           'saveWochenplanung'],
    'load_wochenplanung'           => ['App\\Handlers\\PlanActions',           'loadWochenplanung'],
    'load_wochenplanung_display'   => ['App\\Handlers\\PlanActions',           'loadWochenplanungDisplay'],
    'get_wochenplan_cal_token'     => ['App\\Handlers\\PlanActions',           'getCalToken'],
    'export_wochenplan_ical'       => ['App\\Handlers\\PlanActions',           'exportIcal'],

    // Schnellnotizen
    'load_schnellnotizen'          => ['App\\Handlers\\PlanActions',           'loadSchnellnotizen'],
    'save_schnellnotiz'            => ['App\\Handlers\\PlanActions',           'saveSchnellnotiz'],
    'archive_schnellnotiz'         => ['App\\Handlers\\PlanActions',           'archiveSchnellnotiz'],
    'archive_all_schnellnotizen'   => ['App\\Handlers\\PlanActions',           'archiveAllSchnellnotizen'],
    'delete_schnellnotiz'          => ['App\\Handlers\\PlanActions',           'deleteSchnellnotiz'],

    // Termine
    'load_termine'                 => ['App\\Handlers\\TerminActions',         'loadTermine'],
    'save_termin'                  => ['App\\Handlers\\TerminActions',         'saveTermin'],
    'delete_termin'                => ['App\\Handlers\\TerminActions',         'deleteTermin'],

    // Dashboard (v2.4)
    'load_dashboard'               => ['App\\Handlers\\DashboardActions',      'load'],
    'save_dashboard_item'          => ['App\\Handlers\\DashboardActions',      'saveItem'],
    'delete_dashboard_item'        => ['App\\Handlers\\DashboardActions',      'deleteItem'],
    'update_dashboard_item_status' => ['App\\Handlers\\DashboardActions',      'updateStatus'],
    'update_dashboard_sort'         => ['App\\Handlers\\DashboardActions',      'updateSort'],

    // Gruppen (v2.10.0)
    'load_gruppen'                 => ['App\\Handlers\\GruppenActions',         'load'],
    'load_gruppen_for_user'        => ['App\\Handlers\\GruppenActions',         'loadForUser'],
    'save_gruppe'                  => ['App\\Handlers\\GruppenActions',         'save'],
    'delete_gruppe'                => ['App\\Handlers\\GruppenActions',         'delete'],

    // Kunden
    'load_kunden'                  => ['App\\Handlers\\CrudActions',           'loadKunden'],
    'save_kunde'                   => ['App\\Handlers\\CrudActions',           'saveKunde'],
    'delete_kunde'                 => ['App\\Handlers\\CrudActions',           'deleteKunde'],
    'export_kunde'                 => ['App\\Handlers\\CrudActions',           'exportKunde'],
    'dsgvo_delete_kunde'           => ['App\\Handlers\\CrudActions',           'dsgvoDeleteKunde'],

    // Dienstleister
    'list_dienstleister'           => ['App\\Handlers\\CrudActions',           'listDienstleister'],
    'add_dienstleister'            => ['App\\Handlers\\CrudActions',           'addDienstleister'],
    'edit_dienstleister'           => ['App\\Handlers\\CrudActions',           'editDienstleister'],
    'delete_dienstleister'         => ['App\\Handlers\\CrudActions',           'deleteDienstleister'],

    // Rechnungen & Angebote
    'list_rechnungen'              => ['App\\Handlers\\CrudActions',           'listRechnungen'],
    'save_rechnung'                => ['App\\Handlers\\CrudActions',           'saveRechnung'],
    'delete_rechnung'              => ['App\\Handlers\\CrudActions',           'deleteRechnung'],

    // Dateiverwaltung
    'upload_file'                  => ['App\\Handlers\\FileActions',           'uploadFile'],
    'list_files'                   => ['App\\Handlers\\FileActions',           'listFiles'],
    'download_file'                => ['App\\Handlers\\FileActions',           'downloadFile'],
    'delete_file'                  => ['App\\Handlers\\FileActions',           'deleteFile'],
    'rename_file'                  => ['App\\Handlers\\FileActions',           'renameFile'],
    'move_file'                    => ['App\\Handlers\\FileActions',           'moveFile'],
    'save_generated_file'          => ['App\\Handlers\\FileActions',           'saveGeneratedFile'],
    'save_tagebuch_export'         => ['App\\Handlers\\FileActions',           'saveTagesbuchExport'],
    'save_weekly_export'           => ['App\\Handlers\\FileActions',           'saveWeeklyExport'],
    'save_tagesbericht'            => ['App\\Handlers\\FileActions',           'saveTagesbericht'],

    // Admin / Einstellungen
    'get_permissions'              => ['App\\Handlers\\AdminActions',          'getPermissions'],
    'set_permissions'              => ['App\\Handlers\\AdminActions',          'setPermissions'],
    'load_settings'                => ['App\\Handlers\\AdminActions',          'loadSettingsAction'],
    'save_settings'                => ['App\\Handlers\\AdminActions',          'saveSettingsAction'],
    'upload_logo'                  => ['App\\Handlers\\AdminActions',          'uploadLogo'],
    'get_logo'                     => ['App\\Handlers\\AdminActions',          'getLogo'],
    'get_storage_paths'            => ['App\\Handlers\\AdminActions',          'getStoragePaths'],
    'save_storage_paths'           => ['App\\Handlers\\AdminActions',          'saveStoragePaths'],
    'browse_directory'             => ['App\\Handlers\\AdminActions',          'browseDirectory'],
    'download_audit_log'           => ['App\\Handlers\\AdminActions',          'downloadAuditLog'],
    'system_info'                  => ['App\\Handlers\\AdminActions',          'systemInfo'],
    'update_check'                 => ['App\\Handlers\\AdminActions',          'updateCheck'],
    'update_start'                 => ['App\\Handlers\\AdminActions',          'updateStart'],
    'update_status'                => ['App\\Handlers\\AdminActions',          'updateStatus'],

    // Erinnerungen
    'erinnerung_log'               => ['App\\Handlers\\AdminActions',          'erinnerungLog'],
    'trigger_erinnerung'           => ['App\\Handlers\\AdminActions',          'triggerErinnerung'],
    'trigger_material_erinnerung'  => ['App\\Handlers\\AdminActions',          'triggerMaterialErinnerung'],
    'trigger_backup_email'         => ['App\\Handlers\\AdminActions',          'triggerBackupEmail'],
    'trigger_stundenauswertung_email' => ['App\\Handlers\\AdminActions',       'triggerStundenauswertungEmail'],
    'load_erinnerung_settings'     => ['App\\Handlers\\AdminActions',          'loadErinnerungSettings'],
    'save_erinnerung_settings'     => ['App\\Handlers\\AdminActions',          'saveErinnerungSettings'],

    // Katalog / Datanorm / Metallzuschlag
    'datanorm_reindex'             => ['App\\Handlers\\CatalogActions',        'datanormReindex'],
    'datanorm_search'              => ['App\\Handlers\\CatalogActions',        'datanormSearch'],
    'datanorm_status'              => ['App\\Handlers\\CatalogActions',        'datanormStatus'],
    'datanorm_upload'              => ['App\\Handlers\\CatalogActions',        'datanormUpload'],
    'datanorm_clear'               => ['App\\Handlers\\CatalogActions',        'datanormClear'],
    'metallzuschlag_get'           => ['App\\Handlers\\CatalogActions',        'metallzuschlagGet'],
    'metallzuschlag_set'           => ['App\\Handlers\\CatalogActions',        'metallzuschlagSet'],
    'metallzuschlag_auto_fetch'    => ['App\\Handlers\\CatalogActions',        'metallzuschlagAutoFetch'],
    'metallprofile_catalog'        => ['App\\Handlers\\CatalogActions',        'metallprofileCatalog'],
    'metallprofile_auto_update'    => ['App\\Handlers\\CatalogActions',        'metallprofileAutoUpdate'],
    'hicad_list'                   => ['App\\Handlers\\CatalogActions',        'hicadList'],
    'hicad_import'                 => ['App\\Handlers\\CatalogActions',        'hicadImport'],
    'material_top_used'            => ['App\\Handlers\\CatalogActions',        'materialTopUsed'],
    'ai_scan_material'             => ['App\\Handlers\\AiActions',             'scanMaterial'],

    // Export
    'export_inform_csv'            => ['App\\Handlers\\ExportActions',         'exportInformCsv'],
    'subunternehmer_report'        => ['App\\Handlers\\ExportActions',         'subunternehmerReport'],
    'generate_zugferd'             => ['App\\Handlers\\ExportActions',         'generateZugferd'],
    'render_beleg_pdf'             => ['App\\Handlers\\ExportActions',         'renderBelegPdf'],

    // OCI Punchout (v2.6.9)
    'oci_list_lieferanten'         => ['App\\Handlers\\OciActions',            'listLieferanten'],
    'oci_save_lieferant'           => ['App\\Handlers\\OciActions',            'saveLieferant'],
    'oci_delete_lieferant'         => ['App\\Handlers\\OciActions',            'deleteLieferant'],
    'oci_prepare'                  => ['App\\Handlers\\OciActions',            'prepare'],
    'oci_start'                    => ['App\\Handlers\\OciActions',            'start'],
    'oci_hook'                     => ['App\\Handlers\\OciActions',            'hook'],
];

// ── Dispatch ─────────────────────────────────────────────────
if (isset($routes[$action])) {
    // ── Erzwungener Passwort-Wechsel beim ersten Login ──
    // Wenn der angemeldete User mustChangePassword=1 hat, sind nur
    // wenige Aktionen erlaubt, bis das Passwort geändert wurde.
    $allowedDuringPwChange = ['check', 'logout', 'change_password', 'features'];
    if (!empty($_SESSION['authenticated']) && !in_array($action, $allowedDuringPwChange, true)) {
        $stmt = $db->prepare("SELECT mustChangePassword FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username'] ?? '']);
        $mustChange = (int)$stmt->fetchColumn() === 1;
        // Offenes Statement blockiert sonst WAL-Checkpoint/VACUUM im Handler.
        unset($stmt);
        if ($mustChange) {
            jsonOut(['error' => 'Bitte zuerst das Passwort ändern.', 'mustChangePassword' => true], 403);
        }
    }

    [$class, $method] = $routes[$action];
    // Klasse stammt aus der festen Routing-Tabelle, nie direkt aus der Anfrage.
    (new $class($db, $body))->$method(); // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
} else {
    jsonOut(['error' => 'Unbekannte Aktion: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 400);
}
