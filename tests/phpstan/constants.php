<?php

// Laufzeit-Konstanten der Einstiegspunkte, damit PHPStan sie kennt.
define('APP_ROOT', dirname(__DIR__, 2));
define('APP_VERSION', '0.0.0');
define('DATA_DIR', APP_ROOT . '/data/');
define('BACKUP_DIR', DATA_DIR . 'backups/');
define('ARCHIVE_DIR', DATA_DIR . 'archiv/');
define('EXPORT_DIR', DATA_DIR . 'exports/');
define('TAGEBUCH_DIR', DATA_DIR . 'bautagebuch/');
define('UPLOADS_DIR', DATA_DIR . 'uploads/');
define('DATANORM_DIR', DATA_DIR . 'datanorm/');
define('MODULES_DIR', APP_ROOT . '/modules/');
define('BACKUP_MAX', 7);
