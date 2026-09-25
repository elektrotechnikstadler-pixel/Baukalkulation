<?php
namespace App\Console;

use App\Console\Command\BackupCreateCommand;
use App\Console\Command\BackupImportCommand;
use App\Console\Command\DbMigrateCommand;
use App\Console\Command\DbStatusCommand;
use App\Console\Command\DbTransferCommand;
use App\Console\Command\UserResetPasswordCommand;
use Symfony\Component\Console\Application;

final class Kernel extends Application
{
    public function __construct()
    {
        parent::__construct('Baukalkulation', defined('APP_VERSION') ? APP_VERSION : '0.0.0');
        $this->addCommands([
            new DbMigrateCommand(),
            new DbStatusCommand(),
            new DbTransferCommand(),
            new BackupCreateCommand(),
            new BackupImportCommand(),
            new UserResetPasswordCommand(),
        ]);
    }

    /** Definiert die Laufzeit-Konstanten wie public/api.php (Datenverzeichnis per BK_DATA_DIR). */
    public static function bootstrap(string $appRoot): void
    {
        $versionFile = $appRoot . '/VERSION';
        defined('APP_ROOT')    || define('APP_ROOT', $appRoot);
        defined('APP_VERSION') || define('APP_VERSION', is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : '0.0.0');
        defined('DATA_DIR')    || define('DATA_DIR', rtrim(getenv('BK_DATA_DIR') ?: $appRoot . '/data', '/\\') . '/');
        defined('MODULES_DIR') || define('MODULES_DIR', $appRoot . '/modules/');

        $paths = is_file(DATA_DIR . 'paths_config.json')
            ? (json_decode((string)file_get_contents(DATA_DIR . 'paths_config.json'), true) ?? [])
            : [];
        defined('BACKUP_DIR')   || define('BACKUP_DIR',   $paths['backups']     ?? DATA_DIR . 'backups/');
        defined('ARCHIVE_DIR')  || define('ARCHIVE_DIR',  $paths['archiv']      ?? DATA_DIR . 'archiv/');
        defined('EXPORT_DIR')   || define('EXPORT_DIR',   $paths['exports']     ?? DATA_DIR . 'exports/');
        defined('TAGEBUCH_DIR') || define('TAGEBUCH_DIR', $paths['bautagebuch'] ?? DATA_DIR . 'bautagebuch/');
        defined('UPLOADS_DIR')  || define('UPLOADS_DIR',  $paths['uploads']     ?? DATA_DIR . 'uploads/');
        defined('DATANORM_DIR') || define('DATANORM_DIR', $paths['datanorm']    ?? DATA_DIR . 'datanorm/');
        defined('BACKUP_MAX')   || define('BACKUP_MAX', 7);

        foreach ([DATA_DIR, BACKUP_DIR, ARCHIVE_DIR] as $dir) {
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
        }
    }
}
