<?php
namespace App\Handlers;

use App\Auth;
use App\DataService;

class BaustelleActions
{
    public function __construct(private \PDO $db, private array $body) {}

    /** archive_baustelle
     *
     * Akzeptiert entweder:
     *   - {baustelleId, excelData}                  (Einzel-Archivierung, abwärtskompatibel)
     *   - {items: [{baustelleId, excelData}, ...]}  (Stapel: Hauptprojekt + Unterprojekte)
     *
     * Bei mehreren Items werden alle Archive mit derselben archiveGroup-ID
     * markiert, damit zusammengehörige Projekte später identifiziert werden können.
     */
    public function archive(): void
    {
        Auth::requireRole('admin', 'master');

        // Eingabe normalisieren -> immer Liste $items
        $items = $this->body['items'] ?? null;
        if (!is_array($items) || empty($items)) {
            $singleId = (int)($this->body['baustelleId'] ?? 0);
            if (!$singleId) jsonOut(['error' => 'Keine Baustellen-ID.'], 400);
            $items = [[
                'baustelleId' => $singleId,
                'excelData'   => $this->body['excelData'] ?? '',
            ]];
        }

        // Pauschalen einmalig laden (gleich für alle Archive) (v2.9.20: Cents -> Euro)
        $pauschalen = $this->db->query("SELECT id, name, preis FROM pauschalen ORDER BY id")->fetchAll();
        $pList = array_map(
            fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'preis' => money_from_cents($p['preis'])],
            $pauschalen
        );

        // Gruppen-ID nur, wenn mehrere Baustellen gleichzeitig archiviert werden
        $groupId = count($items) > 1
            ? date('Ymd_His') . '_g' . substr(bin2hex(random_bytes(3)), 0, 6)
            : null;

        $archiveFiles = [];
        $skipped      = [];

        foreach ($items as $idx => $it) {
            $bId = (int)($it['baustelleId'] ?? 0);
            if (!$bId) continue;
            $b = DataService::loadBaustelle($this->db, $bId);
            if (!$b) { $skipped[] = $bId; continue; }

            $safeName    = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $b['name'] ?? ('id' . $bId));
            $archiveName = date('Y-m-d_His') . '_' . sprintf('%02d', $idx) . '_' . $safeName;

            $payload = [
                'archivedAt' => date('c'),
                'archivedBy' => $_SESSION['username'] ?? '',
                'baustelle'  => $b,
                'pauschalen' => $pList,
            ];
            if ($groupId) $payload['archiveGroup'] = $groupId;

            file_put_contents(
                ARCHIVE_DIR . $archiveName . '.json',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            $excelData = $it['excelData'] ?? '';
            if ($excelData) {
                $decoded = base64_decode($excelData);
                if ($decoded !== false) {
                    file_put_contents(ARCHIVE_DIR . $archiveName . '.xlsx', $decoded);
                }
            }

            // Soft-Delete: Die Zeile bleibt erhalten, damit zeiterfassung.baustelleId
            // weiterhin auflösbar ist. Ein hartes DELETE machte früher jede Buchung
            // auf diesem Projekt zur Fehlbuchung.
            $this->db->prepare(
                "UPDATE baustellen SET archiviert = 1, archivFile = ?, archiviertAm = ?, archiviertVon = ? WHERE id = ?"
            )->execute([$archiveName, date('Y-m-d H:i:s'), $_SESSION['username'] ?? '', $bId]);
            $archiveFiles[] = $archiveName;
        }

        if (empty($archiveFiles)) jsonOut(['error' => 'Keine Baustellen archiviert.'], 400);

        jsonOut([
            'ok'            => true,
            'archiveFile'   => $archiveFiles[0],   // abwärtskompatibel
            'archiveFiles'  => $archiveFiles,
            'archiveGroup'  => $groupId,
            'count'         => count($archiveFiles),
            'skipped'       => $skipped,
        ]);
    }

    /** list_archive */
    public function listArchive(): void
    {
        Auth::requireRole('admin', 'master');
        $files = glob(ARCHIVE_DIR . '*.json') ?: [];
        rsort($files);
        $archives = [];
        foreach ($files as $f) {
            $snap = json_decode(file_get_contents($f), true);
            if (!$snap || !isset($snap['baustelle'])) continue;
            $baseName = basename($f, '.json');
            $archives[] = [
                'file'         => $baseName,
                'id'           => isset($snap['baustelle']['id']) ? (int)$snap['baustelle']['id'] : null,
                'name'         => $snap['baustelle']['name'] ?? 'Unbekannt',
                'archivedAt'   => isset($snap['archivedAt']) ? date('d.m.Y H:i', strtotime($snap['archivedAt'])) : '–',
                'archivedBy'   => $snap['archivedBy'] ?? '–',
                'hasExcel'     => file_exists(ARCHIVE_DIR . $baseName . '.xlsx'),
                'archiveGroup' => $snap['archiveGroup'] ?? null,
                'parentId'     => $snap['baustelle']['parentId'] ?? null,
                'projektNr'    => $snap['baustelle']['projektNr'] ?? '',
            ];
        }
        jsonOut(['ok' => true, 'archives' => $archives]);
    }

    /** search_archive – Metadaten-Suche über archivierte Baustellen (für globale Suche) */
    public function searchArchive(): void
    {
        Auth::requireRole('admin', 'master');
        $q = trim((string)($this->body['q'] ?? $_GET['q'] ?? ''));
        $qLower = mb_strtolower($q);
        $files = glob(ARCHIVE_DIR . '*.json') ?: [];
        rsort($files);
        $archives = [];
        foreach ($files as $f) {
            $snap = json_decode(file_get_contents($f), true);
            if (!$snap || !isset($snap['baustelle'])) continue;
            $baseName   = basename($f, '.json');
            $name       = $snap['baustelle']['name'] ?? 'Unbekannt';
            $projektNr  = $snap['baustelle']['projektNr'] ?? '';
            $archivedBy = $snap['archivedBy'] ?? '';
            if ($qLower !== '') {
                $hay = mb_strtolower($name . ' ' . $projektNr . ' ' . $archivedBy);
                if (mb_strpos($hay, $qLower) === false) continue;
            }
            $archives[] = [
                'file'       => $baseName,
                'name'       => $name,
                'archivedAt' => isset($snap['archivedAt']) ? date('d.m.Y H:i', strtotime($snap['archivedAt'])) : '–',
                'archivedBy' => $archivedBy !== '' ? $archivedBy : '–',
                'hasExcel'   => file_exists(ARCHIVE_DIR . $baseName . '.xlsx'),
                'parentId'   => $snap['baustelle']['parentId'] ?? null,
                'projektNr'  => $projektNr,
            ];
        }
        jsonOut(['ok' => true, 'archives' => $archives]);
    }

    public function downloadArchiveExcel(): void
    {
        Auth::requireRole('admin', 'master');
        $fileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '', $_GET['file'] ?? '');
        $xlsxPath = ARCHIVE_DIR . $fileBase . '.xlsx';
        if (!$fileBase || !file_exists($xlsxPath)) jsonOut(['error' => 'Datei nicht gefunden.'], 404);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Archiv_' . $fileBase . '.xlsx"');
        header('Content-Length: ' . filesize($xlsxPath));
        readfile($xlsxPath);
        exit;
    }

    /** download_archive_json – streamt eine einzelne Archiv-JSON zum Sichern. */
    public function downloadArchiveJson(): void
    {
        Auth::requireRole('admin', 'master');
        $fileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '', $_GET['file'] ?? '');
        $jsonPath = ARCHIVE_DIR . $fileBase . '.json';
        if (!$fileBase || !file_exists($jsonPath)) jsonOut(['error' => 'Datei nicht gefunden.'], 404);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.json"');
        header('Content-Length: ' . filesize($jsonPath));
        readfile($jsonPath);
        exit;
    }

    /** download_archive_all – packt das komplette Archiv (JSON + XLSX) als ZIP. */
    public function downloadArchiveAll(): void
    {
        Auth::requireRole('admin', 'master');
        $files = array_merge(
            glob(ARCHIVE_DIR . '*.json') ?: [],
            glob(ARCHIVE_DIR . '*.xlsx') ?: []
        );
        if (empty($files)) jsonOut(['error' => 'Archiv ist leer.'], 404);

        $zipName = 'baukalkulation_archiv_' . date('Y-m-d_H-i') . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . uniqid('arch_', true) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            jsonOut(['error' => 'ZIP konnte nicht erstellt werden.'], 500);
        }
        foreach ($files as $f) {
            if (is_file($f)) $zip->addFile($f, basename($f));
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    /**
     * upload_archive_json – legt eine hochgeladene Archiv-JSON ins Archiv ab.
     * Es wird NICHT reimportiert; die Datei erscheint nur in der Archiv-Liste
     * und kann anschließend regulär über „Importieren" reaktiviert werden.
     */
    public function uploadArchiveJson(): void
    {
        Auth::requireRole('admin', 'master');
        $uploaded = $_FILES['archive'] ?? null;
        if (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Keine gültige Datei hochgeladen.'], 400);
        }

        $raw  = file_get_contents($uploaded['tmp_name']);
        $snap = json_decode((string)$raw, true);
        if (!is_array($snap) || !isset($snap['baustelle']) || !is_array($snap['baustelle'])) {
            jsonOut(['error' => 'Ungültige Archiv-Datei (kein baustelle-Block).'], 400);
        }

        $base = $this->_safeArchiveBase($uploaded['name'] ?? '', $snap);
        $dest = $this->_uniqueArchivePath($base);
        if (file_put_contents($dest, $raw) === false) {
            jsonOut(['error' => 'Datei konnte nicht gespeichert werden (Schreibrechte?).'], 500);
        }

        jsonOut(['ok' => true, 'file' => basename($dest, '.json')]);
    }

    /**
     * upload_archive_all – entpackt ein hochgeladenes Archiv-ZIP ins Archiv.
     * Nur *.json/*.xlsx werden übernommen, Verzeichnisstrukturen ignoriert
     * (Zip-Slip-Schutz via basename). JSON wird auf gültige Struktur geprüft.
     */
    public function uploadArchiveAll(): void
    {
        Auth::requireRole('admin', 'master');
        $uploaded = $_FILES['archive'] ?? null;
        if (!$uploaded || ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Kein gültiges ZIP hochgeladen.'], 400);
        }

        $zip = new \ZipArchive();
        if ($zip->open($uploaded['tmp_name']) !== true) {
            jsonOut(['error' => 'ZIP konnte nicht geöffnet werden.'], 400);
        }

        $imported = 0; $skipped = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || substr($entry, -1) === '/') continue;
            $name = basename($entry); // Zip-Slip: nur Dateiname
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['json', 'xlsx'], true)) { $skipped++; continue; }

            $content = $zip->getFromIndex($i);
            if ($content === false) { $skipped++; continue; }

            if ($ext === 'json') {
                $snap = json_decode($content, true);
                if (!is_array($snap) || !isset($snap['baustelle'])) { $skipped++; continue; }
            }

            $safe = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', pathinfo($name, PATHINFO_FILENAME));
            if ($safe === '') { $skipped++; continue; }
            $dest = ARCHIVE_DIR . $safe . '.' . $ext;
            if (file_put_contents($dest, $content) !== false) $imported++;
            else $skipped++;
        }
        $zip->close();

        jsonOut(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
    }

    /** Ermittelt einen sicheren Archiv-Basisnamen (ohne Endung) aus Upload-Name/Inhalt. */
    private function _safeArchiveBase(string $origName, array $snap): string
    {
        $base = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', pathinfo($origName, PATHINFO_FILENAME));
        if ($base === '' || $base === null) {
            $name = $snap['baustelle']['name'] ?? 'archiv';
            $base = date('Y-m-d_His') . '_00_' . preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '_', $name);
        }
        return $base;
    }

    /** Liefert einen freien .json-Pfad im Archiv (hängt bei Kollision Suffix an). */
    private function _uniqueArchivePath(string $base): string
    {
        $path = ARCHIVE_DIR . $base . '.json';
        $n = 1;
        while (file_exists($path)) {
            $path = ARCHIVE_DIR . $base . '_' . $n . '.json';
            $n++;
        }
        return $path;
    }

    /** reimport_baustelle
     *
     * Standard:  importiert genau die angegebene Archiv-Datei wieder.
     * Optional:  {file, withGroup:true} importiert alle Archive, die zur selben
     *            archiveGroup gehören (Oberprojekt + Unterprojekte). parentId
     *            wird dabei auf die neu vergebenen IDs remappt, damit die
     *            Hierarchie erhalten bleibt.
     */
    public function reimport(): void
    {
        Auth::requireRole('admin', 'master');
        $fileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '', $this->body['file'] ?? '');
        $jsonPath = ARCHIVE_DIR . $fileBase . '.json';
        if (!$fileBase || !file_exists($jsonPath)) jsonOut(['error' => 'Archiv-Datei nicht gefunden.'], 404);

        $snap = json_decode(file_get_contents($jsonPath), true);
        if (!$snap || !isset($snap['baustelle'])) jsonOut(['error' => 'Archiv-Datei beschädigt.'], 500);

        $withGroup = !empty($this->body['withGroup']);
        $groupId   = $snap['archiveGroup'] ?? null;

        // Einzel-Import (Standard, abwärtskompatibel)
        if (!$withGroup || !$groupId) {
            // Soft-Delete-Fall: Die Zeile existiert noch -> nur reaktivieren. Die ID
            // bleibt erhalten, damit alle zeiterfassung-Verknüpfungen intakt sind.
            $existing = \App\Database::fetchOne(
                $this->db,
                "SELECT id FROM baustellen WHERE archiviert = 1 AND archivFile = ?",
                [$fileBase]
            );
            if ($existing) {
                $reId = (int)$existing['id'];
                $this->db->prepare(
                    "UPDATE baustellen SET archiviert = 0, archivFile = '', archiviertAm = '', archiviertVon = '' WHERE id = ?"
                )->execute([$reId]);
                jsonOut([
                    'ok'          => true,
                    'baustelleId' => $reId,
                    'name'        => $snap['baustelle']['name'] ?? '',
                    'reactivated' => true,
                ]);
            }

            $newId = (int)$this->db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM baustellen")->fetchColumn();
            $baustelle = $snap['baustelle'];
            $baustelle['id'] = $newId;
            // Verwaister parentId kann nach Einzel-Reimport nicht mehr stimmen
            // -> als Top-Level wiederherstellen, damit die Baustelle nicht
            // unsichtbar unter einem gelöschten Oberprojekt hängt.
            if (!empty($baustelle['parentId'])) $baustelle['parentId'] = null;
            DataService::saveBaustelle($this->db, $baustelle);
            jsonOut(['ok' => true, 'baustelleId' => $newId, 'name' => $baustelle['name'] ?? '']);
        }

        // Gruppen-Import: alle Archive mit derselben archiveGroup sammeln
        $files = glob(ARCHIVE_DIR . '*.json') ?: [];
        $members = [];
        foreach ($files as $f) {
            $s = json_decode(file_get_contents($f), true);
            if (!$s || !isset($s['baustelle'])) continue;
            if (($s['archiveGroup'] ?? null) !== $groupId) continue;
            $members[] = ['file' => basename($f, '.json'), 'snap' => $s];
        }
        if (empty($members)) jsonOut(['error' => 'Keine Gruppen-Archive gefunden.'], 404);

        // Oberprojekte zuerst, damit das id-Mapping stimmt
        usort($members, fn($a, $b) => ((int)!empty($a['snap']['baustelle']['parentId'])) <=> ((int)!empty($b['snap']['baustelle']['parentId'])));

        $idMap    = [];
        $imported = [];
        $nextId   = (int)$this->db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM baustellen")->fetchColumn();

        foreach ($members as $m) {
            $b      = $m['snap']['baustelle'];
            $oldId  = (int)($b['id'] ?? 0);

            // Soft-Delete-Fall: ID beibehalten, damit Zeitbuchungen verknüpft bleiben.
            $existing = \App\Database::fetchOne(
                $this->db,
                "SELECT id FROM baustellen WHERE archiviert = 1 AND archivFile = ?",
                [$m['file']]
            );
            if ($existing) {
                $reId = (int)$existing['id'];
                $this->db->prepare(
                    "UPDATE baustellen SET archiviert = 0, archivFile = '', archiviertAm = '', archiviertVon = '' WHERE id = ?"
                )->execute([$reId]);
                if ($oldId) $idMap[$oldId] = $reId;
                $imported[] = [
                    'baustelleId' => $reId,
                    'name'        => $b['name'] ?? '',
                    'parentId'    => $b['parentId'] ?? null,
                    'file'        => $m['file'],
                    'reactivated' => true,
                ];
                continue;
            }

            $b['id'] = $nextId;

            if (!empty($b['parentId'])) {
                $oldParent = (int)$b['parentId'];
                // Parent ist Gruppen-Mitglied und wurde oben schon remappt
                $b['parentId'] = $idMap[$oldParent] ?? null;
            }

            DataService::saveBaustelle($this->db, $b);
            if ($oldId) $idMap[$oldId] = $nextId;
            $imported[] = [
                'baustelleId' => $nextId,
                'name'        => $b['name'] ?? '',
                'parentId'    => $b['parentId'] ?? null,
                'file'        => $m['file'],
            ];
            $nextId++;
        }

        // Primär zurückgegebene ID = die vom angeklickten Archiv (falls
        // remapped), sonst der zuerst importierte Eintrag.
        $primaryId = $idMap[(int)($snap['baustelle']['id'] ?? 0)] ?? ($imported[0]['baustelleId'] ?? null);
        jsonOut([
            'ok'           => true,
            'baustelleId'  => $primaryId,
            'name'         => $snap['baustelle']['name'] ?? '',
            'imported'     => $imported,
            'count'        => count($imported),
            'archiveGroup' => $groupId,
        ]);
    }

    /** delete_archive */
    public function deleteArchive(): void
    {
        Auth::requireRole('admin');
        $fileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '', $this->body['file'] ?? '');
        if (!$fileBase) jsonOut(['error' => 'Kein Dateiname.'], 400);
        $jsonPath = ARCHIVE_DIR . $fileBase . '.json';
        $xlsxPath = ARCHIVE_DIR . $fileBase . '.xlsx';
        if (file_exists($jsonPath)) @unlink($jsonPath);
        if (file_exists($xlsxPath)) @unlink($xlsxPath);

        // Die soft-gelöschte Projektzeile darf nur verschwinden, wenn keine
        // Zeitbuchung mehr darauf verweist – sonst verlöre die Stundenauswertung
        // den Projektbezug.
        $row = \App\Database::fetchOne(
            $this->db,
            "SELECT id FROM baustellen WHERE archiviert = 1 AND archivFile = ?",
            [$fileBase]
        );
        $kept = false;
        if ($row) {
            $bId = (int)$row['id'];
            $ref = \App\Database::fetchOne(
                $this->db,
                "SELECT COUNT(*) AS c FROM zeiterfassung WHERE baustelleId = ?",
                [$bId]
            );
            if ((int)($ref['c'] ?? 0) === 0) {
                $this->db->prepare("DELETE FROM baustellen WHERE id = ?")->execute([$bId]);
            } else {
                $kept = true;
            }
        }

        jsonOut(['ok' => true, 'projektZeileBehalten' => $kept]);
    }

    /**
     * clear_archive – leert das gesamte Archiv (Dateien), OHNE Stunden zu verwaisen.
     *
     * Für jede Archiv-Datei wird die zugehörige Soft-Delete-Zeile geprüft:
     *  - Wird sie noch von Zeitbuchungen referenziert, BLEIBT sie als archiviert=1
     *    erhalten (nur die Datei wird entfernt, archivFile wird geleert).
     *  - Ist sie nicht referenziert, darf auch die Zeile gelöscht werden.
     *
     * Ohne ?confirm=1 wird nur eine Vorschau geliefert (Dry-Run).
     */
    public function clearArchive(): void
    {
        Auth::requireRole('admin');
        $confirm = !empty($_GET['confirm']) || !empty($this->body['confirm']);
        $files   = glob(ARCHIVE_DIR . '*.json') ?: [];

        // Referenzierte Baustellen (durch Stunden genutzt) dürfen ihre Zeile behalten.
        $referenziert = [];
        foreach ($this->db->query(
            "SELECT DISTINCT baustelleId FROM zeiterfassung WHERE baustelleId IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $referenziert[(int)$id] = true;
        }

        if (!$confirm) {
            $behalten = 0; $entfernbar = 0;
            foreach ($files as $f) {
                $snap = json_decode((string)file_get_contents($f), true);
                $id   = (int)($snap['baustelle']['id'] ?? 0);
                if ($id > 0 && isset($referenziert[$id])) $behalten++; else $entfernbar++;
            }
            jsonOut([
                'ok'              => true,
                'dryRun'          => true,
                'archiveDateien'  => count($files),
                'zeilenBehalten'  => $behalten,
                'zeilenEntfernbar'=> $entfernbar,
            ]);
        }

        $dateien = 0; $behaltenZeilen = 0; $geloeschteZeilen = 0;
        $this->db->beginTransaction();
        try {
            $keep = $this->db->prepare("UPDATE baustellen SET archivFile = '' WHERE id = ? AND archiviert = 1");
            $del  = $this->db->prepare("DELETE FROM baustellen WHERE id = ? AND archiviert = 1");
            foreach ($files as $f) {
                $snap = json_decode((string)file_get_contents($f), true);
                $id   = (int)($snap['baustelle']['id'] ?? 0);
                $base = basename($f, '.json');
                @unlink($f);
                @unlink(ARCHIVE_DIR . $base . '.xlsx');
                $dateien++;
                if ($id > 0) {
                    if (isset($referenziert[$id])) { $keep->execute([$id]); $behaltenZeilen++; }
                    else { $del->execute([$id]); $geloeschteZeilen++; }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] clear_archive fehlgeschlagen: ' . $e->getMessage());
            jsonOut(['error' => 'Archiv leeren fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        jsonOut([
            'ok'              => true,
            'dryRun'          => false,
            'dateienGeloescht'=> $dateien,
            'zeilenBehalten'  => $behaltenZeilen,
            'zeilenGeloescht' => $geloeschteZeilen,
        ]);
    }

    /**
     * repair_archiv_projekte – stellt archivierte Projekte als Soft-Delete-Zeilen her.
     *
     * Vor v2.10.83 löschte das Archivieren die Baustellen-Zeile hart; nur die JSON-Datei
     * im Archivverzeichnis blieb übrig. Dadurch zeigt zeiterfassung.baustelleId ins Leere
     * und die Stundenauswertung kann den Projektnamen nicht mehr auflösen.
     *
     * Diese Reparatur liest die Archiv-Dateien und legt die fehlenden Zeilen mit ihrer
     * ORIGINAL-ID und archiviert=1 wieder an. Die Projekte bleiben damit überall
     * ausgeblendet, sind aber wieder auflösbar.
     *
     * Ohne ?confirm=1 wird nur eine Vorschau geliefert (Dry-Run).
     */
    public function repairArchivProjekte(): void
    {
        Auth::requireRole('admin', 'master');
        $confirm = !empty($_GET['confirm']) || !empty($this->body['confirm']);

        // Je Projekt-ID gewinnt das jüngste Archiv (ein Projekt kann mehrfach
        // archiviert und reimportiert worden sein).
        $byId = [];
        foreach (glob(ARCHIVE_DIR . '*.json') ?: [] as $f) {
            $snap = json_decode((string)file_get_contents($f), true);
            if (!$snap || !isset($snap['baustelle']) || !is_array($snap['baustelle'])) continue;
            $id = (int)($snap['baustelle']['id'] ?? 0);
            if ($id <= 0) continue;
            $ts = (string)($snap['archivedAt'] ?? '');
            if (!isset($byId[$id]) || $ts > $byId[$id]['ts']) {
                $byId[$id] = ['ts' => $ts, 'file' => basename($f, '.json'), 'snap' => $snap];
            }
        }

        $vorhanden = [];
        foreach ($this->db->query("SELECT id FROM baustellen")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $vorhanden[(int)$id] = true;
        }

        // Nur referenzierte IDs sind für die Stundenauswertung relevant.
        $referenziert = [];
        foreach ($this->db->query(
            "SELECT DISTINCT baustelleId FROM zeiterfassung WHERE baustelleId IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $referenziert[(int)$id] = true;
        }

        $wiederherstellbar = [];
        $bereitsVorhanden  = 0;
        foreach ($byId as $id => $entry) {
            if (isset($vorhanden[$id])) { $bereitsVorhanden++; continue; }
            $wiederherstellbar[] = [
                'baustelleId'  => $id,
                'name'         => (string)($entry['snap']['baustelle']['name'] ?? ''),
                'archivFile'   => $entry['file'],
                'archiviertAm' => $entry['ts'],
                'referenziert' => isset($referenziert[$id]),
            ];
        }

        // Referenzierte IDs, für die es KEIN Archiv gibt – diese werden als
        // ARCHIVIERTE Platzhalter wiederhergestellt (nie aktiv), damit die
        // zugehörigen Stunden wieder auflösen und keine ZE005-Warnungen entstehen.
        $ohneArchiv = [];
        foreach (array_keys($referenziert) as $id) {
            if (!isset($vorhanden[$id]) && !isset($byId[$id])) $ohneArchiv[] = $id;
        }
        // Namen für Platzhalter aus der denormalisierten zeiterfassung.baustelleName.
        $ohneArchivNamen = [];
        if ($ohneArchiv) {
            $ph = implode(',', array_fill(0, count($ohneArchiv), '?'));
            $nStmt = $this->db->prepare(
                "SELECT baustelleId, baustelleName FROM zeiterfassung
                  WHERE baustelleId IN ($ph) AND baustelleName != ''"
            );
            $nStmt->execute(array_values($ohneArchiv));
            foreach ($nStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $bid = (int)$r['baustelleId'];
                if (!isset($ohneArchivNamen[$bid])) $ohneArchivNamen[$bid] = (string)$r['baustelleName'];
            }
        }

        if (!$confirm) {
            jsonOut([
                'ok'                => true,
                'dryRun'            => true,
                'hinweis'           => 'Vorschau. Zum Ausführen ?confirm=1 anhängen.',
                'archiveDateien'    => count($byId),
                'bereitsVorhanden'  => $bereitsVorhanden,
                'wiederherstellbar' => $wiederherstellbar,
                'ohneArchivDatei'   => $ohneArchiv,
                'counts'            => [
                    'wiederherstellbar'             => count($wiederherstellbar),
                    'davonMitZeitbuchungen'         => count(array_filter($wiederherstellbar, fn($w) => $w['referenziert'])),
                    'referenziertOhneArchivDatei'   => count($ohneArchiv),
                    'platzhalterAnlegbar'           => count($ohneArchiv),
                ],
            ]);
        }

        $restored    = 0;
        $namen       = 0;
        $status      = 0;
        $platzhalter = 0;
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE baustellen SET archiviert = 1, archivFile = ?, archiviertAm = ?, archiviertVon = ? WHERE id = ?"
            );
            foreach ($wiederherstellbar as $w) {
                $b = $byId[$w['baustelleId']]['snap']['baustelle'];
                $b['id'] = $w['baustelleId'];
                DataService::saveBaustelle($this->db, $b);
                $upd->execute([
                    $w['archivFile'],
                    $w['archiviertAm'],
                    (string)($byId[$w['baustelleId']]['snap']['archivedBy'] ?? ''),
                    $w['baustelleId'],
                ]);
                $restored++;
            }

            // Platzhalter für verwaiste Stunden OHNE Archiv-Datei: minimale Zeile,
            // ARCHIVIERT (archiviert=1) – niemals als aktives Projekt.
            $updPh = $this->db->prepare(
                "UPDATE baustellen SET archiviert = 1, archivFile = '', archiviertAm = ?, archiviertVon = ? WHERE id = ?"
            );
            foreach ($ohneArchiv as $bid) {
                $name = $ohneArchivNamen[$bid] ?? ('Projekt #' . $bid);
                DataService::saveBaustelle($this->db, ['id' => $bid, 'name' => $name, 'kundeId' => null]);
                $updPh->execute([date('Y-m-d H:i:s'), 'system-reparatur', $bid]);
                $platzhalter++;
            }

            // Projektnamen nachziehen, damit die Auswertung sie auch ohne Join zeigt.
            $st = $this->db->prepare("
                UPDATE zeiterfassung
                   SET baustelleName = COALESCE((SELECT b.name FROM baustellen b WHERE b.id = zeiterfassung.baustelleId), '')
                 WHERE baustelleName = ''
                   AND baustelleId IS NOT NULL
                   AND EXISTS (SELECT 1 FROM baustellen b WHERE b.id = zeiterfassung.baustelleId)
            ");
            $st->execute();
            $namen = $st->rowCount();

            // ZE005 (Projekt existiert nicht mehr) auflösen, wo das Projekt wieder da ist.
            $ss = $this->db->prepare("
                UPDATE zeiterfassung
                   SET status = 'booked_valid', errorCode = '', errorMessage = ''
                 WHERE errorCode = 'ZE005'
                   AND baustelleId IS NOT NULL
                   AND EXISTS (SELECT 1 FROM baustellen b WHERE b.id = zeiterfassung.baustelleId)
            ");
            $ss->execute();
            $status = $ss->rowCount();

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[bk] repair_archiv_projekte fehlgeschlagen: ' . $e->getMessage());
            jsonOut(['error' => 'Reparatur fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        jsonOut([
            'ok'                      => true,
            'dryRun'                  => false,
            'wiederhergestellt'       => $restored,
            'platzhalterAngelegt'     => $platzhalter,
            'projektnamenNachgetragen'=> $namen,
            'warnungenAufgeloest'     => $status,
            'ohneArchivDatei'         => $ohneArchiv,
        ]);
    }

    /** load_auswertung_archives – Archivierte Baustellen-Daten fuer Auswertung */
    public function loadAuswertungArchives(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeAuswertung')) jsonOut(['error' => 'Keine Berechtigung.'], 403);
        $files = glob(ARCHIVE_DIR . '*.json') ?: [];
        $archives = [];
        foreach ($files as $f) {
            $snap = json_decode(file_get_contents($f), true);
            if (!$snap || !isset($snap['baustelle'])) continue;
            $b = $snap['baustelle'];
            $baseName = basename($f, '.json');

            // Finanzkennzahlen berechnen – defensiv: fehlende Felder (z.B. 'ek')
            // dürfen keine Warnung auslösen, da der globale Error-Handler daraus
            // eine Exception macht und sonst die ganze Archiv-Auswertung (500) kippt.
            $matVk = 0; $matEk = 0;
            $globalAufschlag = ($b['materialAufschlagGlobal'] ?? 0) / 100;
            foreach ($b['material'] ?? [] as $m) {
                if (!is_array($m)) continue;
                $ek       = (float)($m['ek'] ?? 0);
                $rabattF  = 1 - ((float)($m['rabatt'] ?? 0)) / 100;
                $anzahl   = (float)($m['anzahl'] ?? 1);
                if (!empty($m['vkDirekt'])) {
                    $matVk += $ek * $rabattF * $anzahl;
                } else {
                    $aufschlag = ((float)($m['aufschlag'] ?? 0)) / 100;
                    $matVk += $ek * (1 + $aufschlag) * (1 + $globalAufschlag) * $rabattF * $anzahl;
                }
                $matEk += $ek * $anzahl;
            }
            $azEin = 0; $azFk = 0;
            foreach ($b['arbeitszeit'] ?? [] as $a) {
                if (!is_array($a)) continue;
                $azEin += ($a['stunden'] ?? 0) * ($a['stundenpreis'] ?? 0);
                $azFk  += ($a['stunden'] ?? 0) * ($a['fixkosten'] ?? 0);
            }
            $pSum = 0;
            $pauschalen = $snap['pauschalen'] ?? [];
            foreach ($b['pauschalen'] ?? [] as $entry) {
                $pid = is_array($entry) ? ($entry['id'] ?? 0) : $entry;
                $anz = is_array($entry) ? ($entry['anzahl'] ?? 1) : 1;
                foreach ($pauschalen as $p) {
                    if ((int)($p['id'] ?? 0) === (int)$pid) { $pSum += ($p['preis'] ?? 0) * $anz; break; }
                }
            }
            $abSum = 0;
            foreach ($b['abschlaege'] ?? [] as $a) { $abSum += (is_array($a) ? ($a['betrag'] ?? 0) : 0); }

            $gesamt = $matVk + $azEin + $pSum;
            $gewinn = ($matVk - $matEk) + ($azEin - $azFk) + $pSum;

            $archives[] = [
                'file'       => $baseName,
                'name'       => $b['name'] ?? 'Unbekannt',
                'projektNr'  => $b['projektNr'] ?? '',
                'archivedAt' => $snap['archivedAt'] ?? '',
                'matVk'      => round($matVk, 2),
                'matEk'      => round($matEk, 2),
                'azEin'      => round($azEin, 2),
                'azFk'       => round($azFk, 2),
                'pSum'       => round($pSum, 2),
                'abSum'      => round($abSum, 2),
                'gesamt'     => round($gesamt, 2),
                'gewinn'     => round($gewinn, 2),
                'offen'      => round($gesamt - $abSum, 2),
                'includeInAuswertung' => $b['includeInAuswertung'] ?? true,
                'kundeId'    => $b['kundeId'] ?? null,
            ];
        }
        jsonOut(['ok' => true, 'archives' => $archives]);
    }

    /** view_archive – Snapshot eines archivierten Projekts für Read-Only-Ansicht */
    public function viewArchive(): void
    {
        Auth::requireRole('admin', 'master');
        $fileBase = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/u', '', $this->body['file'] ?? $_GET['file'] ?? '');
        if (!$fileBase) jsonOut(['error' => 'Kein Dateiname.'], 400);
        $jsonPath = ARCHIVE_DIR . $fileBase . '.json';
        if (!file_exists($jsonPath)) jsonOut(['error' => 'Archiv nicht gefunden.'], 404);
        $snap = json_decode(file_get_contents($jsonPath), true);
        if (!$snap || !isset($snap['baustelle'])) jsonOut(['error' => 'Ungültiges Archiv-Format.'], 400);
        jsonOut([
            'ok'         => true,
            'baustelle'  => $snap['baustelle'],
            'pauschalen' => $snap['pauschalen'] ?? [],
            'archivedAt' => $snap['archivedAt'] ?? '',
            'archivedBy' => $snap['archivedBy'] ?? '',
        ]);
    }

    /** toggle_auswertung_flag – In/aus Auswertung fuer aktive Baustelle */
    public function toggleAuswertungFlag(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeAuswertung')) jsonOut(['error' => 'Keine Berechtigung.'], 403);
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $include     = (bool)($this->body['include'] ?? true);
        if (!$baustelleId) jsonOut(['error' => 'Keine Baustellen-ID.'], 400);

        $row = $this->db->prepare("SELECT data FROM baustellen WHERE id = ?");
        $row->execute([$baustelleId]);
        $bRow = $row->fetch();
        if (!$bRow) jsonOut(['error' => 'Baustelle nicht gefunden.'], 404);

        $bData = json_decode($bRow['data'], true) ?: [];
        $bData['includeInAuswertung'] = $include;
        $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                 ->execute([json_encode($bData, JSON_UNESCAPED_UNICODE), $baustelleId]);
        jsonOut(['ok' => true]);
    }

    /** mark_ordered */
    public function markOrdered(): void
    {
        // Admin/Master ODER User mit canOrderMaterial-Berechtigung darf markieren
        Auth::requireAuth();
        if (!in_array($_SESSION['role'] ?? 'normal', ['admin', 'master'])) {
            $perms = Auth::getCurrentUserPerms($this->db);
            if (empty($perms['canOrderMaterial'])) {
                jsonOut(['error' => 'Keine Berechtigung für diese Aktion.'], 403);
            }
        }
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $materialIds = $this->body['materialIds'] ?? [];
        if (!$baustelleId || empty($materialIds)) jsonOut(['error' => 'Fehlende Parameter.'], 400);

        $row = $this->db->prepare("SELECT data FROM baustellen WHERE id = ?");
        $row->execute([$baustelleId]);
        $bRow = $row->fetch();
        if (!$bRow) jsonOut(['error' => 'Baustelle nicht gefunden.'], 404);

        $bData   = json_decode($bRow['data'], true) ?: [];
        $today   = date('Y-m-d');
        $updated = false;
        foreach ($bData['fehlendesMaterial'] ?? [] as &$m) {
            if (in_array($m['id'], $materialIds) && ($m['status'] ?? 'offen') === 'offen') {
                $m['status']       = 'bestellt';
                $m['bestellDatum'] = $today;
                $m['bestelltVon']  = $_SESSION['username'];
                $updated = true;
            }
        }
        unset($m);
        if ($updated) {
            $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                     ->execute([json_encode($bData, JSON_UNESCAPED_UNICODE), $baustelleId]);
        }
        jsonOut(['ok' => true]);
    }

    /** archive_offenes_material */
    public function archiveOffenesMaterial(): void
    {
        Auth::requireRole('admin', 'master');
        $excelBase64 = $this->body['excelBase64'] ?? '';
        if (!$excelBase64) jsonOut(['error' => 'Keine Excel-Daten.'], 400);

        $archiveDir = DATA_DIR . 'archiv_material/';
        if (!is_dir($archiveDir)) mkdir($archiveDir, 0750, true);
        $fileName = 'OffenesMaterial_' . date('Y-m-d_His') . '.xlsx';
        file_put_contents($archiveDir . $fileName, base64_decode($excelBase64));

        // fehlendesMaterial in allen aktiven Baustellen leeren (archivierte bleiben eingefroren)
        $rows = $this->db->query("SELECT id, data FROM baustellen WHERE archiviert = 0")->fetchAll();
        foreach ($rows as $r) {
            $d = json_decode($r['data'], true) ?: [];
            $d['fehlendesMaterial'] = [];
            $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")
                     ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $r['id']]);
        }
        jsonOut(['ok' => true, 'file' => $fileName]);
    }

    /** save_sub_sort_order – Sortierreihenfolge von Unterprojekten speichern.
     *  Body: { parentId: int, order: [id, id, ...] }
     *  Schreibt sortPos in das JSON-data-Feld jedes betroffenen Unterprojekts.
     */
    public function saveSubSortOrder(): void
    {
        Auth::requireRole('admin', 'master', 'normal');
        $parentId = (int)($this->body['parentId'] ?? 0);
        $order    = $this->body['order'] ?? [];
        if (!$parentId || !is_array($order) || empty($order)) {
            jsonOut(['error' => 'Ungültige Parameter.'], 400);
        }

        $stmt = $this->db->prepare("SELECT id, data FROM baustellen WHERE id = ? AND JSON_EXTRACT(data,'$.parentId') = ?");
        $upd  = $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?");

        $this->db->beginTransaction();
        try {
            foreach ($order as $pos => $subId) {
                $subId = (int)$subId;
                $stmt->execute([$subId, $parentId]);
                $row = $stmt->fetch();
                if (!$row) continue;
                $d = json_decode($row['data'], true) ?: [];
                $d['sortPos'] = $pos;
                $upd->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $subId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            jsonOut(['error' => 'Sortierung konnte nicht gespeichert werden: ' . $e->getMessage()], 500);
        }
        jsonOut(['ok' => true]);
    }

    /** move_position
     *
     * A.2: Kaskadiert das Verschieben eines auto-importierten Arbeitszeit-Eintrags
     * zwischen Baustellen auf die zugehörige Zeiterfassung. Die eigentliche
     * Positions-Verschiebung im Baustellen-JSON erfolgt clientseitig via Full-Replace
     * (save). Hier wird nur die verknüpfte zeiterfassung.baustelleId aktualisiert.
     *
     * Erwartet: { zeitEntryId, zeitUser, destBaustelleId }
     */
    public function movePosition(): void
    {
        Auth::requireRole('admin', 'master', 'normal');

        $zeitEntryId     = (int)($this->body['zeitEntryId'] ?? 0);
        $zeitUser        = trim((string)($this->body['zeitUser'] ?? ''));
        $destBaustelleId = isset($this->body['destBaustelleId']) && $this->body['destBaustelleId'] !== null
            ? (int)$this->body['destBaustelleId']
            : null;

        // Ohne verknüpften Zeiterfassungs-Eintrag gibt es nichts zu kaskadieren.
        if ($zeitEntryId <= 0 || $zeitUser === '') {
            jsonOut(['ok' => true, 'updated' => 0]);
        }

        // Berechtigung: Admin/Master dürfen fremde Einträge umhängen; normale
        // Benutzer nur ihre eigenen Zeiterfassungs-Einträge.
        $role        = $_SESSION['role'] ?? 'normal';
        $currentUser = (string)($_SESSION['username'] ?? '');
        if ($role !== 'admin' && $role !== 'master' && strcasecmp($zeitUser, $currentUser) !== 0) {
            jsonOut(['error' => 'Keine Berechtigung, fremde Zeiterfassung zu verschieben.'], 403);
        }

        try {
            $old = \App\Database::fetchOne(
                $this->db,
                "SELECT datum, baustelleId, baustelleName FROM zeiterfassung
                  WHERE entryId = ? AND username = ? COLLATE NOCASE",
                [$zeitEntryId, $zeitUser]
            );
            $neuName = '';
            if ($destBaustelleId !== null) {
                $bn = \App\Database::fetchOne($this->db, "SELECT name FROM baustellen WHERE id = ?", [$destBaustelleId]);
                $neuName = (string)($bn['name'] ?? '');
            }
            $now = date('Y-m-d H:i:s');

            $stmt = $this->db->prepare(
                "UPDATE zeiterfassung SET baustelleId = :bid, baustelleName = :bname, updatedAt = :now
                  WHERE entryId = :eid AND username = :user COLLATE NOCASE"
            );
            $stmt->execute([
                ':bid'   => $destBaustelleId,
                ':bname' => $neuName,
                ':now'   => $now,
                ':eid'   => $zeitEntryId,
                ':user'  => $zeitUser,
            ]);

            // Umbuchung lückenlos protokollieren (alter/neuer Projektbezug, Bearbeiter).
            $altBId = $old && $old['baustelleId'] !== null ? (int)$old['baustelleId'] : null;
            if ($old && $altBId !== $destBaustelleId) {
                $this->db->prepare(
                    "INSERT INTO zeiterfassung_log (username, entryId, aktion, alteWerte, neueWerte, geaendertVon, geaendertAm)
                     VALUES (?,?,'move',?,?,?,?)"
                )->execute([
                    $zeitUser,
                    $zeitEntryId,
                    json_encode(['baustelleId' => $altBId, 'baustelleName' => (string)($old['baustelleName'] ?? '')], JSON_UNESCAPED_UNICODE),
                    json_encode(['baustelleId' => $destBaustelleId, 'baustelleName' => $neuName], JSON_UNESCAPED_UNICODE),
                    $currentUser,
                    $now,
                ]);
            }
        } catch (\Throwable $e) {
            jsonOut(['error' => 'Zeiterfassung konnte nicht aktualisiert werden: ' . $e->getMessage()], 500);
        }

        jsonOut(['ok' => true, 'updated' => $stmt->rowCount()]);
    }
}
