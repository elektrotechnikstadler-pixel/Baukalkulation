<?php
namespace App\Services;

/**
 * Passwort des Support-Kontos "Systemadmin": je Installation individuell statt fest im Code.
 * Quelle: Env BK_SYSTEMADMIN_PASSWORD, sonst zufällig erzeugt und in DATA_DIR abgelegt (nur root/www-data lesbar).
 */
final class SystemadminPassword
{
    public const USERNAME = 'Systemadmin';
    public const FILE     = 'systemadmin-passwort.txt';

    public static function newHash(): string
    {
        $env = getenv('BK_SYSTEMADMIN_PASSWORD');
        if (is_string($env) && $env !== '') {
            if (mb_strlen($env) < 12) {
                throw new \RuntimeException('BK_SYSTEMADMIN_PASSWORD muss mindestens 12 Zeichen lang sein.');
            }
            return password_hash($env, PASSWORD_DEFAULT);
        }

        $plain = self::random(20);
        $file  = DATA_DIR . self::FILE;
        file_put_contents($file, "Startpasswort für '" . self::USERNAME . "' (muss bei der ersten Anmeldung geändert werden):\n{$plain}\n");
        chmod($file, 0600);
        error_log('[Baukalkulation] Systemadmin-Startpasswort erzeugt, siehe ' . $file);
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    private static function random(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
