<?php
namespace App\Backup;

use App\DataService;

/**
 * Wiederherstellen einer Sicherung inkl. Sicherheitskopie des aktuellen Standes.
 * Gemeinsam genutzt von der Web-API und bin/console.
 */
final class RestoreService
{
    public function __construct(private readonly \PDO $db, private readonly string $user = '') {}

    /**
     * @return array{safetyBackup:string, import:array, archived:?int, data:array}
     * @throws InvalidBackupException|ImportConflictException|\RuntimeException – DB bleibt dann unverändert
     */
    public function restore(BackupArchive $archive, string $mode, string $reason): array
    {
        $data = $archive->data();
        $curActive = (int)$this->db->query('SELECT COUNT(*) FROM baustellen WHERE archiviert = 0')->fetchColumn();
        if (empty($data['baustellen']) && $curActive > 0) {
            throw new InvalidBackupException('Die Sicherung enthält keine aktiven Baustellen – die Wiederherstellung würde alle aktuellen Projekte entfernen.');
        }

        try {
            $safety = $this->createSafetySnapshot($reason);
        } catch (\Throwable $e) {
            error_log("Pre-{$reason}-Snapshot fehlgeschlagen: " . $e->getMessage());
            throw new \RuntimeException('Der aktuelle Stand konnte nicht gesichert werden.');
        }

        $archived = null;
        $hook = null;
        if ($mode === Importer::MODE_REPLACE) {
            // Aktive Baustellen des Ziels, die in der Sicherung fehlen, ins Archiv statt löschen.
            $backupIds = array_map(fn($b) => (int)$b['id'], $data['baustellen']);
            $hook = function () use ($backupIds, &$archived): void {
                $active = array_map('intval', $this->db->query('SELECT id FROM baustellen WHERE archiviert = 0')->fetchAll(\PDO::FETCH_COLUMN));
                $archived = $this->archiveBaustellen(array_values(array_diff($active, $backupIds)));
            };
        }

        $report = (new Importer($this->db))->import($archive, $mode, $this->user, $hook);
        return ['safetyBackup' => $safety, 'import' => $report, 'archived' => $archived, 'data' => $data];
    }

    /** Sichert den aktuellen Stand vor einem Restore; eindeutiger Name, damit nichts überschrieben wird. */
    public function createSafetySnapshot(string $reason): string
    {
        $base    = date('Y-m-d_H-i-s') . '_pre-' . $reason;
        $dirName = $base;
        for ($i = 2; is_dir(BACKUP_DIR . $dirName); $i++) {
            $dirName = $base . '-' . $i;
        }
        try {
            BackupWriter::writeDir($this->db, BACKUP_DIR . $dirName);
        } catch (\Throwable $e) {
            BackupArchive::removeDir(BACKUP_DIR . $dirName . '/');
            throw $e;
        }
        DataService::pruneBackupCategory('pre-restore', 20);
        return $dirName;
    }

    /** Verschiebt Baustellen ins Archiv (Export-JSON + Soft-Delete), analog zu BaustelleActions::archive(). */
    private function archiveBaustellen(array $ids): int
    {
        if (empty($ids)) return 0;

        $pList = array_map(
            fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'preis' => money_from_cents($p['preis'])],
            $this->db->query('SELECT id, name, preis FROM pauschalen ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
        );
        $upd = $this->db->prepare(
            'UPDATE baustellen SET archiviert = 1, archivFile = ?, archiviertAm = ?, archiviertVon = ? WHERE id = ?'
        );
        $count = 0;
        foreach (array_values($ids) as $idx => $bId) {
            $b = DataService::loadBaustelle($this->db, (int)$bId);
            if (!$b) continue;

            $safeName    = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $b['name'] ?? ('id' . $bId));
            $archiveName = date('Y-m-d_His') . '_' . sprintf('%02d', $idx) . '_' . $safeName;
            file_put_contents(
                ARCHIVE_DIR . $archiveName . '.json',
                json_encode([
                    'archivedAt' => date('c'),
                    'archivedBy' => ($this->user !== '' ? $this->user : 'System') . ' (Backup-Ersetzung)',
                    'baustelle'  => $b,
                    'pauschalen' => $pList,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );
            $upd->execute([$archiveName, date('Y-m-d H:i:s'), $this->user, (int)$bId]);
            $count++;
        }
        return $count;
    }
}
