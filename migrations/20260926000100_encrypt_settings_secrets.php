<?php

use App\Auth;
use App\Services\SecretBox;
use Phinx\Migration\AbstractMigration;

/** SMTP-Passwort und Gemini-Key lagen bis v2.10.99 im Klartext in settings.data. */
final class EncryptSettingsSecrets extends AbstractMigration
{
    public function up(): void
    {
        if (!defined('DATA_DIR')) return; // Schlüssel liegt in DATA_DIR – nur zur Laufzeit der App

        $pdo = $this->getAdapter()->getConnection();
        $raw = $pdo->query('SELECT data FROM settings WHERE id = 1')->fetchColumn();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) return;

        $changed = false;
        foreach (Auth::SECRET_SETTINGS as $key) {
            $value = (string)($data[$key] ?? '');
            if ($value !== '' && !SecretBox::isEncrypted($value)) {
                $data[$key] = SecretBox::encrypt($value);
                $changed = true;
            }
        }
        if ($changed) {
            $pdo->prepare('UPDATE settings SET data = ? WHERE id = 1')
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
