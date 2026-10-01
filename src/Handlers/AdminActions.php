<?php
namespace App\Handlers;

use App\Auth;
use App\Database;
use App\Services\AuditService;
use App\Services\FirmenLogo;
use App\Services\SystemInfo;
use App\Services\UpdateAuftrag;
use App\Services\UpdatePruefung;

class AdminActions
{
    public function __construct(private \PDO $db, private array $body) {}

    // ── Berechtigungen ───────────────────────────────────────
    public function getPermissions(): void
    {
        Auth::requireAuth();
        jsonOut(['ok' => true, 'permissions' => Auth::loadPermissions($this->db)]);
    }

    public function setPermissions(): void
    {
        Auth::requireRole('admin');
        $newPerms = $this->body['permissions'] ?? null;
        if (!$newPerms) jsonOut(['error' => 'Keine Daten übermittelt.'], 400);
        Auth::savePermissions($this->db, $newPerms);
        AuditService::log('permissions_change', 'Berechtigungen aktualisiert');
        jsonOut(['ok' => true]);
    }

    // ── Einstellungen ────────────────────────────────────────
    public function loadSettingsAction(): void
    {
        Auth::requireRole('admin', 'master');
        jsonOut(['ok' => true, 'settings' => Auth::publicSettings(Auth::loadSettings($this->db))]);
    }

    public function saveSettingsAction(): void
    {
        Auth::requireRole('admin');
        if (array_key_exists('firma_logo_url', $this->body)) {
            $logo = $this->body['firma_logo_url'];
            if (($logo !== null && !is_string($logo)) || !FirmenLogo::isValidSetting(trim((string)$logo))) {
                jsonOut(['error' => 'Ungültiger Logo-Pfad.'], 400);
            }
        }
        $current = Auth::loadSettings($this->db);
        $allowed = array_keys($current);
        foreach ($allowed as $key) {
            if (array_key_exists($key, $this->body)) {
                if (is_bool($current[$key])) {
                    $current[$key] = (bool)$this->body[$key];
                } elseif (is_array($current[$key])) {
                    $current[$key] = is_array($this->body[$key]) ? $this->body[$key] : [];
                } else {
                    $current[$key] = trim((string)$this->body[$key]);
                }
            }
        }
        // Logo-Dateien löschen wenn firma_logo_url geleert wird
        if (array_key_exists('firma_logo_url', $this->body) && $current['firma_logo_url'] === '') {
            foreach (glob(DATA_DIR . 'firma_logo.*') as $old) @unlink($old);
        }

        Auth::saveSettings($this->db, $current);
        AuditService::log('settings_change', 'Einstellungen aktualisiert: ' . implode(', ', array_keys(array_intersect_key($this->body, $current))));
        jsonOut(['ok' => true]);
    }

    // ── Logo-Upload ──────────────────────────────────────────
    public function uploadLogo(): void
    {
        Auth::requireRole('admin');
        if (empty($_FILES['logo'])) jsonOut(['error' => 'Keine Datei.'], 400);
        $file = $_FILES['logo'];
        // SVG absichtlich ausgeschlossen: SVG-Dateien können JavaScript enthalten
        // und würden beim direkten Aufruf der get_logo-URL XSS ermöglichen.
        $allowed = ['image/png','image/jpeg','image/gif','image/webp'];
        // MIME-Type ermitteln (Fallback falls ext-fileinfo fehlt)
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $mime = $mimeMap[$ext] ?? '';
        }
        if (!in_array($mime, $allowed)) jsonOut(['error' => 'Nur Bilder (PNG, JPG, GIF, WebP). SVG wird aus Sicherheitsgründen nicht unterstützt.'], 400);
        if ($file['size'] > 2 * 1024 * 1024) jsonOut(['error' => 'Max. 2 MB.'], 400);

        // Alte Logos entfernen
        foreach (glob(DATA_DIR . 'firma_logo.*') as $old) @unlink($old);

        // Raster-Bilder mit GD optimieren (max 600px breit, als PNG speichern)
        if ($mime !== 'image/svg+xml' && function_exists('imagecreatefromstring')) {
            $imgData = file_get_contents($file['tmp_name']);
            $src = @imagecreatefromstring($imgData);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                $maxW = 600;
                if ($w > $maxW) {
                    $newH = (int)round($h * $maxW / $w);
                    $dst = imagecreatetruecolor($maxW, $newH);
                    // Transparenz erhalten
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefill($dst, 0, 0, $transparent);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $newH, $w, $h);
                    imagedestroy($src);
                    $src = $dst;
                }
                $dest = DATA_DIR . 'firma_logo.png';
                imagepng($src, $dest, 6);
                imagedestroy($src);
                $logoUrl = 'data/firma_logo.png';
                $settings = Auth::loadSettings($this->db);
                $settings['firma_logo_url'] = $logoUrl;
                Auth::saveSettings($this->db, $settings);
                jsonOut(['ok' => true, 'url' => $logoUrl]);
                return;
            }
        }

        // Fallback: Datei direkt speichern (SVG oder wenn GD fehlt)
        $ext = match($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp', default => 'png'
        };
        $dest = DATA_DIR . 'firma_logo.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dest)) jsonOut(['error' => 'Upload fehlgeschlagen.'], 500);
        $logoUrl = 'data/firma_logo.' . $ext;
        $settings = Auth::loadSettings($this->db);
        $settings['firma_logo_url'] = $logoUrl;
        Auth::saveSettings($this->db, $settings);
        jsonOut(['ok' => true, 'url' => $logoUrl]);
    }

    // ── Logo ausliefern (umgeht nginx-Blockade auf data/) ─────
    public function getLogo(): void
    {
        $logo = FirmenLogo::resolve(Auth::loadSettings($this->db));
        if ($logo === null) { http_response_code(404); exit; }
        header('Content-Type: ' . $logo['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($logo['path']));
        readfile($logo['path']);
        exit;
    }

    // ── Speicherpfade ────────────────────────────────────────
    public function getStoragePaths(): void
    {
        Auth::requireRole('admin');
        $dirs = ['Daten' => DATA_DIR, 'Backups' => BACKUP_DIR, 'Exporte' => EXPORT_DIR, 'Bautagebuch' => TAGEBUCH_DIR, 'Uploads' => UPLOADS_DIR, 'Archiv' => ARCHIVE_DIR, 'Datanorm' => defined('DATANORM_DIR') ? DATANORM_DIR : (dirname(__DIR__, 2) . '/Datanorm/')];
        $paths = [];
        foreach ($dirs as $label => $dir) {
            $real = realpath($dir) ?: $dir;
            $size = 0;
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it as $f) $size += $f->getSize();
            }
            $paths[$label] = ['path' => $real, 'size' => $size > 1048576 ? round($size/1048576,1).' MB' : round($size/1024,1).' KB'];
        }
        jsonOut(['ok' => true, 'paths' => $paths]);
    }

    public function saveStoragePaths(): void
    {
        Auth::requireRole('admin');
        $newPaths = $this->body['paths'] ?? [];
        $allowed  = ['backups','archiv','exports','bautagebuch','uploads','datanorm'];
        $cfg = [];
        foreach ($allowed as $key) {
            if (!empty($newPaths[$key]) && is_string($newPaths[$key])) {
                $p = rtrim($newPaths[$key], '/\\') . '/';
                if ($key === 'datanorm') {
                    // Datanorm-Verzeichnis muss existieren, wird nicht angelegt
                    if (!is_dir($p)) jsonOut(['error' => "Datanorm-Verzeichnis '$p' existiert nicht."], 400);
                    $cfg[$key] = $p;
                } else {
                    if (!is_dir($p)) @mkdir($p, 0755, true);
                    if (is_dir($p)) $cfg[$key] = $p;
                    else jsonOut(['error' => "Verzeichnis '$p' konnte nicht erstellt werden."], 400);
                }
            }
        }
        // Pfade als JSON in Data-Dir speichern (Legacy-Kompatibilität)
        file_put_contents(DATA_DIR . 'paths_config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        jsonOut(['ok' => true]);
    }

    public function browseDirectory(): void
    {
        Auth::requireRole('admin');
        $requestedPath = $this->body['path'] ?? '/';
        $realPath = realpath($requestedPath);
        if ($realPath === false || !is_dir($realPath)) jsonOut(['error' => 'Verzeichnis nicht gefunden.'], 404);

        // Nur bestimmte Basispfade erlauben (kein Zugriff auf gesamtes Dateisystem)
        $allowedBases = [realpath(DATA_DIR), realpath(BACKUP_DIR), realpath(ARCHIVE_DIR)];
        if (defined('EXPORT_DIR') && realpath(EXPORT_DIR)) $allowedBases[] = realpath(EXPORT_DIR);
        if (defined('DATANORM_DIR') && realpath(DATANORM_DIR)) $allowedBases[] = realpath(DATANORM_DIR);
        // App-Root erlauben (für relative Datanorm-Pfade)
        $allowedBases[] = realpath(dirname(__DIR__, 2));
        // Volume-Root (Synology NAS) erlauben
        $allowedBases[] = '/volume1';
        $allowedBases[] = '/volume2';
        $allowedBases = array_filter($allowedBases);
        $isAllowed = false;
        foreach ($allowedBases as $base) {
            if (str_starts_with($realPath, $base)) { $isAllowed = true; break; }
        }
        if (!$isAllowed) jsonOut(['error' => 'Zugriff auf dieses Verzeichnis nicht erlaubt.'], 403);

        $entries = [];
        $items = @scandir($realPath);
        if ($items === false) jsonOut(['error' => 'Verzeichnis nicht lesbar.'], 403);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($realPath . DIRECTORY_SEPARATOR . $item)) $entries[] = $item;
        }
        sort($entries, SORT_STRING | SORT_FLAG_CASE);
        jsonOut(['ok' => true, 'current' => $realPath, 'dirs' => $entries]);
    }

    // ── Audit-Log Download ────────────────────────────────────
    public function downloadAuditLog(): void
    {
        Auth::requireRole('admin', 'master');
        $format = trim($this->body['format'] ?? 'csv');
        if (!in_array($format, ['csv', 'txt'], true)) $format = 'csv';

        $rows = $this->db->query(
            "SELECT ts, username, action, details FROM audit_log ORDER BY id ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['zeit'] = date('Y-m-d H:i:s', (int)$r['ts']);
        }
        unset($r);

        $filename = 'audit_log_' . date('Y-m-d_His') . '.' . $format;
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');
        header('X-Content-Type-Options: nosniff');

        if ($format === 'csv') {
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM für Excel
            fputcsv($out, ['Zeitpunkt', 'Benutzer', 'Aktion', 'Details'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['zeit'], $r['username'], $r['action'], $r['details']], ';');
            }
            fclose($out);
        } else {
            foreach ($rows as $r) {
                echo $r['zeit'] . ' | ' . $r['username'] . ' | ' . $r['action'] . ' | ' . $r['details'] . "\n";
            }
        }
        exit;
    }

    // ── Erinnerungen ─────────────────────────────────────────
    public function erinnerungLog(): void
    {
        Auth::requireRole('admin', 'master');
        $logFile = DATA_DIR . 'erinnerung.log';
        $log = file_exists($logFile) ? file_get_contents($logFile) : '';
        jsonOut(['ok' => true, 'log' => $log]);
    }

    public function triggerErinnerung(): void
    {
        Auth::requireRole('admin');
        $output = []; $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../../cron_stunden_erinnerung.php') . ' 2>&1', $output, $code);
        jsonOut(['ok' => $code === 0, 'output' => implode("\n", $output)]);
    }

    public function triggerMaterialErinnerung(): void
    {
        Auth::requireRole('admin');
        $output = []; $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../../cron_material_erinnerung.php') . ' 2>&1', $output, $code);
        jsonOut(['ok' => $code === 0, 'output' => implode("\n", $output)]);
    }

    public function triggerBackupEmail(): void
    {
        Auth::requireRole('admin');

        $cronPath = realpath(__DIR__ . '/../../cron_backup_email.php');
        if ($cronPath === false || !file_exists($cronPath)) {
            jsonOut(['ok' => false, 'hint' => 'cron_backup_email.php nicht gefunden (' . __DIR__ . '/../../cron_backup_email.php)']);
        }
        if (!function_exists('exec')) {
            jsonOut(['ok' => false, 'hint' => 'exec() ist auf diesem Server nicht verfügbar.']);
        }

        // PHP_BINARY kann in mod_php (Apache-Modul) leer sein – expliziter Fallback
        $phpBin = PHP_BINARY;
        if (empty(trim($phpBin))) {
            $which = trim((string)shell_exec('which php 2>/dev/null'));
            $phpBin = $which ?: '/usr/local/bin/php';
        }

        $output = []; $code = 0;
        exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cronPath) . ' --force 2>&1', $output, $code);
        jsonOut(['ok' => $code === 0, 'output' => implode("\n", $output)]);
    }

    public function triggerStundenauswertungEmail(): void
    {
        Auth::requireRole('admin');

        $cronPath = realpath(__DIR__ . '/../../cron_stundenauswertung_email.php');
        if ($cronPath === false || !file_exists($cronPath)) {
            jsonOut(['ok' => false, 'hint' => 'cron_stundenauswertung_email.php nicht gefunden.']);
        }
        if (!function_exists('exec')) {
            jsonOut(['ok' => false, 'hint' => 'exec() ist auf diesem Server nicht verfügbar.']);
        }
        $phpBin = PHP_BINARY;
        if (empty(trim($phpBin))) {
            $which = trim((string)shell_exec('which php 2>/dev/null'));
            $phpBin = $which ?: '/usr/local/bin/php';
        }
        $output = []; $code = 0;
        exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cronPath) . ' --force 2>&1', $output, $code);
        jsonOut(['ok' => $code === 0, 'output' => implode("\n", $output)]);
    }

    public function loadErinnerungSettings(): void
    {
        Auth::requireRole('admin', 'master');
        $row = $this->db->query("SELECT data FROM erinnerung_settings WHERE id = 1")->fetch();
        $settings = $row ? (json_decode($row['data'], true) ?? []) : [];
        $waConfig = file_exists(__DIR__ . '/../../whatsapp_config.php')
            ? (require __DIR__ . '/../../whatsapp_config.php') : [];
        $defaults = [
            'stunden_aktiv'           => $waConfig['erinnerung_stunden_aktiv']    ?? true,
            'material_aktiv'          => $waConfig['erinnerung_material_aktiv']   ?? true,
            'material_uhrzeit'        => $waConfig['erinnerung_material_uhrzeit'] ?? '14:00',
            'backup_email_aktiv'      => false,
            'backup_email_empfaenger' => '',
            'backup_email_zyklus'     => 'woechentlich',
            'backup_email_uhrzeit'    => '07:00',
            'stundenauswertung_email_aktiv'      => false,
            'stundenauswertung_email_empfaenger' => '',
            'stundenauswertung_email_tag'        => 1,
            'stundenauswertung_email_uhrzeit'    => '07:00',
            'backup_email_max_mb'                => 20,
        ];
        $merged = array_merge($defaults, $settings);
        // Das Passwort verlässt den Server nie, nur ob eines gesetzt ist.
        $merged['backup_email_passwort_gesetzt'] = ($merged['backup_email_passwort'] ?? '') !== '';
        unset($merged['backup_email_passwort']);
        jsonOut(['ok' => true, 'settings' => $merged]);
    }

    public function saveErinnerungSettings(): void
    {
        Auth::requireRole('admin');
        $empfaenger = trim($this->body['backup_email_empfaenger'] ?? '');
        if ($empfaenger !== '' && !filter_var($empfaenger, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['error' => 'Ungültige Backup-Empfänger-E-Mail-Adresse.'], 400);
        }
        $allowedZyklus = ['taeglich', 'woechentlich', 'monatlich'];
        $zyklus = $this->body['backup_email_zyklus'] ?? 'woechentlich';
        if (!in_array($zyklus, $allowedZyklus, true)) $zyklus = 'woechentlich';

        $saEmpf = trim($this->body['stundenauswertung_email_empfaenger'] ?? '');
        if ($saEmpf !== '' && !filter_var($saEmpf, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['error' => 'Ungültige Empfänger-E-Mail für die Stundenauswertung.'], 400);
        }
        $saTag = max(1, min(28, (int)($this->body['stundenauswertung_email_tag'] ?? 1)));

        $row = $this->db->query("SELECT data FROM erinnerung_settings WHERE id = 1")->fetch();
        $old = $row ? (json_decode($row['data'], true) ?? []) : [];
        $backupPw = (string)($old['backup_email_passwort'] ?? '');
        if (!empty($this->body['backup_email_passwort_loeschen'])) {
            $backupPw = '';
        } elseif (($this->body['backup_email_passwort'] ?? '') !== '') {
            $pw = (string)$this->body['backup_email_passwort'];
            if (mb_strlen($pw) < 10) {
                jsonOut(['error' => 'Backup-Passwort mindestens 10 Zeichen.'], 400);
            }
            $backupPw = \App\Services\SecretBox::encrypt($pw);
        }

        $newSettings = [
            'stunden_aktiv'           => (bool)($this->body['stunden_aktiv'] ?? true),
            'material_aktiv'          => (bool)($this->body['material_aktiv'] ?? true),
            'material_uhrzeit'        => $this->body['material_uhrzeit'] ?? '14:00',
            'backup_email_aktiv'      => (bool)($this->body['backup_email_aktiv'] ?? false),
            'backup_email_empfaenger' => $empfaenger,
            'backup_email_zyklus'     => $zyklus,
            'backup_email_uhrzeit'    => $this->body['backup_email_uhrzeit'] ?? '07:00',
            'stundenauswertung_email_aktiv'      => (bool)($this->body['stundenauswertung_email_aktiv'] ?? false),
            'stundenauswertung_email_empfaenger' => $saEmpf,
            'stundenauswertung_email_tag'        => $saTag,
            'stundenauswertung_email_uhrzeit'    => $this->body['stundenauswertung_email_uhrzeit'] ?? '07:00',
            'backup_email_passwort'              => $backupPw,
            'backup_email_max_mb'                => max(1, min(100, (int)($this->body['backup_email_max_mb'] ?? $old['backup_email_max_mb'] ?? 20))),
        ];
        $json = json_encode($newSettings, JSON_UNESCAPED_UNICODE);
        $this->db->prepare("INSERT INTO erinnerung_settings (id, data) VALUES (1, ?) ON CONFLICT(id) DO UPDATE SET data = excluded.data")
                  ->execute([$json]);
        jsonOut(['ok' => true]);
    }

    // ── Update & Systeminfo ──────────────────────────────────
    public function systemInfo(): void
    {
        Auth::requireRole('admin');
        jsonOut(['ok' => true, 'info' => (new SystemInfo($this->db, DATA_DIR, BACKUP_DIR))->sammeln()]);
    }

    public function updateCheck(): void
    {
        Auth::requireRole('admin');
        $force = !empty($_GET['force'] ?? $this->body['force'] ?? null);
        $istPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($force && !$istPost) {
            jsonOut(['error' => 'Erzwungene Prüfung nur per POST.'], 405);
        }
        $pruefung = UpdatePruefung::ausUmgebung(DATA_DIR . 'update_check.json');
        // GET liefert nur den gecachten Stand, damit ein Seitenaufruf keinen Abruf auslöst.
        jsonOut(['ok' => true, 'update' => $istPost ? $pruefung->pruefen($force) : $pruefung->gecachterStand()]);
    }

    public function updateStart(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Update nur per POST.'], 405);
        }
        $version = $this->body['version'] ?? null;
        if (!is_string($version)) {
            jsonOut(['error' => 'Zielversion fehlt.'], 400);
        }
        $user = Database::fetchOne($this->db, 'SELECT id FROM users WHERE username = ?', [$_SESSION['username'] ?? '']);
        try {
            $antwort = $this->updateAuftrag()->anfordern($version, (int) ($user['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[update_start] ' . $e->getMessage());
            jsonOut(['error' => 'Update-Anforderung konnte nicht gespeichert werden.'], 500);
        }
        if (!$antwort['ok']) {
            jsonOut(['error' => UpdateAuftrag::MELDUNGEN[$antwort['code']], 'code' => $antwort['code']], 400);
        }
        AuditService::log('update_start', 'Update auf Version ' . $version . ' angefordert (' . $antwort['id'] . ')');
        jsonOut(['ok' => true, 'id' => $antwort['id']], 202);
    }

    public function updateStatus(): void
    {
        Auth::requireRole('admin');
        $auftrag = $this->updateAuftrag();
        jsonOut(['ok' => true, 'update' => $auftrag->status() + [
            'updater_aktiv' => $auftrag->updaterAktiv(),
            'zustand'       => $auftrag->zustand(),
            'wartung'       => UpdateAuftrag::wartungAktiv(DATA_DIR . 'update', time()),
        ]]);
    }

    private function updateAuftrag(): UpdateAuftrag
    {
        return new UpdateAuftrag(
            DATA_DIR . 'update',
            UpdatePruefung::ausUmgebung(DATA_DIR . 'update_check.json'),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
        );
    }
}
