<?php

use Phinx\Migration\AbstractMigration;

/**
 * Halbe Urlaubstage: tagesanteil = 1 (ganzer Tag) oder 0.5 (halber Tag).
 * Bestandsdaten bleiben ganze Tage.
 */
final class AddTagesanteilToZeiterfassung extends AbstractMigration
{
    public function up(): void
    {
        $this->table('zeiterfassung')
            ->addColumn('tagesanteil', 'float', ['default' => 1, 'null' => false])
            ->update();
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
