<?php

use Phinx\Migration\AbstractMigration;

/**
 * T3 (Überwachung und Systemmeldungen) – AP-20261012-datensicherung-nas, Vertrag Punkt 1.
 * beobachtetSeit trägt den Beginn der Beobachtungs-Schonfrist je Job (Ueberwachung::pruefen()).
 */
final class AddBeobachtetSeitToJobStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->getAdapter()->getConnection()->exec(
            "ALTER TABLE job_status ADD COLUMN beobachtetSeit TEXT NOT NULL DEFAULT ''"
        );
    }

    public function down(): void
    {
        throw new \RuntimeException('Nicht umkehrbar.');
    }
}
