<?php
namespace App\Services\Jobs;

use App\Backup\BackupArchive;
use App\Backup\BackupWriter;
use App\DataService;

/**
 * Tägliche Serversicherung (Format 2) – Staging + atomares rename(), damit ein
 * Abbruch mitten im Schreiben nie einen als gültig geltenden Tagesordner hinterlässt.
 * Eine flock-Sperre verhindert, dass Speichern (Rückfall) und Planer gleichzeitig
 * denselben Tagesordner anlegen.
 */
final class Tagessicherung
{
    public const ERSTELLT = 'erstellt';
    public const VORHANDEN = 'vorhanden';
    public const GESPERRT = 'gesperrt';

    /** Abstand zwischen zwei Sperrversuchen, solange $warteSekunden noch nicht erreicht ist. */
    private const SPERR_RETRY_MIKROSEKUNDEN = 250_000; // 250 ms

    /**
     * @param int $warteSekunden 0 = sofort per LOCK_NB (kein Blockieren); >0 = alle ~250 ms erneut
     *        per LOCK_NB versuchen, bis die Sperre frei ist oder $warteSekunden verstrichen sind
     *        (danach self::GESPERRT) – blockiert also nie unbegrenzt.
     * @return string self::ERSTELLT|self::VORHANDEN|self::GESPERRT
     * @throws \RuntimeException fester Text – Details nur per error_log()
     */
    public static function erstelle(\PDO $db, \DateTimeImmutable $jetzt, int $warteSekunden = 0): string
    {
        $tag = $jetzt->format('Y-m-d');
        $zielDir = rtrim(BACKUP_DIR, '/\\') . '/' . $tag;

        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir) && !mkdir($runDir, 0750, true) && !is_dir($runDir)) {
            throw new \RuntimeException('Sperrverzeichnis für die Tagessicherung konnte nicht erstellt werden.');
        }
        $lockPfad = $runDir . '/sicherung.lock';
        $lock = fopen($lockPfad, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Sperrdatei der Tagessicherung konnte nicht geöffnet werden.');
        }

        $versuchBis = microtime(true) + max(0, $warteSekunden);
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $versuchBis) {
                fclose($lock);
                return self::GESPERRT;
            }
            usleep(self::SPERR_RETRY_MIKROSEKUNDEN);
        }

        try {
            self::alteTempOrdnerAufraeumen($jetzt);

            if (is_dir($zielDir)) {
                try {
                    $archiv = BackupArchive::fromDir($zielDir);
                    $archiv->cleanup();
                    return self::VORHANDEN;
                } catch (\Throwable $e) {
                    error_log('[Tagessicherung] Vorhandener Tagesordner ungültig, wird neu erstellt: ' . $e->getMessage());
                    BackupArchive::removeDir($zielDir . '/');
                }
            }

            $tmpDir = rtrim(BACKUP_DIR, '/\\') . '/.tmp-' . bin2hex(random_bytes(6));
            try {
                BackupWriter::writeDir($db, $tmpDir);
                $pruefung = BackupArchive::fromDir($tmpDir);
                $pruefung->cleanup();
            } catch (\Throwable $e) {
                error_log('[Tagessicherung] Schreiben fehlgeschlagen: ' . $e->getMessage());
                BackupArchive::removeDir($tmpDir . '/');
                throw new \RuntimeException('Tagessicherung konnte nicht erstellt werden.');
            }
            if (!rename($tmpDir, $zielDir)) {
                BackupArchive::removeDir($tmpDir . '/');
                throw new \RuntimeException('Tagessicherung konnte nicht abgelegt werden.');
            }

            $einstellungen = SystemEinstellungen::laden($db);
            $aufbewahrung = (int)($einstellungen['sicherung']['aufbewahrungTage'] ?? 7);
            DataService::pruneBackupCategory('daily', $aufbewahrung);

            return self::ERSTELLT;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function alteTempOrdnerAufraeumen(\DateTimeImmutable $jetzt): void
    {
        $grenze = $jetzt->modify('-6 hours')->getTimestamp();
        foreach (glob(rtrim(BACKUP_DIR, '/\\') . '/.tmp-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $mtime = filemtime($dir);
            if ($mtime === false || $mtime < $grenze) {
                BackupArchive::removeDir($dir . '/');
            }
        }
    }
}
