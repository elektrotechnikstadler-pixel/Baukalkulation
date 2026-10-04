<?php

use Phinx\Migration\AbstractMigration;

final class AddWeekdaySollzeitToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('sollzeitJeWochentag', 'integer', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenMo', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenDi', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenMi', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenDo', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenFr', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenSa', 'float', ['default' => 0, 'null' => false])
            ->addColumn('sollstundenSo', 'float', ['default' => 0, 'null' => false])
            ->update();
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}