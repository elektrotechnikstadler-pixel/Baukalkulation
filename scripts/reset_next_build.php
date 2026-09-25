<?php
// Reset script for next build/deployment.
// Usage (CLI): php scripts/reset_next_build.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

$baseDir = dirname(__DIR__);
$dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
$dbPath = $dataDir . 'database.sqlite';
$backupDir = $dataDir . 'backups' . DIRECTORY_SEPARATOR;

if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    fwrite(STDERR, "Could not create data directory: $dataDir\n");
    exit(1);
}
if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Could not create backup directory: $backupDir\n");
    exit(1);
}

$timestamp = date('Y-m-d_His');

if (file_exists($dbPath)) {
    $backupFile = $backupDir . 'pre_build_reset_' . $timestamp . '.sqlite';
    if (!copy($dbPath, $backupFile)) {
        fwrite(STDERR, "Backup failed: $backupFile\n");
        exit(1);
    }
    if (!unlink($dbPath)) {
        fwrite(STDERR, "Could not delete database file: $dbPath\n");
        exit(1);
    }
    echo "Database backup created: $backupFile\n";
}

// Optional runtime artifacts cleanup.
$runtimeFiles = [
    $dataDir . 'datanorm_index.tsv',
    $dataDir . 'metallzuschlag_cache.json',
    $dataDir . 'error.log',
];
foreach ($runtimeFiles as $file) {
    if (file_exists($file)) {
        @unlink($file);
    }
}

if (!defined('DATA_DIR')) {
    define('DATA_DIR', $dataDir);
}
if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', $backupDir);
}
if (!defined('ARCHIVE_DIR')) {
    define('ARCHIVE_DIR', $dataDir . 'archiv' . DIRECTORY_SEPARATOR);
}
if (!defined('EXPORT_DIR')) {
    define('EXPORT_DIR', $dataDir . 'exports' . DIRECTORY_SEPARATOR);
}
if (!defined('TAGEBUCH_DIR')) {
    define('TAGEBUCH_DIR', $dataDir . 'bautagebuch' . DIRECTORY_SEPARATOR);
}
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', $dataDir . 'uploads' . DIRECTORY_SEPARATOR);
}
if (!defined('BACKUP_MAX')) {
    define('BACKUP_MAX', 7);
}

foreach ([BACKUP_DIR, ARCHIVE_DIR, EXPORT_DIR, TAGEBUCH_DIR, UPLOADS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

$autoload = $baseDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php. Run composer install first.\n");
    exit(1);
}
require_once $autoload;

$db = App\Database::connect();
App\Auth::ensureSystemadmin($db);

echo "Reset complete.\n";
echo "Standard-Admin: 'Systemadmin' (Initial-Passwort siehe interne Dokumentation; muss beim ersten Login geaendert werden).\n";
