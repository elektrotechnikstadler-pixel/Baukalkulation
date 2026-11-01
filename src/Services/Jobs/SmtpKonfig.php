<?php
namespace App\Services\Jobs;

use App\Services\SecretBox;

/**
 * SMTP-Konfiguration für den Job-bezogenen Mailversand (Überwachung/Testmail, T3).
 * Liefert exakt dieselbe Quelle und Entschlüsselung wie cron_backup_email.php
 * (settings id 1, smtp_pass per SecretBox::decrypt) – cron_backup_email.php wird
 * bewusst NICHT auf diese Klasse umgebaut (AP-20261012-datensicherung-nas, T3-Vertrag Punkt 3).
 */
final class SmtpKonfig
{
    /** @return array{smtp_host:string,smtp_port:string,smtp_user:string,smtp_pass:string,smtp_from_email:string,smtp_from_name:string,smtp_security:string} */
    public static function laden(\PDO $db): array
    {
        $row = $db->query('SELECT data FROM settings WHERE id = 1')->fetch();
        $settings = $row ? (json_decode((string)$row['data'], true) ?? []) : [];

        $pass = '';
        try {
            $pass = SecretBox::decrypt((string)($settings['smtp_pass'] ?? ''));
        } catch (\Throwable $e) {
            // Sicherung einer anderen Installation eingespielt (anderer secret.key) – kein
            // Absturz, nur ein leeres Passwort und Protokollierung der Ursache (E-014).
            error_log('[SmtpKonfig] smtp_pass nicht entschlüsselbar: ' . $e->getMessage());
        }

        return [
            'smtp_host'       => (string)($settings['smtp_host'] ?? ''),
            'smtp_port'       => (string)($settings['smtp_port'] ?? '587'),
            'smtp_user'       => (string)($settings['smtp_user'] ?? ''),
            'smtp_pass'       => $pass,
            'smtp_from_email' => (string)($settings['smtp_from_email'] ?? $settings['firma_email'] ?? ''),
            'smtp_from_name'  => (string)($settings['smtp_from_name'] ?? $settings['firma_name'] ?? ''),
            'smtp_security'   => (string)($settings['smtp_security'] ?? 'tls'),
        ];
    }
}
