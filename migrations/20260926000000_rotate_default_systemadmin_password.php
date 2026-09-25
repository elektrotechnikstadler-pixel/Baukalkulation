<?php

use App\Services\SystemadminPassword;
use Phinx\Migration\AbstractMigration;

/**
 * Bis v2.10.99 hatte "Systemadmin" in jeder Installation dasselbe, im Quellcode stehende
 * Startpasswort. Ist es noch aktiv, wird es durch ein individuelles ersetzt.
 */
final class RotateDefaultSystemadminPassword extends AbstractMigration
{
    private const LEGACY_DEFAULT = 'Stadler2580!';

    public function up(): void
    {
        if (!defined('DATA_DIR')) return; // nur zur Laufzeit der App, nicht über die Phinx-CLI

        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? COLLATE NOCASE');
        $stmt->execute([SystemadminPassword::USERNAME]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !password_verify(self::LEGACY_DEFAULT, (string)$row['password'])) return;

        $pdo->prepare('UPDATE users SET password = ?, mustChangePassword = 1, sessionInvalidatedAt = ? WHERE id = ?')
            ->execute([SystemadminPassword::newHash(), time(), $row['id']]);
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
