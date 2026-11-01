<?php
namespace App\Services\Jobs;

use App\Backup\BackupArchive;

/**
 * Monatliche Wiederherstellungs-Probe (T3, E2, AP-20261012-datensicherung-nas):
 * prüft die neueste Tagessicherung, indem die enthaltene database.sqlite in ein
 * Temp-Verzeichnis kopiert und per eigenem PDO geöffnet wird (PRAGMA integrity_check
 * + mindestens eine Anwendungstabelle). Reine Dateisystem-/DB-Prüfung ohne eigene
 * Protokollierung – der Aufrufer (Planer) kapselt Fehler/Erfolg wie bei
 * Tagessicherung::erstelle().
 */
final class WiederherstellungsProbe
{
    public const FEHLER_KEINE_SICHERUNG = 'PROBE_KEINE_SICHERUNG';
    public const FEHLER_DEFEKT = 'PROBE_DEFEKT';

    /**
     * @return array{sicherungTag:string,tabellen:int,groesseBytes:int}
     * @throws \RuntimeException Nachricht ist der feste Fehlercode (self::FEHLER_*);
     *         Details gehen nur per error_log() (E-014).
     */
    public static function pruefe(): array
    {
        $tage = self::neuesteTagessicherungenAbsteigend();
        if ($tage === []) {
            throw new \RuntimeException(self::FEHLER_KEINE_SICHERUNG);
        }
        $neuesterTag = array_key_first($tage);
        $dir = $tage[$neuesterTag];

        try {
            $archiv = BackupArchive::fromDir($dir);
            $archiv->cleanup();
        } catch (\Throwable $e) {
            error_log('[WiederherstellungsProbe] Archivprüfung fehlgeschlagen: ' . $e->getMessage());
            throw new \RuntimeException(self::FEHLER_DEFEKT);
        }

        $sqlitePfad = rtrim($dir, '/\\') . '/' . BackupArchive::FILE_DB;
        if (!is_file($sqlitePfad)) {
            error_log('[WiederherstellungsProbe] ' . BackupArchive::FILE_DB . ' fehlt in ' . $dir);
            throw new \RuntimeException(self::FEHLER_DEFEKT);
        }

        [$tabellen, $groesseBytes] = self::kopiePruefen($sqlitePfad);

        return [
            'sicherungTag' => $neuesterTag,
            'tabellen'     => $tabellen,
            'groesseBytes' => $groesseBytes,
        ];
    }

    /** @return array<string,string> Tagesordner (JJJJ-MM-TT => Pfad), absteigend sortiert nach Tag */
    private static function neuesteTagessicherungenAbsteigend(): array
    {
        $dirs = glob(rtrim(BACKUP_DIR, '/\\') . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [];
        $tage = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1) {
                $tage[$name] = $dir;
            }
        }
        krsort($tage);
        return $tage;
    }

    /**
     * Existiert ein gültiger Tagesordner (bestandene BackupArchive-Prüfung) mit einem Datum vor
     * $heute? Wird vom Planer genutzt, um die Probe-Baseline nur bei Neuinstallationen (noch
     * keine ältere Tagessicherung vorhanden) zu setzen (Reviewbefund 5).
     */
    public static function hatGueltigenTagesordnerVor(string $heute): bool
    {
        foreach (self::neuesteTagessicherungenAbsteigend() as $tag => $dir) {
            if ($tag >= $heute) continue;
            try {
                BackupArchive::fromDir($dir)->cleanup();
                return true;
            } catch (\Throwable $e) {
                continue; // ungültiger Tagesordner zählt nicht als Nachweis einer bestehenden Installation
            }
        }
        return false;
    }

    /** @return array{0:int,1:int} [Tabellenanzahl, Dateigröße in Byte] */
    private static function kopiePruefen(string $sqlitePfad): array
    {
        $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/bk_probe_' . bin2hex(random_bytes(6)) . '/';
        if (!mkdir($tmpDir, 0700, true)) {
            throw new \RuntimeException(self::FEHLER_DEFEKT);
        }
        $tmpDb = $tmpDir . 'database.sqlite';
        $pdo = null;
        try {
            if (!copy($sqlitePfad, $tmpDb)) {
                throw new \RuntimeException(self::FEHLER_DEFEKT);
            }
            $groesseBytes = (int)(filesize($tmpDb) ?: 0);

            $pdo = new \PDO('sqlite:' . $tmpDb);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $check = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($check !== 'ok') {
                error_log('[WiederherstellungsProbe] integrity_check: ' . (string)$check);
                throw new \RuntimeException(self::FEHLER_DEFEKT);
            }
            $tabellen = (int)$pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchColumn();
            if ($tabellen < 1) {
                throw new \RuntimeException(self::FEHLER_DEFEKT);
            }
            return [$tabellen, $groesseBytes];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[WiederherstellungsProbe] Prüfung der Kopie fehlgeschlagen: ' . $e->getMessage());
            throw new \RuntimeException(self::FEHLER_DEFEKT);
        } finally {
            // SQLite hält das Datei-Handle bis zur Objekt-Freigabe – gc_collect_cycles()
            // vor dem Löschen stellt sicher, dass unlink() unter Windows nicht fehlschlägt (E-030).
            $pdo = null;
            gc_collect_cycles();
            // Kein @ (E-063, der globale Error-Handler wirft trotzdem) – stattdessen explizite
            // Prüfung + vorübergehend deaktivierter Error-Handler fürs reine Aufräumen.
            set_error_handler(static fn (): bool => true);
            try {
                if (is_file($tmpDb)) unlink($tmpDb);
                if (is_dir($tmpDir)) rmdir($tmpDir);
            } finally {
                restore_error_handler();
            }
        }
    }
}
