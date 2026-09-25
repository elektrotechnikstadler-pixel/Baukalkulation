<?php
// ============================================================
// Lager-Modul – Adapter für ModuleLoader (v2.1, Phase 2A)
// ============================================================
// Endpoints: orte_list/save/delete, artikel_list/save/delete,
// buchung (delta-basiert), match_offenes_material (Fuzzy auf
// Bezeichnung). Alle Schreib-Aktionen schreiben Audit-Log.
// ============================================================

namespace App\Modules\Lager;

use App\Core\AbstractModule;
use App\Auth;
use App\Services\AuditService;

require_once __DIR__ . '/../LagerDatabase.php';

class Module extends AbstractModule
{
    public static function migrate(\PDO $db): void
    {
        LagerDatabase::init($db);
    }

    public function dispatch(string $action): void
    {
        $settings = Auth::loadSettings($this->db);
        if (isset($settings['modul_lager']) && $settings['modul_lager'] === false) {
            \jsonOut(['error' => 'Modul Lager ist deaktiviert.'], 403);
        }

        switch ($action) {
            case 'ping':
                \jsonOut(['ok' => true, 'module' => 'lager', 'version' => $this->manifest['version'] ?? '0.1.0']);
                break;

            // ── Lagerorte ──────────────────────────────────
            case 'orte_list':   $this->requirePerm('canReadLager');       $this->orteList();   break;
            case 'ort_save':    $this->requirePerm('canManageLagerorte'); $this->ortSave();    break;
            case 'ort_delete':  $this->requirePerm('canManageLagerorte'); $this->ortDelete();  break;

            // ── Artikel ────────────────────────────────────
            case 'artikel_list':   $this->requirePerm('canReadLager');  $this->artikelList();   break;
            case 'artikel_save':   $this->requirePerm('canWriteLager'); $this->artikelSave();   break;
            case 'artikel_delete': $this->requirePerm('canWriteLager'); $this->artikelDelete(); break;

            // ── Bestandsbuchung ────────────────────────────
            case 'buchung': $this->requirePerm('canWriteLager'); $this->buchung(); break;

            // ── Match Offenes Material ─────────────────────
            case 'match_offenes_material': $this->requirePerm('canReadLager'); $this->matchOffenesMaterial(); break;

            // ── Foto-OCR für Artikelerkennung ──────────────
            case 'foto_ocr':          $this->requirePerm('canReadLager'); $this->fotoOcr();         break;

            // ── KI-Bilderkennung (Google Gemini) ──────────
            case 'foto_ai':           $this->requirePerm('canReadLager'); $this->fotoAi();           break;
            case 'foto_ai_available': $this->requirePerm('canReadLager'); $this->fotoAiAvailable(); break;

            default:
                \jsonOut(['error' => 'Unbekannte Lager-Aktion: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 400);
        }
    }

    // ── Helpers ──────────────────────────────────────────
    private function requirePerm(string $key): void
    {
        if (!Auth::canDo($this->db, $key)) {
            \jsonOut(['error' => "Keine Berechtigung: $key"], 403);
        }
    }

    private function audit(string $sub, string $details): void
    {
        try { AuditService::log('lager.' . $sub, $details); } catch (\Throwable $e) { /* ignore */ }
    }

    // ── Lagerorte ────────────────────────────────────────
    private function orteList(): void
    {
        $rows = $this->db->query("SELECT id, name, beschreibung, aktiv FROM lager_orte ORDER BY LOWER(name)")->fetchAll(\PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($this->db->query("SELECT lagerort_id, COUNT(*) c FROM lager_artikel GROUP BY lagerort_id")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $counts[(int)$c['lagerort_id']] = (int)$c['c'];
        }
        foreach ($rows as &$r) {
            $r['id']             = (int)$r['id'];
            $r['aktiv']          = (int)$r['aktiv'];
            $r['anzahl_artikel'] = $counts[(int)$r['id']] ?? 0;
        }
        \jsonOut(['ok' => true, 'orte' => $rows]);
    }

    private function ortSave(): void
    {
        $b    = $this->body;
        $id   = isset($b['id']) ? (int)$b['id'] : 0;
        $name = trim((string)($b['name'] ?? ''));
        $besc = trim((string)($b['beschreibung'] ?? ''));
        $aktiv= !empty($b['aktiv']) ? 1 : (array_key_exists('aktiv', $b) ? 0 : 1);

        if ($name === '') \jsonOut(['error' => 'Name ist erforderlich.'], 400);
        if (mb_strlen($name) > 100) \jsonOut(['error' => 'Name zu lang (max. 100).'], 400);

        $stmt = $this->db->prepare("SELECT id FROM lager_orte WHERE LOWER(name) = LOWER(?) AND id != ?");
        $stmt->execute([$name, $id]);
        if ($stmt->fetch()) \jsonOut(['error' => 'Lagerort mit diesem Namen existiert bereits.'], 400);

        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE lager_orte SET name=?, beschreibung=?, aktiv=? WHERE id=?");
            $stmt->execute([$name, $besc, $aktiv, $id]);
            $this->audit('ort_update', "id=$id name=$name");
        } else {
            $stmt = $this->db->prepare("INSERT INTO lager_orte(name, beschreibung, aktiv) VALUES (?,?,?)");
            $stmt->execute([$name, $besc, $aktiv]);
            $id = (int)$this->db->lastInsertId();
            $this->audit('ort_create', "id=$id name=$name");
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function ortDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Ungültige ID.'], 400);

        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM lager_artikel WHERE lagerort_id = ?");
        $stmt->execute([$id]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt > 0) \jsonOut(['error' => "Lagerort hat noch $cnt Artikel zugeordnet. Erst Artikel umlagern oder löschen."], 400);

        $stmt = $this->db->prepare("DELETE FROM lager_orte WHERE id = ?");
        $stmt->execute([$id]);
        $this->audit('ort_delete', "id=$id");
        \jsonOut(['ok' => true]);
    }

    // ── Artikel ──────────────────────────────────────────
    private function artikelList(): void
    {
        $q          = trim((string)($_GET['q'] ?? $this->body['q'] ?? ''));
        $lagerortId = isset($_GET['lagerortId']) ? (int)$_GET['lagerortId'] : (int)($this->body['lagerortId'] ?? 0);
        $nurMitBest = !empty($_GET['nurMitBestand']) || !empty($this->body['nurMitBestand']);
        $unterMin   = !empty($_GET['unterMindest'])   || !empty($this->body['unterMindest']);

        $sql = "SELECT a.id, a.artikelnr, a.bezeichnung, a.menge, a.einheit,
                       a.ek_preis, a.vk_preis, a.mindestbestand, a.kategorie, a.notiz,
                       a.lagerort_id, o.name AS lagerort_name,
                       a.erstellt_von, a.erstellt_am, a.geaendert_am
                FROM lager_artikel a
                LEFT JOIN lager_orte o ON o.id = a.lagerort_id
                WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $tok) {
                $sql .= " AND (a.bezeichnung LIKE ? OR a.artikelnr LIKE ? OR a.kategorie LIKE ?)";
                $like = '%' . $tok . '%';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }
        }
        if ($lagerortId > 0) { $sql .= " AND a.lagerort_id = ?"; $params[] = $lagerortId; }
        if ($nurMitBest)     { $sql .= " AND a.menge > 0"; }
        if ($unterMin)       { $sql .= " AND a.mindestbestand > 0 AND a.menge < a.mindestbestand"; }
        $sql .= " ORDER BY LOWER(a.bezeichnung) LIMIT 1000";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']             = (int)$r['id'];
            $r['lagerort_id']    = $r['lagerort_id'] !== null ? (int)$r['lagerort_id'] : null;
            $r['menge']          = (float)$r['menge'];
            // v2.9.18: Geldbetraege auf 2 NK normieren
            $r['ek_preis']       = $r['ek_preis']       !== null ? \money_round($r['ek_preis'])       : null;
            $r['vk_preis']       = $r['vk_preis']       !== null ? \money_round($r['vk_preis'])       : null;
            $r['mindestbestand'] = $r['mindestbestand'] !== null ? (float)$r['mindestbestand'] : 0.0;
        }
        \jsonOut(['ok' => true, 'artikel' => $rows]);
    }

    private function artikelSave(): void
    {
        $b = $this->body;
        $id   = isset($b['id']) ? (int)$b['id'] : 0;
        $bez  = trim((string)($b['bezeichnung'] ?? ''));
        $nr   = trim((string)($b['artikelnr'] ?? ''));
        $einh = trim((string)($b['einheit'] ?? ''));
        $kat  = trim((string)($b['kategorie'] ?? ''));
        $not  = trim((string)($b['notiz'] ?? ''));
        $menge= isset($b['menge'])          ? (float)$b['menge']          : 0.0;
        $ek   = isset($b['ek_preis'])       && $b['ek_preis']       !== '' ? (float)$b['ek_preis']       : null;
        $vk   = isset($b['vk_preis'])       && $b['vk_preis']       !== '' ? (float)$b['vk_preis']       : null;
        $minB = isset($b['mindestbestand']) ? (float)$b['mindestbestand'] : 0.0;
        $ortId= isset($b['lagerort_id']) && $b['lagerort_id'] !== '' && $b['lagerort_id'] !== null ? (int)$b['lagerort_id'] : null;

        if ($bez === '') \jsonOut(['error' => 'Bezeichnung ist erforderlich.'], 400);
        if (mb_strlen($bez) > 200) \jsonOut(['error' => 'Bezeichnung zu lang (max. 200).'], 400);
        if ($menge < 0) \jsonOut(['error' => 'Menge darf nicht negativ sein.'], 400);
        if ($minB  < 0) \jsonOut(['error' => 'Mindestbestand darf nicht negativ sein.'], 400);
        if ($ortId !== null) {
            $stmt = $this->db->prepare("SELECT 1 FROM lager_orte WHERE id = ?");
            $stmt->execute([$ortId]);
            if (!$stmt->fetch()) \jsonOut(['error' => 'Lagerort existiert nicht.'], 400);
        }

        $now = date('c');
        $user= $_SESSION['username'] ?? 'system';

        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE lager_artikel SET artikelnr=?, bezeichnung=?, menge=?, einheit=?, ek_preis=?, vk_preis=?, lagerort_id=?, mindestbestand=?, kategorie=?, notiz=?, geaendert_am=? WHERE id=?");
            $stmt->execute([$nr, $bez, $menge, $einh, $ek, $vk, $ortId, $minB, $kat, $not, $now, $id]);
            $this->audit('artikel_update', "id=$id bez=" . substr($bez, 0, 60));
        } else {
            $stmt = $this->db->prepare("INSERT INTO lager_artikel(artikelnr, bezeichnung, menge, einheit, ek_preis, vk_preis, lagerort_id, mindestbestand, kategorie, notiz, erstellt_von, geaendert_am) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$nr, $bez, $menge, $einh, $ek, $vk, $ortId, $minB, $kat, $not, $user, $now]);
            $id = (int)$this->db->lastInsertId();
            $this->audit('artikel_create', "id=$id bez=" . substr($bez, 0, 60) . " menge=$menge");
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function artikelDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Ungültige ID.'], 400);
        $stmt = $this->db->prepare("DELETE FROM lager_artikel WHERE id = ?");
        $stmt->execute([$id]);
        $this->audit('artikel_delete', "id=$id");
        \jsonOut(['ok' => true]);
    }

    // ── Bestandsbuchung (delta-basiert) ─────────────────
    private function buchung(): void
    {
        $b   = $this->body;
        $id  = (int)($b['artikelId'] ?? 0);
        $delta= isset($b['delta']) ? (float)$b['delta'] : 0.0;
        $grund= trim((string)($b['grund'] ?? ''));
        $bauId= isset($b['baustelleId']) ? (int)$b['baustelleId'] : 0;

        if ($id <= 0)      \jsonOut(['error' => 'Ungültige Artikel-ID.'], 400);
        if ($delta == 0.0) \jsonOut(['error' => 'Delta darf nicht 0 sein.'], 400);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT id, bezeichnung, menge, einheit FROM lager_artikel WHERE id = ?");
            $stmt->execute([$id]);
            $art = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$art) {
                $this->db->rollBack();
                \jsonOut(['error' => 'Artikel nicht gefunden.'], 404);
            }
            $neu = (float)$art['menge'] + $delta;
            if ($neu < 0) {
                $this->db->rollBack();
                \jsonOut(['error' => 'Endbestand darf nicht negativ werden. Aktuell: ' . (float)$art['menge'] . ' ' . $art['einheit']], 400);
            }
            $stmt = $this->db->prepare("UPDATE lager_artikel SET menge = ?, geaendert_am = ? WHERE id = ?");
            $stmt->execute([$neu, date('c'), $id]);
            $this->db->commit();
            $detail = "id=$id delta=$delta neu=$neu" . ($bauId ? " baustelle=$bauId" : '') . ($grund !== '' ? " grund=" . substr($grund, 0, 80) : '');
            $this->audit('buchung', $detail);
            \jsonOut(['ok' => true, 'menge' => $neu, 'artikel' => ['id' => (int)$art['id'], 'bezeichnung' => $art['bezeichnung'], 'einheit' => $art['einheit']]]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            \jsonOut(['error' => 'Buchung fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    // ── Match Offenes Material → Lager ──────────────────
    private function matchOffenesMaterial(): void
    {
        $b = $this->body;
        $eintraege = isset($b['eintraege']) && is_array($b['eintraege']) ? $b['eintraege'] : [];
        if (count($eintraege) === 0) {
            \jsonOut(['ok' => true, 'matches' => []]);
        }

        $rows = $this->db->query("SELECT a.id, a.artikelnr, a.bezeichnung, a.menge, a.einheit, a.lagerort_id, o.name AS lagerort_name FROM lager_artikel a LEFT JOIN lager_orte o ON o.id = a.lagerort_id WHERE a.menge > 0 LIMIT 5000")->fetchAll(\PDO::FETCH_ASSOC);

        $matches = [];
        foreach ($eintraege as $e) {
            $eid = $e['id']  ?? null;
            $bez = trim((string)($e['bezeichnung'] ?? ''));
            if ($bez === '') continue;
            $best = null;
            $bestScore = 0.0;
            foreach ($rows as $r) {
                $score = self::similarity($bez, (string)$r['bezeichnung']);
                if ($score > $bestScore) { $bestScore = $score; $best = $r; }
            }
            if ($best && $bestScore >= 0.55) {
                $matches[] = [
                    'eintragId'    => $eid,
                    'score'        => round($bestScore, 3),
                    'artikelId'    => (int)$best['id'],
                    'artikelnr'    => $best['artikelnr'],
                    'bezeichnung'  => $best['bezeichnung'],
                    'menge'        => (float)$best['menge'],
                    'einheit'      => $best['einheit'],
                    'lagerort_id'  => $best['lagerort_id'] !== null ? (int)$best['lagerort_id'] : null,
                    'lagerort_name'=> $best['lagerort_name'],
                ];
            }
        }
        \jsonOut(['ok' => true, 'matches' => $matches]);
    }

    /** Heuristische Ähnlichkeit: Substring + Token-Overlap + similar_text-Fallback. */
    private static function similarity(string $a, string $b): float
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        if (mb_strpos($b, $a) !== false || mb_strpos($a, $b) !== false) {
            $shorter = min(mb_strlen($a), mb_strlen($b));
            $longer  = max(mb_strlen($a), mb_strlen($b));
            return min(0.95, 0.6 + 0.35 * ($shorter / max(1, $longer)));
        }
        $ta = array_filter(preg_split('/[\s,;\.\-\_\/]+/u', $a) ?: []);
        $tb = array_filter(preg_split('/[\s,;\.\-\_\/]+/u', $b) ?: []);
        if (count($ta) > 0 && count($tb) > 0) {
            $inter = count(array_intersect($ta, $tb));
            $union = count(array_unique(array_merge($ta, $tb)));
            $jac   = $union > 0 ? $inter / $union : 0.0;
            if ($jac >= 0.5) return min(0.9, 0.4 + 0.5 * $jac);
        }
        similar_text($a, $b, $pct);
        return $pct / 100.0;
    }

    // ── Foto-OCR ─────────────────────────────────────────
    /**
     * Empfängt ein Bild (multipart/form-data, Feld "foto"),
     * führt Tesseract-OCR aus und gibt Treffer aus Lager + Datanorm zurück.
     * Die Temp-Datei wird unmittelbar nach der OCR-Verarbeitung gelöscht.
     */
    private function fotoOcr(): void
    {
        // OCR-Feature prüfen
        if (!function_exists('feature_enabled') || !\feature_enabled('ocr')) {
            \jsonOut(['ok' => true, 'text' => '', 'lager_treffer' => [], 'datanorm_treffer' => [], 'hint' => 'OCR ist auf diesem Server nicht verfügbar (Tesseract nicht installiert).']);
        }

        // Upload-Validierung
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

        // MIME-Type per finfo prüfen (keine SVG-Gefahr, nur Rasterbilder erlaubt)
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/tiff'];
        $mime = '';
        if (class_exists('finfo')) {
            $fi   = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($file['tmp_name']);
        }
        if (!in_array($mime, $allowed, true)) {
            \jsonOut(['error' => 'Nur Rasterbilder erlaubt (JPG, PNG, WebP, GIF, BMP, TIFF).'], 400);
        }

        // Temp-Datei in sys_get_temp_dir() – NICHT in uploads/
        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif' => 'gif', 'image/bmp' => 'bmp', 'image/tiff' => 'tiff',
        ];
        $ext      = $extMap[$mime] ?? 'jpg';
        $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lager_ocr_' . bin2hex(random_bytes(8)) . '.' . $ext;

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
            \jsonOut(['ok' => true, 'text' => '', 'lager_treffer' => [], 'datanorm_treffer' => [], 'hint' => 'Kein Text erkannt. Bitte schärferes Bild, gute Beleuchtung und scharfen Fokus verwenden.']);
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
            \jsonOut(['ok' => true, 'text' => $text, 'lager_treffer' => [], 'datanorm_treffer' => [], 'hint' => 'Keine auswertbaren Tokens im erkannten Text.']);
        }

        // ── 1) Lager-Treffer ────────────────────────────
        $sqlParts = [];
        $sqlParams = [];
        foreach ($tokens as $tok) {
            $like = '%' . $tok . '%';
            $sqlParts[] = "(a.bezeichnung LIKE ? OR a.artikelnr LIKE ?)";
            $sqlParams[] = $like;
            $sqlParams[] = $like;
        }
        $sql = "SELECT a.id, a.artikelnr, a.bezeichnung, a.menge, a.einheit, a.ek_preis, a.lagerort_id, o.name AS lagerort_name
                FROM lager_artikel a
                LEFT JOIN lager_orte o ON o.id = a.lagerort_id
                WHERE " . implode(' OR ', $sqlParts) . "
                ORDER BY LOWER(a.bezeichnung)
                LIMIT 10";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($sqlParams);
        $lagerTreffer = array_map(function($r) {
            return [
                'id'           => (int)$r['id'],
                'artikelnr'    => $r['artikelnr'],
                'bezeichnung'  => $r['bezeichnung'],
                'menge'        => (float)$r['menge'],
                'einheit'      => $r['einheit'],
                'ek_preis'     => $r['ek_preis'] !== null ? (float)$r['ek_preis'] : null,
                'lagerort_name'=> $r['lagerort_name'],
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));

        // ── 2) Datanorm-Treffer ──────────────────────────
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
                    $matchAll = true;
                    foreach ($tokens as $tok) {
                        if (mb_strpos($haystack, mb_strtolower($tok, 'UTF-8')) === false) {
                            $matchAll = false;
                            break;
                        }
                    }
                    if (!$matchAll) {
                        // Single-token fallback: at least one token must match
                        $matchAny = false;
                        foreach ($tokens as $tok) {
                            if (mb_strpos($haystack, mb_strtolower($tok, 'UTF-8')) !== false) {
                                $matchAny = true;
                                break;
                            }
                        }
                        if (!$matchAny) continue;
                    }
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

        \jsonOut([
            'ok'               => true,
            'text'             => $text,
            'lager_treffer'    => $lagerTreffer,
            'datanorm_treffer' => $datanormTreffer,
        ]);
    }

    // ────────────────────────────────────────────────────────
    // KI-Bilderkennung via Google Gemini Flash
    // ────────────────────────────────────────────────────────

    /** Prüft ob ein Gemini API-Key konfiguriert ist (kein echter API-Aufruf). */
    private function fotoAiAvailable(): void
    {
        $settings = Auth::loadSettings($this->db);
        $key      = trim($settings['gemini_api_key'] ?? '');
        \jsonOut(['ok' => true, 'available' => $key !== '']);
    }

    /** Analysiert ein hochgeladenes Foto via Google Gemini und gibt strukturierte Artikelfelder zurück. */
    private function fotoAi(): void
    {
        $settings = Auth::loadSettings($this->db);
        $apiKey   = trim($settings['gemini_api_key'] ?? '');
        if ($apiKey === '') {
            \jsonOut(['ok' => false, 'hint' => 'Kein Gemini API-Key konfiguriert. Bitte unter Einstellungen → KI-Bilderkennung hinterlegen.']);
        }

        // ── Upload-Validierung ────────────────────────────
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
        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/tiff'];
        $mime     = '';
        if (class_exists('finfo')) {
            $fi   = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($file['tmp_name']);
        }
        if (!in_array($mime, $allowed, true)) {
            \jsonOut(['error' => 'Nur Rasterbilder erlaubt (JPG, PNG, WebP, GIF, BMP, TIFF).'], 400);
        }

        // ── Bild vorbereiten (EXIF-Rotation + Hochskalieren) ─
        $extMap      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                        'image/gif' => 'gif', 'image/bmp' => 'bmp', 'image/tiff' => 'tiff'];
        $ext         = $extMap[$mime] ?? 'jpg';
        $tmpPath     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lager_ai_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            \jsonOut(['error' => 'Bild konnte nicht verarbeitet werden.'], 500);
        }

        $processedPath = null;
        $geminiMime    = 'image/jpeg'; // Standardformat für Gemini
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
                    // Auf max. 1600px beschränken (Gemini-Limit beachten, Kosten reduzieren)
                    if ($w > 0 && $h > 0 && max($w, $h) > 1600) {
                        $scale  = 1600 / max($w, $h);
                        $newW   = (int)round($w * $scale);
                        $newH   = (int)round($h * $scale);
                        $shrunk = imagescale($img, $newW, $newH, IMG_BICUBIC);
                        if ($shrunk !== false) { imagedestroy($img); $img = $shrunk; }
                    } elseif ($w > 0 && $h > 0 && max($w, $h) < 800) {
                        $scale = (int)ceil(800 / max($w, $h));
                        $big   = imagescale($img, $w * $scale, $h * $scale, IMG_BICUBIC);
                        if ($big !== false) { imagedestroy($img); $img = $big; }
                    }
                    $processedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai_p_' . bin2hex(random_bytes(4)) . '.jpg';
                    imagejpeg($img, $processedPath, 90);
                    imagedestroy($img);
                    if (!file_exists($processedPath) || filesize($processedPath) < 100) {
                        if ($processedPath && file_exists($processedPath)) @unlink($processedPath);
                        $processedPath = null;
                    }
                    $geminiMime = 'image/jpeg';
                }
            } catch (\Throwable) {
                $processedPath = null;
            }
        }

        $imagePath = $processedPath ?? $tmpPath;
        $imageData = base64_encode((string)file_get_contents($imagePath));
        @unlink($tmpPath);
        if ($processedPath && $processedPath !== $tmpPath && file_exists($processedPath)) {
            @unlink($processedPath);
        }

        if ($imageData === '') {
            \jsonOut(['error' => 'Bild konnte nicht gelesen werden.'], 500);
        }

        // ── Gemini API-Aufruf ─────────────────────────────
        $prompt = <<<'PROMPT'
Du bist ein Assistent für Lagerverwaltung auf einer Baustelle. Analysiere das Bild und extrahiere folgende Informationen als JSON (nur JSON, kein Fließtext):
{
  "bezeichnung": "Produktname oder Bezeichnung des Artikels",
  "artikelnr": "Artikelnummer, EAN, oder Bestellnummer wenn sichtbar",
  "menge": null,
  "einheit": "Einheit (Stk, m, kg, l, Pck, Rol, ...)",
  "kategorie": "Kategorie (z.B. Befestigungsmittel, Klebstoffe, Werkzeug, Elektro, Sanitär, ...)",
  "ek_preis": null,
  "notiz": "Weitere relevante Informationen (Hersteller, Größe, Norm, ...)",
  "confidence": 80
}
Hinweise:
- confidence: 0-100, wie sicher du dir bei der Erkennung bist
- Zahlen ohne Anführungszeichen (z.B. "menge": 25, "ek_preis": 8.50)
- Unbekannte Felder auf null setzen
- Sprache: Deutsch
PROMPT;

        $payload = json_encode([
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $geminiMime, 'data' => $imageData]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'maxOutputTokens'  => 2048,
                'temperature'      => 0.1,
            ],
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($settings['gemini_model'] ?? 'gemini-2.5-flash-lite') . ':generateContent?key=' . urlencode($apiKey);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $rawResponse = (string)curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            \jsonOut(['ok' => false, 'hint' => 'Netzwerkfehler beim KI-Aufruf: ' . $curlError]);
        }
        if ($httpCode !== 200) {
            $errBody = json_decode($rawResponse, true);
            $errMsg  = $errBody['error']['message'] ?? ('HTTP ' . $httpCode);
            \jsonOut(['ok' => false, 'hint' => 'Gemini API-Fehler: ' . $errMsg]);
        }

        // ── Gemini-Antwort parsen ─────────────────────────
        $geminiResponse = json_decode($rawResponse, true);
        // Gemini 2.5+ Thinking-Modelle liefern mehrere Parts:
        // parts[0] = Thinking-Token (thought:true), parts[n] = eigentliche Antwort.
        // Ersten Non-Thought-Part verwenden.
        $jsonText = '';
        foreach ($geminiResponse['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && !($part['thought'] ?? false)) {
                $jsonText = $part['text'];
                break;
            }
        }

        // JSON aus Markdown-Codeblock befreien falls nötig
        $jsonText = preg_replace('/^```(?:json)?\s*/i', '', trim($jsonText));
        $jsonText = preg_replace('/\s*```$/', '', $jsonText);

        $parsed = json_decode(trim($jsonText), true);
        // Fallback: erstes {…} im Text suchen (falls Modell trotz responseMimeType Text umgibt)
        if (!is_array($parsed)) {
            $start = strpos($jsonText, '{');
            $end   = strrpos($jsonText, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($jsonText, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($parsed)) {
            \jsonOut(['ok' => false, 'hint' => 'KI-Antwort konnte nicht verarbeitet werden. Bitte erneut versuchen.']);
        }

        // ── Sanitizing ───────────────────────────────────
        $bezeichnung = mb_substr(trim((string)($parsed['bezeichnung'] ?? '')), 0, 200, 'UTF-8');
        $artikelnr   = mb_substr(trim((string)($parsed['artikelnr']   ?? '')), 0, 100, 'UTF-8');
        $einheit     = mb_substr(trim((string)($parsed['einheit']     ?? '')), 0, 20,  'UTF-8');
        $kategorie   = mb_substr(trim((string)($parsed['kategorie']   ?? '')), 0, 100, 'UTF-8');
        $notiz       = mb_substr(trim((string)($parsed['notiz']       ?? '')), 0, 500, 'UTF-8');
        $menge       = is_numeric($parsed['menge'] ?? null)    ? (float)$parsed['menge']    : null;
        $ekPreis     = is_numeric($parsed['ek_preis'] ?? null) ? (float)$parsed['ek_preis'] : null;
        $confidence  = max(0, min(100, (int)($parsed['confidence'] ?? 50)));

        if ($bezeichnung === '') {
            \jsonOut(['ok' => true, 'hint' => 'Kein Artikel erkannt. Bitte schärferes Bild mit gut sichtbarem Etikett verwenden.',
                      'bezeichnung' => '', 'confidence' => $confidence]);
        }

        // ── Ähnliche Lager-Artikel suchen ─────────────────
        $lagerTreffer = [];
        if ($bezeichnung !== '') {
            $like  = '%' . $bezeichnung . '%';
            $parts = array_unique(array_filter(
                explode(' ', preg_replace('/[^\w\s]/u', ' ', $bezeichnung) ?: ''),
                fn($t) => mb_strlen($t) >= 3
            ));
            $sqlParts = ["(a.bezeichnung LIKE ? OR a.artikelnr LIKE ?)"];
            $sqlParams = [$like, $like];
            if ($artikelnr !== '') {
                $sqlParts[]  = "(a.artikelnr LIKE ?)";
                $sqlParams[] = '%' . $artikelnr . '%';
            }
            foreach (array_slice($parts, 0, 5) as $tok) {
                $sqlParts[]  = "(a.bezeichnung LIKE ?)";
                $sqlParams[] = '%' . $tok . '%';
            }
            $stmt = $this->db->prepare(
                "SELECT a.id, a.artikelnr, a.bezeichnung, a.menge, a.einheit, a.ek_preis, a.lagerort_id, o.name AS lagerort_name
                 FROM lager_artikel a
                 LEFT JOIN lager_orte o ON o.id = a.lagerort_id
                 WHERE " . implode(' OR ', $sqlParts) .
                " ORDER BY LOWER(a.bezeichnung) LIMIT 8"
            );
            $stmt->execute($sqlParams);
            $lagerTreffer = array_map(fn($r) => [
                'id'            => (int)$r['id'],
                'artikelnr'     => $r['artikelnr'],
                'bezeichnung'   => $r['bezeichnung'],
                'menge'         => (float)$r['menge'],
                'einheit'       => $r['einheit'],
                'ek_preis'      => $r['ek_preis'] !== null ? (float)$r['ek_preis'] : null,
                'lagerort_name' => $r['lagerort_name'],
            ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }

        // ── Datanorm-Katalog-Treffer suchen ───────────────
        $katalogTreffer = [];
        if ($bezeichnung !== '') {
            $indexFile = defined('DATA_DIR') ? \DATA_DIR . 'datanorm_index.tsv' : '';
            if ($indexFile !== '' && file_exists($indexFile)) {
                // Suchbegriffe: Tokens aus Bezeichnung (≥2 Zeichen) + Artikelnummer
                $rawTokens = array_unique(array_filter(
                    explode(' ', mb_strtolower(preg_replace('/[^\w\s]/u', ' ', $bezeichnung) ?: '', 'UTF-8')),
                    fn($t) => mb_strlen($t, 'UTF-8') >= 2
                ));
                $searchTokens = array_slice($rawTokens, 0, 5);
                if ($artikelnr !== '') {
                    $searchTokens[] = mb_strtolower($artikelnr, 'UTF-8');
                }

                if (!empty($searchTokens)) {
                    $fh = fopen($indexFile, 'r');
                    while (($line = fgets($fh)) !== false && count($katalogTreffer) < 5) {
                        $lower = mb_strtolower($line, 'UTF-8');
                        $hit = false;
                        foreach ($searchTokens as $tok) {
                            if (mb_strpos($lower, $tok) !== false) { $hit = true; break; }
                        }
                        if (!$hit) continue;
                        $p = explode("\t", rtrim($line, "\r\n"));
                        $katalogTreffer[] = [
                            'artNr'       => $p[0] ?? '',
                            'bezeichnung' => $p[1] ?? '',
                            'einheit'     => $p[2] ?? 'Stk.',
                            'ek'          => round((float)($p[3] ?? 0), 4),
                            'lp'          => round((float)($p[4] ?? 0), 4),
                            'quelle'      => 'Datanorm',
                        ];
                    }
                    fclose($fh);
                }
            }
        }

        \jsonOut([
            'ok'              => true,
            'bezeichnung'     => $bezeichnung,
            'artikelnr'       => $artikelnr,
            'menge'           => $menge,
            'einheit'         => $einheit,
            'kategorie'       => $kategorie,
            'ek_preis'        => $ekPreis,
            'notiz'           => $notiz,
            'confidence'      => $confidence,
            'lager_treffer'   => $lagerTreffer,
            'katalog_treffer' => $katalogTreffer,
        ]);
    }
}
