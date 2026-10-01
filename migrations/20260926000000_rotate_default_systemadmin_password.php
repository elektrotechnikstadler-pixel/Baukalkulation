<?php

use App\Services\SystemadminPassword;
use Phinx\Migration\AbstractMigration;

/**
 * Bis v2.10.99 hatte "Systemadmin" in jeder Installation dasselbe, im Quellcode stehende
 * Startpasswort. Beim Upgrade auf 3.x erhält das Konto deshalb immer ein individuelles Passwort
 * (data/systemadmin-passwort.txt bzw. BK_SYSTEMADMIN_PASSWORD) – ohne das alte Passwort zu kennen.
 */
final class RotateDefaultSystemadminPassword extends AbstractMigration
{
    public function up(): void
    {
        if (!defined('DATA_DIR')) return; // nur zur Laufzeit der App, nicht über die Phinx-CLI

        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?)');
        $stmt->execute([SystemadminPassword::USERNAME]);
        $id = $stmt->fetchColumn();
        if ($id === false) return;

        $pdo->prepare('UPDATE users SET password = ?, mustChangePassword = 1, sessionInvalidatedAt = ? WHERE id = ?')
            ->execute([SystemadminPassword::newHash(), time(), $id]);
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
