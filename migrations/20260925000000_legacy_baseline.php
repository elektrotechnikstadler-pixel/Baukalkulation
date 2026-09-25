<?php

use App\Database\LegacySchema;
use Phinx\Migration\AbstractMigration;

/**
 * Basis: Schema-Stand v2.10.99. Legt eine neue DB an oder hebt jede ältere
 * SQLite-DB (vor Einführung der Migrationen) auf diesen Stand.
 */
final class LegacyBaseline extends AbstractMigration
{
    public function up(): void
    {
        LegacySchema::apply($this->getAdapter()->getConnection());
    }

    public function down(): void
    {
        throw new \RuntimeException('Die Basis-Migration kann nicht zurückgenommen werden.');
    }
}
