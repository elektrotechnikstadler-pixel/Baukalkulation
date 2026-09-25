<?php
// ============================================================
// Aufmaß-Modul – Backend (v2.1, Phase 3A)
// ============================================================
// Endpoints: list, load, save (Aufmaß+Abschnitte+Positionen),
// delete, status (entwurf/geprueft/uebernommen), uebernehmen
// (liefert Positionsdaten zur Übernahme ins Projektmaterial,
// bucht ggf. Lagerbestand ab, sperrt Aufmaß).
// ============================================================

namespace App\Modules\Aufmass;

use App\Core\AbstractModule;
use App\Auth;
use App\Services\AuditService;

require_once __DIR__ . '/../AufmassDatabase.php';

class Module extends AbstractModule
{
    public static function migrate(\PDO $db): void
    {
        AufmassDatabase::init($db);
    }

    public function dispatch(string $action): void
    {
        $settings = Auth::loadSettings($this->db);
        if (isset($settings['modul_aufmass']) && $settings['modul_aufmass'] === false) {
            \jsonOut(['error' => 'Modul Aufmaß ist deaktiviert.'], 403);
        }

        switch ($action) {
            case 'ping':
                \jsonOut(['ok' => true, 'module' => 'aufmass', 'version' => $this->manifest['version'] ?? '0.1.0']);
                break;

            case 'list':        $this->requirePerm('canReadAufmass');  $this->listAufmasse();    break;
            case 'load':        $this->requirePerm('canReadAufmass');  $this->loadAufmass();     break;
            case 'save':        $this->requirePerm('canWriteAufmass'); $this->saveAufmass();     break;
            case 'delete':      $this->requirePerm('canWriteAufmass'); $this->deleteAufmass();   break;
            case 'status':      $this->statusChange();                                            break;
            case 'uebernehmen': $this->requirePerm('canApproveAufmass'); $this->uebernehmen();   break;

            // ── Foto-OCR für Positionserkennung ──────────────
            case 'foto_ocr': $this->requirePerm('canReadAufmass'); $this->fotoOcr(); break;

            default:
                \jsonOut(['error' => 'Unbekannte Aufmaß-Aktion: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 400);
        }
    }

    private function requirePerm(string $key): void
    {
        if (!Auth::canDo($this->db, $key)) {
            \jsonOut(['error' => "Keine Berechtigung: $key"], 403);
        }
    }

    private function audit(string $sub, string $details): void
    {
        try { AuditService::log('aufmass.' . $sub, $details); } catch (\Throwable $e) { /* ignore */ }
    }

    private function getAufmassRow(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM aufmass WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── List / Load ───────────────────────────────────────
    private function listAufmasse(): void
    {
        $bId = isset($_GET['baustelleId']) ? (int)$_GET['baustelleId'] : 0;
        $sql = "SELECT a.id, a.baustelle_id, a.titel, a.status, a.erstellt_von, a.erstellt_am,
                       a.geprueft_von, a.geprueft_am, a.uebernommen_am,
                       (SELECT COUNT(*) FROM aufmass_position p WHERE p.aufmass_id = a.id) AS anzahl_positionen,
                       (SELECT COALESCE(SUM(p.menge * COALESCE(p.einzelpreis,0)),0) FROM aufmass_position p WHERE p.aufmass_id = a.id AND p.ausgewaehlt = 1) AS summe
                FROM aufmass a";
        $params = [];
        if ($bId > 0) { $sql .= " WHERE a.baustelle_id = ?"; $params[] = $bId; }
        $sql .= " ORDER BY a.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['baustelle_id'] = $r['baustelle_id'] === null ? null : (int)$r['baustelle_id'];
            $r['anzahl_positionen'] = (int)$r['anzahl_positionen'];
            // v2.9.18: SUM(menge*einzelpreis) liefert Float-Rauschen -> normieren
            $r['summe'] = \money_round($r['summe']);
        }
        \jsonOut(['ok' => true, 'aufmasse' => $rows]);
    }

    private function loadAufmass(): void
    {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Ungültige ID.'], 400);
        $a = $this->getAufmassRow($id);
        if (!$a) \jsonOut(['error' => 'Aufmaß nicht gefunden.'], 404);
        $a['id'] = (int)$a['id'];
        $a['baustelle_id'] = $a['baustelle_id'] === null ? null : (int)$a['baustelle_id'];

        $st = $this->db->prepare("SELECT id, name, sortier FROM aufmass_abschnitt WHERE aufmass_id=? ORDER BY sortier, id");
        $st->execute([$id]);
        $abschnitte = array_map(function($r){ return ['id'=>(int)$r['id'], 'name'=>$r['name'], 'sortier'=>(int)$r['sortier']]; }, $st->fetchAll(\PDO::FETCH_ASSOC));

        $st = $this->db->prepare("SELECT * FROM aufmass_position WHERE aufmass_id=? ORDER BY sortier, id");
        $st->execute([$id]);
        $positionen = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $positionen[] = [
                'id'           => (int)$p['id'],
                'abschnitt_id' => $p['abschnitt_id'] === null ? null : (int)$p['abschnitt_id'],
                'bezeichnung'  => $p['bezeichnung'],
                'formel'       => $p['formel'],
                'menge'        => $p['menge'] === null ? null : (float)$p['menge'],
                'einheit'      => $p['einheit'],
                // v2.9.18: Geldbetraege auf 2 NK normieren
                'einzelpreis'  => $p['einzelpreis'] === null ? null : \money_round($p['einzelpreis']),
                'ek'           => $p['ek'] === null ? null : \money_round($p['ek']),
                'ref_typ'      => $p['ref_typ'],
                'ref_id'       => $p['ref_id'],
                'ausgewaehlt'  => (int)$p['ausgewaehlt'],
                'sortier'      => (int)$p['sortier'],
                'notiz'        => $p['notiz'],
            ];
        }
        \jsonOut(['ok' => true, 'aufmass' => $a, 'abschnitte' => $abschnitte, 'positionen' => $positionen]);
    }

    // ── Save (komplettes Aufmaß) ──────────────────────────
    private function saveAufmass(): void
    {
        $b = $this->body;
        $id = (int)($b['id'] ?? 0);
        $titel = trim((string)($b['titel'] ?? ''));
        $baustelleId = isset($b['baustelle_id']) && $b['baustelle_id'] !== '' ? (int)$b['baustelle_id'] : null;
        $notiz = (string)($b['notiz'] ?? '');
        $abschnitte = is_array($b['abschnitte'] ?? null) ? $b['abschnitte'] : [];
        $positionen = is_array($b['positionen'] ?? null) ? $b['positionen'] : [];

        if ($titel === '') \jsonOut(['error' => 'Titel ist erforderlich.'], 400);
        if (mb_strlen($titel) > 200) \jsonOut(['error' => 'Titel zu lang.'], 400);

        if ($id > 0) {
            $a = $this->getAufmassRow($id);
            if (!$a) \jsonOut(['error' => 'Aufmaß nicht gefunden.'], 404);
            if ($a['status'] === 'uebernommen') \jsonOut(['error' => 'Aufmaß ist bereits übernommen und gesperrt.'], 400);
        }

        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $st = $this->db->prepare("UPDATE aufmass SET titel=?, baustelle_id=?, notiz=? WHERE id=?");
                $st->execute([$titel, $baustelleId, $notiz, $id]);
            } else {
                $st = $this->db->prepare("INSERT INTO aufmass(titel, baustelle_id, notiz, erstellt_von) VALUES (?,?,?,?)");
                $st->execute([$titel, $baustelleId, $notiz, $this->user]);
                $id = (int)$this->db->lastInsertId();
            }

            // Abschnitte upsert + cleanup
            $existingAbsIds = [];
            $st = $this->db->prepare("SELECT id FROM aufmass_abschnitt WHERE aufmass_id=?");
            $st->execute([$id]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $aid) $existingAbsIds[(int)$aid] = true;

            $absIdMap = []; // tempId(<0) → realId für Positionen-Mapping
            foreach ($abschnitte as $idx => $a) {
                $aId = (int)($a['id'] ?? 0);
                $name = trim((string)($a['name'] ?? ''));
                $sort = (int)($a['sortier'] ?? $idx);
                if ($aId > 0 && isset($existingAbsIds[$aId])) {
                    $st2 = $this->db->prepare("UPDATE aufmass_abschnitt SET name=?, sortier=? WHERE id=?");
                    $st2->execute([$name, $sort, $aId]);
                    unset($existingAbsIds[$aId]);
                } else {
                    $st2 = $this->db->prepare("INSERT INTO aufmass_abschnitt(aufmass_id, name, sortier) VALUES (?,?,?)");
                    $st2->execute([$id, $name, $sort]);
                    $newId = (int)$this->db->lastInsertId();
                    if ($aId < 0) $absIdMap[$aId] = $newId;
                }
            }
            if (!empty($existingAbsIds)) {
                $del = $this->db->prepare("DELETE FROM aufmass_abschnitt WHERE id=?");
                foreach (array_keys($existingAbsIds) as $oid) $del->execute([$oid]);
            }

            // Positionen upsert + cleanup
            $existingPosIds = [];
            $st = $this->db->prepare("SELECT id FROM aufmass_position WHERE aufmass_id=?");
            $st->execute([$id]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $pid) $existingPosIds[(int)$pid] = true;

            foreach ($positionen as $idx => $p) {
                $pId = (int)($p['id'] ?? 0);
                $absRef = isset($p['abschnitt_id']) && $p['abschnitt_id'] !== null && $p['abschnitt_id'] !== '' ? (int)$p['abschnitt_id'] : null;
                if ($absRef !== null && $absRef < 0 && isset($absIdMap[$absRef])) $absRef = $absIdMap[$absRef];
                $bez = trim((string)($p['bezeichnung'] ?? ''));
                $formel = (string)($p['formel'] ?? '');
                $menge = isset($p['menge']) ? (float)$p['menge'] : 0;
                $einheit = (string)($p['einheit'] ?? '');
                $einzel = isset($p['einzelpreis']) && $p['einzelpreis'] !== '' && $p['einzelpreis'] !== null ? (float)$p['einzelpreis'] : null;
                $ek = isset($p['ek']) && $p['ek'] !== '' && $p['ek'] !== null ? (float)$p['ek'] : null;
                $refTyp = $p['ref_typ'] ?? null;
                $refId = $p['ref_id'] ?? null;
                $aus = !empty($p['ausgewaehlt']) ? 1 : 0;
                $sort = (int)($p['sortier'] ?? $idx);
                $notiz = (string)($p['notiz'] ?? '');

                if ($pId > 0 && isset($existingPosIds[$pId])) {
                    $st2 = $this->db->prepare("UPDATE aufmass_position SET abschnitt_id=?, bezeichnung=?, formel=?, menge=?, einheit=?, einzelpreis=?, ek=?, ref_typ=?, ref_id=?, ausgewaehlt=?, sortier=?, notiz=? WHERE id=?");
                    $st2->execute([$absRef, $bez, $formel, $menge, $einheit, $einzel, $ek, $refTyp, $refId, $aus, $sort, $notiz, $pId]);
                    unset($existingPosIds[$pId]);
                } else {
                    $st2 = $this->db->prepare("INSERT INTO aufmass_position(aufmass_id, abschnitt_id, bezeichnung, formel, menge, einheit, einzelpreis, ek, ref_typ, ref_id, ausgewaehlt, sortier, notiz) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $st2->execute([$id, $absRef, $bez, $formel, $menge, $einheit, $einzel, $ek, $refTyp, $refId, $aus, $sort, $notiz]);
                }
            }
            if (!empty($existingPosIds)) {
                $del = $this->db->prepare("DELETE FROM aufmass_position WHERE id=?");
                foreach (array_keys($existingPosIds) as $oid) $del->execute([$oid]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \jsonOut(['error' => 'Speicherfehler: ' . $e->getMessage()], 500);
        }

        $this->audit('save', "id=$id titel=$titel pos=" . count($positionen));
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function deleteAufmass(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Ungültige ID.'], 400);
        $a = $this->getAufmassRow($id);
        if (!$a) \jsonOut(['error' => 'Nicht gefunden.'], 404);
        if ($a['status'] === 'uebernommen') \jsonOut(['error' => 'Übernommenes Aufmaß kann nicht gelöscht werden.'], 400);
        $st = $this->db->prepare("DELETE FROM aufmass WHERE id=?");
        $st->execute([$id]);
        $this->audit('delete', "id=$id");
        \jsonOut(['ok' => true]);
    }

    // ── Statuswechsel (entwurf <-> geprueft) ─────────────
    private function statusChange(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $newStatus = (string)($this->body['status'] ?? '');
        if (!in_array($newStatus, ['entwurf','geprueft'], true)) \jsonOut(['error' => 'Ungültiger Statuswechsel über diesen Endpoint.'], 400);
        $a = $this->getAufmassRow($id);
        if (!$a) \jsonOut(['error' => 'Nicht gefunden.'], 404);
        if ($a['status'] === 'uebernommen') \jsonOut(['error' => 'Aufmaß ist gesperrt.'], 400);

        if ($newStatus === 'geprueft') {
            if (!Auth::canDo($this->db, 'canApproveAufmass')) \jsonOut(['error' => 'Keine Berechtigung zur Prüfung.'], 403);
            $st = $this->db->prepare("UPDATE aufmass SET status='geprueft', geprueft_von=?, geprueft_am=CURRENT_TIMESTAMP WHERE id=?");
            $st->execute([$this->user, $id]);
        } else {
            if (!Auth::canDo($this->db, 'canWriteAufmass')) \jsonOut(['error' => 'Keine Berechtigung.'], 403);
            $st = $this->db->prepare("UPDATE aufmass SET status='entwurf', geprueft_von=NULL, geprueft_am=NULL WHERE id=?");
            $st->execute([$id]);
        }
        $this->audit('status', "id=$id status=$newStatus");
        \jsonOut(['ok' => true]);
    }

    // ── Übernahme: liefert Positionsdaten + bucht ggf. Lager ab ─
    private function uebernehmen(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $positionIds = is_array($this->body['positionIds'] ?? null) ? array_map('intval', $this->body['positionIds']) : [];
        $a = $this->getAufmassRow($id);
        if (!$a) \jsonOut(['error' => 'Aufmaß nicht gefunden.'], 404);
        if ($a['status'] === 'uebernommen') \jsonOut(['error' => 'Bereits übernommen.'], 400);
        if (empty($positionIds)) \jsonOut(['error' => 'Keine Positionen ausgewählt.'], 400);

        $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
        $st = $this->db->prepare("SELECT * FROM aufmass_position WHERE aufmass_id=? AND id IN ($placeholders)");
        $st->execute(array_merge([$id], $positionIds));
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) \jsonOut(['error' => 'Keine passenden Positionen gefunden.'], 400);

        $this->db->beginTransaction();
        $lagerWarnungen = [];
        try {
            foreach ($rows as $p) {
                if (($p['ref_typ'] ?? '') === 'lager' && $p['ref_id']) {
                    $artikelId = (int)$p['ref_id'];
                    $menge = (float)$p['menge'];
                    if ($menge > 0) {
                        $stArt = $this->db->prepare("SELECT id, bezeichnung, menge FROM lager_artikel WHERE id=?");
                        $stArt->execute([$artikelId]);
                        $art = $stArt->fetch(\PDO::FETCH_ASSOC);
                        if ($art) {
                            $neu = (float)$art['menge'] - $menge;
                            if ($neu < 0) {
                                $lagerWarnungen[] = "Lager: {$art['bezeichnung']} (verfügbar " . (float)$art['menge'] . ", angefordert $menge) – nicht abgebucht.";
                            } else {
                                $stUpd = $this->db->prepare("UPDATE lager_artikel SET menge=?, geaendert_am=CURRENT_TIMESTAMP WHERE id=?");
                                $stUpd->execute([$neu, $artikelId]);
                                try { AuditService::log('lager.buchung', "artikelId=$artikelId delta=-$menge grund=Aufmass-Uebernahme aufmassId=$id"); } catch (\Throwable $e) {}
                            }
                        }
                    }
                }
            }

            $st = $this->db->prepare("UPDATE aufmass SET status='uebernommen', uebernommen_am=CURRENT_TIMESTAMP WHERE id=?");
            $st->execute([$id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \jsonOut(['error' => 'Übernahme fehlgeschlagen: ' . $e->getMessage()], 500);
        }

        $this->audit('uebernahme', "id=$id positionen=" . count($rows));

        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                'id' => (int)$p['id'],
                'bezeichnung' => $p['bezeichnung'],
                'menge' => (float)$p['menge'],
                'einheit' => $p['einheit'],
                'einzelpreis' => $p['einzelpreis'] === null ? null : (float)$p['einzelpreis'],
                'ek' => $p['ek'] === null ? null : (float)$p['ek'],
                'ref_typ' => $p['ref_typ'],
                'ref_id' => $p['ref_id'],
                'notiz' => $p['notiz'],
            ];
        }
        \jsonOut(['ok' => true, 'baustelle_id' => $a['baustelle_id'] === null ? null : (int)$a['baustelle_id'], 'positionen' => $out, 'lager_warnungen' => $lagerWarnungen]);
    }

    // ── Foto-OCR ──────────────────────────────────────────────────────────────
    /**
     * Empfängt ein Bild (multipart/form-data, Feld "foto"),
     * führt Tesseract-OCR aus und gibt Datanorm-Treffer zurück.
     * Die Temp-Datei wird sofort nach der OCR-Verarbeitung gelöscht.
     */
    private function fotoOcr(): void
    {
        if (!function_exists('feature_enabled') || !\feature_enabled('ocr')) {
            \jsonOut(['ok' => true, 'text' => '', 'datanorm_treffer' => [], 'hint' => 'OCR ist auf diesem Server nicht verfügbar (Tesseract nicht installiert).']);
        }

        if (empty($_FILES['foto']) || !isset($_FILES['foto']['tmp_name'])) {
            \jsonOut(['error' => 'Kein Bild übermittelt.'], 400);
        }
        $file = $_FILES['foto'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            \jsonOut(['error' => 'Upload-Fehler (Code ' . (int)$file['error'] . ').'], 400);
        }
        if ($file['size'] > 8 * 1024 * 1024) {
            \jsonOut(['error' => 'Bild zu groß (max. 8 MB).'], 400);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/tiff'];
        $mime = '';
        if (class_exists('finfo')) {
            $fi   = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($file['tmp_name']);
        }
        if (!in_array($mime, $allowed, true)) {
            \jsonOut(['error' => 'Nur Rasterbilder erlaubt (JPG, PNG, WebP, GIF, BMP, TIFF).'], 400);
        }

        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif' => 'gif', 'image/bmp' => 'bmp', 'image/tiff' => 'tiff',
        ];
        $ext     = $extMap[$mime] ?? 'jpg';
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aufmass_ocr_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            \jsonOut(['error' => 'Bild konnte nicht verarbeitet werden.'], 500);
        }

        // Bild vorverarbeiten (GD) für bessere OCR-Qualität
        $processedPath = null;
        if (extension_loaded('gd')) {
            try {
                $img = match ($mime) {
                    'image/jpeg' => @imagecreatefromjpeg($tmpPath),
                    'image/png'  => @imagecreatefrompng($tmpPath),
                    'image/webp' => @imagecreatefromwebp($tmpPath),
                    'image/gif'  => @imagecreatefromgif($tmpPath),
                    'image/bmp'  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($tmpPath) : false,
                    default      => false,
                };
                if ($img !== false && $img !== null) {
                    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
                    // EXIF-Rotation korrigieren – mobile Kamerafotos sind häufig 90°/270° gedreht gespeichert
                    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                        $exif   = @exif_read_data($tmpPath);
                        $orient = (int)($exif['Orientation'] ?? 1);
                        $rotDeg = match ($orient) { 3 => 180, 6 => 270, 8 => 90, default => 0 };
                        if ($rotDeg !== 0) {
                            $rot = imagerotate($img, $rotDeg, 0);
                            if ($rot !== false) { imagedestroy($img); $img = $rot; }
                        }
                    }
                    $w = imagesx($img); $h = imagesy($img);
                    if ($w > 0 && $h > 0 && max($w, $h) < 1200) {
                        $scale = (int)ceil(1200 / max($w, $h));
                        $big   = imagescale($img, $w * $scale, $h * $scale, IMG_BICUBIC);
                        if ($big !== false) { imagedestroy($img); $img = $big; }
                    }
                    imagefilter($img, IMG_FILTER_GRAYSCALE);
                    imagefilter($img, IMG_FILTER_CONTRAST, -20);
                    imagefilter($img, IMG_FILTER_SHARPEN);
                    $processedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_p_' . bin2hex(random_bytes(4)) . '.png';
                    imagepng($img, $processedPath, 3);
                    imagedestroy($img);
                    // Safeguard: leere Datei nicht verwenden
                    if (!file_exists($processedPath) || filesize($processedPath) < 100) {
                        if ($processedPath && file_exists($processedPath)) @unlink($processedPath);
                        $processedPath = null;
                    }
                }
            } catch (\Throwable) {
                $processedPath = null;
            }
        }

        // OCR via Tesseract
        // --psm 6: einheitlicher Textblock (gut für Etiketten)  --oem 1: LSTM-Netz
        $text = '';
        $ocrInput = $processedPath ?? $tmpPath;
        try {
            $escaped = escapeshellarg($ocrInput);
            $raw = (string)shell_exec("timeout 30 tesseract $escaped stdout --psm 3 --oem 1 -l deu+eng 2>/dev/null");
            if (trim($raw) === '') {
                // Fallback: sparse text (--psm 11) für spärliche Beschriftungen
                $raw = (string)shell_exec("timeout 30 tesseract $escaped stdout --psm 11 --oem 1 -l deu+eng 2>/dev/null");
            }
            $text = mb_substr(trim($raw), 0, 2000, 'UTF-8');
        } finally {
            if (file_exists($tmpPath)) @unlink($tmpPath);
            if ($processedPath && file_exists($processedPath)) @unlink($processedPath);
        }

        // OCR-Text bereinigen: Zeilen mit überwiegend Sonderzeichen entfernen
        if ($text !== '') {
            $lines = explode("\n", $text);
            $lines = array_filter($lines, function (string $l): bool {
                $l = trim($l);
                if (mb_strlen($l) < 2) return false;
                $clean = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß\s\-\.]/u', '', $l);
                return mb_strlen($clean ?? '') > mb_strlen($l) * 0.15;
            });
            $text = implode("\n", array_values($lines));
        }

        if ($text === '') {
            \jsonOut(['ok' => true, 'text' => '', 'datanorm_treffer' => [], 'hint' => 'Kein Text erkannt. Bitte schärferes Bild, gute Beleuchtung und scharfen Fokus verwenden.']);
        }

        // Tokens extrahieren: Artikelnummer-Kandidaten (mit Ziffern) zuerst
        $rawTokens = array_unique(array_filter(
            preg_split('/[\s\n\r,;:]+/u', $text) ?: [],
            fn($t) => mb_strlen($t) >= 2 && preg_match('/\w/u', $t)
        ));
        $artNrTokens = array_values(array_filter($rawTokens, fn($t) => preg_match('/\d/', $t) && mb_strlen($t) >= 3));
        $textTokens  = array_values(array_filter($rawTokens, fn($t) => !preg_match('/\d/', $t) && mb_strlen($t) >= 3));
        $tokens = array_slice(array_merge($artNrTokens, $textTokens), 0, 10);

        if (empty($tokens)) {
            \jsonOut(['ok' => true, 'text' => $text, 'datanorm_treffer' => [], 'hint' => 'Keine auswertbaren Tokens im erkannten Text.']);
        }

        $datanormTreffer = [];
        $indexFile = defined('DATA_DIR') ? DATA_DIR . 'datanorm_index.tsv' : '';
        if ($indexFile && file_exists($indexFile)) {
            $fh = @fopen($indexFile, 'r');
            if ($fh) {
                $count = 0;
                while ($count < 15 && ($line = fgets($fh)) !== false) {
                    $cols = explode("\t", rtrim($line, "\r\n"));
                    if (count($cols) < 3) continue;
                    $artNr = $cols[0];
                    $bez   = $cols[1];
                    $haystack = mb_strtolower($artNr . ' ' . $bez, 'UTF-8');
                    $matchAny = false;
                    foreach ($tokens as $tok) {
                        if (mb_strpos($haystack, mb_strtolower($tok, 'UTF-8')) !== false) {
                            $matchAny = true;
                            break;
                        }
                    }
                    if (!$matchAny) continue;
                    $datanormTreffer[] = [
                        'artNr'       => $artNr,
                        'bezeichnung' => $bez,
                        'einheit'     => $cols[2] ?? '',
                        'ek'          => isset($cols[3]) ? (float)$cols[3] : null,
                        'lp'          => isset($cols[4]) ? (float)$cols[4] : null,
                        'warengruppe' => $cols[5] ?? '',
                    ];
                    $count++;
                }
                fclose($fh);
            }
        }

        \jsonOut(['ok' => true, 'text' => $text, 'datanorm_treffer' => $datanormTreffer]);
    }
}
