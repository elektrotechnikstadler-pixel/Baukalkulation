<?php
namespace App\Handlers;

use App\Auth;

class FileActions
{
    public function __construct(private \PDO $db, private array $body) {}

    /** Die 7 Standard-Dokumentenordner (v2.0.1: + Sonstiges) */
    private const DOC_CATEGORIES = [
        'Eingangsbelege', 'Ausgangsbelege', 'Angebote', 'Dateien', 'Fotos', 'Protokolle', 'Sonstiges'
    ];

    private function getBaustelleFolder(int $baustelleId): string
    {
        $row = $this->db->prepare("SELECT name FROM baustellen WHERE id = ?");
        $row->execute([$baustelleId]);
        $r = $row->fetch();
        $bName = $r ? $r['name'] : 'Baustelle_' . $baustelleId;
        return preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\- ]/u', '_', $bName);
    }

    /** Ordnerstruktur für eine Baustelle sicherstellen */
    private function ensureFolderStructure(string $baseDir): void
    {
        foreach (self::DOC_CATEGORIES as $cat) {
            $catDir = $baseDir . $cat . '/';
            if (!is_dir($catDir)) mkdir($catDir, 0750, true);
        }
    }

    /**
     * Text aus einer Datei extrahieren (PDF → pdfparser, Bilder → Tesseract OCR)
     *
     * H2 (v1.8.0): DoS-Härtung
     *  - Dateigröße begrenzt (PDF 20 MB, Bild 8 MB) – verhindert dass ein
     *    riesiges Bild den OCR-Prozess minutenlang blockiert.
     *  - tesseract/pdftotext laufen mit 30 s Timeout (Linux: timeout-Kommando).
     */
    private function extractText(string $filePath, string $ext): string
    {
        $text = '';
        $size = @filesize($filePath);
        // Schwellen für Klassifikation – Volltext brauchen wir hier nicht.
        $maxPdf = 20 * 1024 * 1024;
        $maxImg =  8 * 1024 * 1024;
        try {
            if ($ext === 'pdf') {
                if ($size !== false && $size > $maxPdf) {
                    error_log('[DocClassify] PDF zu groß für OCR: ' . $size . ' bytes – übersprungen');
                    return '';
                }
                // Erst mit pdftotext (poppler), dann Fallback pdfparser
                $escaped = escapeshellarg($filePath);
                $text = feature_enabled('ocr')
                    ? (string)shell_exec("timeout 30 pdftotext -l 3 $escaped - 2>/dev/null")
                    : '';
                if (mb_strlen(trim($text)) < 20 && feature_enabled('pdfparser')) {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($filePath);
                    $text = $pdf->getText();
                    // Nur erste 5000 Zeichen für Klassifikation
                    $text = mb_substr($text, 0, 5000);
                }
            } elseif (in_array($ext, ['jpg','jpeg','png','tif','tiff','bmp','webp'])) {
                if ($size !== false && $size > $maxImg) {
                    error_log('[DocClassify] Bild zu groß für OCR: ' . $size . ' bytes – übersprungen');
                    return '';
                }
                // Tesseract OCR (Deutsch + Englisch) – nur wenn im Build aktiviert.
                if (feature_enabled('ocr')) {
                    $escaped = escapeshellarg($filePath);
                    $text = (string)shell_exec("timeout 30 tesseract $escaped stdout -l deu+eng 2>/dev/null");
                    $text = mb_substr($text, 0, 5000);
                }
            }
        } catch (\Throwable $e) {
            // Textextraktion fehlgeschlagen → Fallback auf Dateiname
            error_log('[DocClassify] Extraction failed: ' . $e->getMessage());
            $text = '';
        }
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Dokumentenklassifikation – primär nach Inhalt, Fallback Dateiname.
     * @param string $filePath  Pfad zur hochgeladenen Datei (temp oder final)
     * @param string $fileName  Originaler Dateiname
     */
    private function classifyDocument(string $filePath, string $fileName = ''): string
    {
        $ext = strtolower(pathinfo($fileName ?: $filePath, PATHINFO_EXTENSION));

        // Reine Fotos ohne Dokumentinhalt (Kamera-Schnappschüsse)
        $imageExts = ['jpg','jpeg','png','gif','webp','bmp','heic','heif','tif','tiff','svg'];
        $isImage = in_array($ext, $imageExts);

        // Text aus Dateiinhalt extrahieren
        $text = '';
        if (in_array($ext, ['pdf','jpg','jpeg','png','tif','tiff','bmp','webp'])) {
            $text = $this->extractText($filePath, $ext);
        }

        // Auch den Dateinamen in die Analyse einbeziehen (als Zusatzinfo)
        $text .= ' ' . mb_strtolower($fileName, 'UTF-8');

        // ── Inhaltbasierte Klassifikation ────────────────────────

        // Rechnung / Beleg erkennen
        $isRechnung  = (bool)preg_match('/rechnung|invoice|rechnungs-?nr|rechnungsdatum|rechnungsbetrag|netto.?betrag|zahlungsziel|bankverbindung|iban.*de\d{2}/iu', $text);
        $isLiefer    = (bool)preg_match('/lieferschein|wareneingang|lieferung|packing.?slip|delivery.?note|wareneingangsnr/iu', $text);
        $isGutschrift= (bool)preg_match('/gutschrift|credit.?note|stornorechnung|erstattung/iu', $text);
        $isMahnung   = (bool)preg_match('/mahnung|zahlungserinnerung|inkasso|mahngebühr|verzugszins/iu', $text);
        $isAngebot   = (bool)preg_match('/angebot|kostenvoranschlag|offerte|leistungsverzeichnis|nachtrag|angebots-?nr|gültig.?bis|quotation|quote|proposal/iu', $text);
        $isProtokoll = (bool)preg_match('/protokoll|bericht|tagesbericht|baubericht|abnahme|prüfbericht|prüfprotokoll|bautagebuch|aufmaß|aufmass|messbericht|prüfzeugnis|zeugnis|gutachten|befund|niederschrift|begehung|mängelprotokoll|abnahmeprotokoll/iu', $text);
        $isBestellung= (bool)preg_match('/bestellschein|bestellung|auftragsbestätigung|purchase.?order|bestell-?nr|bestelldatum|order.?confirm/iu', $text);

        // Eingangsbeleg-Indikatoren (empfangenes Dokument)
        $isEingang   = (bool)preg_match('/lieferschein|wareneingang|gutschrift|mahnung|eingangsrechnung|kunden-?nr|ihre.?rechnung|zahlbar.?bis|eingangsbeleg/iu', $text);
        // Ausgangsbeleg-Indikatoren (eigenes Dokument)
        $isAusgang   = (bool)preg_match('/bestellschein|bestellung|ausgangsrechnung|unsere.?rechnung|wir.?berechnen|wir.?erlauben|auftragsbestätigung|ausgangsbeleg/iu', $text);

        // ── Entscheidungslogik ────────────────────────────────────

        // Angebote
        if ($isAngebot && !$isRechnung) return 'Angebote';

        // Protokolle
        if ($isProtokoll && !$isRechnung && !$isAngebot) return 'Protokolle';

        // Rechnungen – Richtung bestimmen
        if ($isRechnung || $isLiefer || $isGutschrift || $isMahnung) {
            if ($isAusgang && !$isEingang) return 'Ausgangsbelege';
            return 'Eingangsbelege'; // Default: empfangen
        }

        // Bestellungen → Ausgangsbelege
        if ($isBestellung) return 'Ausgangsbelege';

        // Bild ohne erkannten Dokumentinhalt → Foto
        if ($isImage && mb_strlen(trim($text)) < 50) return 'Fotos';

        // Bild mit langem OCR-Text aber kein Treffer → wahrscheinlich ein Foto von einem Dokument
        if ($isImage && mb_strlen(trim($text)) >= 50) {
            // Nochmal prüfen: Enthält der Text typische Dokumentwörter?
            if (preg_match('/datum|unterschrift|seite|nr\.|betrag|summe|gesamt|mwst|netto|brutto/iu', $text)) {
                return 'Dateien'; // Gescanntes Dokument, nicht eindeutig zuzuordnen
            }
            return 'Fotos'; // Wahrscheinlich ein Baufoto mit zufälligem Text
        }

        // Fallback
        return 'Dateien';
    }

    /** Schnelle Klassifikation nur nach Dateiname (für Legacy-Migration, kein OCR) */
    private function classifyByName(string $fileName): string
    {
        $lower = mb_strtolower($fileName, 'UTF-8');
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','heic','heif','tif','tiff','svg'])) {
            if (!preg_match('/(rechnung|lieferschein|angebot|protokoll|bericht|abnahme)/i', $lower)) return 'Fotos';
        }
        if (preg_match('/(eingang|lieferschein|gutschrift|mahnung)/i', $lower)) return 'Eingangsbelege';
        if (preg_match('/(ausgang|bestellschein|bestellung|auftragsbestätigung)/i', $lower)) return 'Ausgangsbelege';
        if (preg_match('/rechnung/i', $lower)) return 'Eingangsbelege';
        if (preg_match('/(angebot|kostenvoranschlag|offerte|nachtrag)/i', $lower)) return 'Angebote';
        if (preg_match('/(protokoll|bericht|tagesbericht|baubericht|abnahme|bautagebuch|aufma)/i', $lower)) return 'Protokolle';
        return 'Dateien';
    }

    public function uploadFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($_POST['baustelleId'] ?? 0);
        if (!$baustelleId) jsonOut(['error' => 'Keine Baustellen-ID.'], 400);
        // H5 (v1.8.0): Sichtbarkeitsprüfung – Master darf nur Dateien zu
        // Baustellen hochladen, die in seiner Visibility-Liste sind.
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }
        if (empty($_FILES['file'])) jsonOut(['error' => 'Keine Datei hochgeladen.'], 400);

        // Ordner-Berechtigung prüfen
        $category = trim($_POST['category'] ?? '');
        $file = $_FILES['file'];
        $origName = basename($file['name']);

        // SEC: Ausführbare Dateierweiterungen ablehnen – verhindert PHP-Ausführung
        // im Fall einer Fehlkonfiguration des Webservers (defense-in-depth;
        // data/.htaccess sperrt PHP-Ausführung bereits auf Webserver-Ebene).
        $uploadExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $blockedExts = ['php','php3','php4','php5','php7','php8','phtml','phar',
                        'shtml','cgi','pl','py','rb','sh','bash','htaccess'];
        if (in_array($uploadExt, $blockedExts, true)) {
            jsonOut(['error' => 'Dieser Dateityp ist nicht erlaubt.'], 400);
        }

        // Upload-Fehler prüfen
        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonOut(['error' => 'Upload-Fehler (Code ' . $file['error'] . ').'], 400);
        }
        // Dateigröße begrenzen (20 MB)
        if ($file['size'] > 20 * 1024 * 1024) {
            jsonOut(['error' => 'Datei zu groß (max. 20 MB).'], 400);
        }

        // Auto-Klassifikation wenn keine Kategorie vorgegeben
        if (!$category || !in_array($category, self::DOC_CATEGORIES)) {
            $category = $this->classifyDocument($file['tmp_name'], $origName);
        }

        // Schreibberechtigung prüfen
        $writePerm = 'canWrite' . $category;
        if (!Auth::canDo($this->db, $writePerm)) {
            jsonOut(['error' => "Keine Schreibberechtigung für $category."], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $this->ensureFolderStructure($baseDir);

        $dir = $baseDir . $category . '/';
        $safeName = date('Y-m-d_His') . '_' . preg_replace('/[^a-zA-Z0-9äöüÄÖÜß._\-]/u', '_', $origName);
        $dest = $dir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) jsonOut(['error' => 'Upload fehlgeschlagen.'], 500);
        jsonOut(['ok' => true, 'fileName' => $safeName, 'originalName' => $origName, 'size' => $file['size'], 'category' => $category]);
    }

    /** Datei intern speichern (von PDF-Export etc.) – kein Upload, direkte Daten */
    public function saveGeneratedFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $category    = trim($this->body['category'] ?? 'Dateien');
        $fileName    = basename($this->body['fileName'] ?? '');
        $base64Data  = $this->body['fileData'] ?? '';

        if (!$baustelleId || !$fileName || !$base64Data) {
            jsonOut(['error' => 'Fehlende Parameter.'], 400);
        }
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }
        // Größe prüfen (base64 ist ~33% größer als Binärdaten)
        if (strlen($base64Data) > 28 * 1024 * 1024) {
            jsonOut(['error' => 'Datei zu groß (max. 20 MB).'], 400);
        }
        if (!in_array($category, self::DOC_CATEGORIES)) $category = 'Dateien';

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $this->ensureFolderStructure($baseDir);

        $dir = $baseDir . $category . '/';
        $safeName = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß._\-]/u', '_', $fileName);
        $dest = $dir . $safeName;

        $decoded = base64_decode($base64Data);
        if ($decoded === false) jsonOut(['error' => 'Ungültige Dateidaten.'], 400);

        file_put_contents($dest, $decoded);
        jsonOut(['ok' => true, 'fileName' => $safeName, 'category' => $category]);
    }

    public function listFiles(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($_GET['baustelleId'] ?? 0);
        if (!$baustelleId) jsonOut(['error' => 'Keine Baustellen-ID.'], 400);
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $this->ensureFolderStructure($baseDir);

        $result = [];
        foreach (self::DOC_CATEGORIES as $cat) {
            // Leseberechtigung prüfen
            $readPerm = 'canRead' . $cat;
            if (!Auth::canDo($this->db, $readPerm)) {
                $result[$cat] = ['allowed' => false, 'files' => []];
                continue;
            }

            $catDir = $baseDir . $cat . '/';
            $aliases = $this->loadAliases($catDir);
            $files = [];
            if (is_dir($catDir)) {
                foreach (scandir($catDir) as $f) {
                    if ($f === '.' || $f === '..' || $f === '.aliases.json') continue;
                    $path = $catDir . $f;
                    if (!is_file($path)) continue;
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);
                    $files[] = [
                        'name' => $f,
                        'alias' => $aliases[$f] ?? '',
                        'size' => filesize($path),
                        'isImage' => $isImage,
                        'modified' => date('Y-m-d H:i', filemtime($path)),
                        'category' => $cat,
                    ];
                }
            }
            // Neueste zuerst
            usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));
            $result[$cat] = ['allowed' => true, 'files' => $files];
        }

        // Legacy: auch Dateien im Hauptordner (ohne Unterordner) migrieren
        $legacyFiles = [];
        if (is_dir($baseDir)) {
            foreach (scandir($baseDir) as $f) {
                if ($f === '.' || $f === '..' || $f === '.aliases.json') continue;
                $path = $baseDir . $f;
                if (!is_file($path)) continue;
                // In richtigen Ordner verschieben (nur nach Dateiname, kein OCR bei Migration)
                $cat = $this->classifyByName($f);
                $catDir = $baseDir . $cat . '/';
                if (!is_dir($catDir)) mkdir($catDir, 0750, true);
                rename($path, $catDir . $f);
                // Alias migrieren
                $oldAliases = $this->loadAliases($baseDir);
                if (isset($oldAliases[$f])) {
                    $catAliases = $this->loadAliases($catDir);
                    $catAliases[$f] = $oldAliases[$f];
                    $this->saveAliases($catDir, $catAliases);
                    unset($oldAliases[$f]);
                    $this->saveAliases($baseDir, $oldAliases);
                }
                $legacyFiles[] = $f;
            }
        }

        jsonOut([
            'ok' => true,
            'categories' => $result,
            'folder' => $folderName,
            'migrated' => count($legacyFiles),
        ]);
    }

    /** Datei in anderen Ordner verschieben */
    public function moveFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $fileName    = basename($this->body['fileName'] ?? '');
        $fromCat     = trim($this->body['fromCategory'] ?? '');
        $toCat       = trim($this->body['toCategory'] ?? '');

        if (!$baustelleId || !$fileName || !$fromCat || !$toCat) {
            jsonOut(['error' => 'Fehlende Parameter.'], 400);
        }
        if (!in_array($fromCat, self::DOC_CATEGORIES) || !in_array($toCat, self::DOC_CATEGORIES)) {
            jsonOut(['error' => 'Ungültige Kategorie.'], 400);
        }
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        // Schreibberechtigung für Zielordner
        if (!Auth::canDo($this->db, 'canWrite' . $toCat)) {
            jsonOut(['error' => "Keine Schreibberechtigung für $toCat."], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $srcPath = $baseDir . $fromCat . '/' . $fileName;
        $dstPath = $baseDir . $toCat . '/' . $fileName;

        if (!file_exists($srcPath)) jsonOut(['error' => 'Datei nicht gefunden.'], 404);

        // SEC: realpath-Guard gegen Path-Traversal (defense-in-depth)
        $realBase = realpath(UPLOADS_DIR);
        $realSrc  = realpath($srcPath);
        if (!$realBase || !$realSrc || !str_starts_with($realSrc . '/', $realBase . '/')) {
            jsonOut(['error' => 'Datei nicht gefunden.'], 404);
        }

        if (file_exists($dstPath)) {
            $dstPath = $baseDir . $toCat . '/' . date('His') . '_' . $fileName;
        }

        rename($srcPath, $dstPath);

        // Alias migrieren
        $srcAliases = $this->loadAliases($baseDir . $fromCat . '/');
        if (isset($srcAliases[$fileName])) {
            $dstAliases = $this->loadAliases($baseDir . $toCat . '/');
            $dstAliases[basename($dstPath)] = $srcAliases[$fileName];
            $this->saveAliases($baseDir . $toCat . '/', $dstAliases);
            unset($srcAliases[$fileName]);
            $this->saveAliases($baseDir . $fromCat . '/', $srcAliases);
        }

        jsonOut(['ok' => true, 'newCategory' => $toCat]);
    }

    public function downloadFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($_GET['baustelleId'] ?? 0);
        $fileName    = basename($_GET['fileName'] ?? '');
        $category    = trim($_GET['category'] ?? '');
        if (!$baustelleId || !$fileName) jsonOut(['error' => 'Fehlende Parameter.'], 400);
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';

        // Datei im Unterordner suchen
        if ($category && in_array($category, self::DOC_CATEGORIES)) {
            $path = $baseDir . $category . '/' . $fileName;
        } else {
            // Fallback: in allen Ordnern suchen
            $path = $baseDir . $fileName;
            if (!file_exists($path)) {
                foreach (self::DOC_CATEGORIES as $cat) {
                    $try = $baseDir . $cat . '/' . $fileName;
                    if (file_exists($try)) { $path = $try; $category = $cat; break; }
                }
            }
        }
        if (!file_exists($path)) jsonOut(['error' => 'Datei nicht gefunden.'], 404);

        // SEC: realpath-Guard gegen Path-Traversal (defense-in-depth)
        $realBase = realpath(UPLOADS_DIR);
        $realPath = realpath($path);
        if (!$realBase || !$realPath || !str_starts_with($realPath . '/', $realBase . '/')) {
            jsonOut(['error' => 'Datei nicht gefunden.'], 404);
        }

        // Leseberechtigung prüfen
        if ($category && !Auth::canDo($this->db, 'canRead' . $category)) {
            jsonOut(['error' => 'Keine Leseberechtigung.'], 403);
        }

        // SEC (v2.9.17): finfo statt mime_content_type (zuverlaessiger, nicht deprecated).
        try { $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'; }
        catch (\Throwable $e) { $mime = 'application/octet-stream'; }
        $safeFileName = str_replace(['"', "\r", "\n"], '', $fileName);
        // SEC: Nur ungefährliche MIME-Typen inline ausliefern. SVG, HTML, JS etc.
        // können eingebettete Skripte enthalten → immer als Download (attachment).
        $inlineSafeMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $disposition = in_array($mime, $inlineSafeMimes, true) ? 'inline' : 'attachment';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFileName . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function deleteFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $fileName    = basename($this->body['fileName'] ?? '');
        $category    = trim($this->body['category'] ?? '');
        if (!$baustelleId || !$fileName) jsonOut(['error' => 'Fehlende Parameter.'], 400);
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        if ($category && !Auth::canDo($this->db, 'canWrite' . $category)) {
            jsonOut(['error' => 'Keine Schreibberechtigung.'], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $dir = ($category && in_array($category, self::DOC_CATEGORIES))
            ? $baseDir . $category . '/'
            : $baseDir;
        $path = $dir . $fileName;
        if (file_exists($path)) @unlink($path);
        // Also remove alias if present
        $aliases = $this->loadAliases($dir);
        if (isset($aliases[$fileName])) {
            unset($aliases[$fileName]);
            $this->saveAliases($dir, $aliases);
        }
        jsonOut(['ok' => true]);
    }

    public function renameFile(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $fileName    = basename($this->body['fileName'] ?? '');
        $alias       = trim($this->body['alias'] ?? '');
        $category    = trim($this->body['category'] ?? '');
        if (!$baustelleId || !$fileName) jsonOut(['error' => 'Fehlende Parameter.'], 400);
        // H5 (v1.8.0): Sichtbarkeitsprüfung
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $dir = ($category && in_array($category, self::DOC_CATEGORIES))
            ? $baseDir . $category . '/'
            : $baseDir;
        $path = $dir . $fileName;
        if (!file_exists($path)) jsonOut(['error' => 'Datei nicht gefunden.'], 404);

        $aliases = $this->loadAliases($dir);
        if ($alias === '') {
            unset($aliases[$fileName]);
        } else {
            $aliases[$fileName] = $alias;
        }
        $this->saveAliases($dir, $aliases);
        jsonOut(['ok' => true]);
    }

    private function loadAliases(string $dir): array
    {
        $path = $dir . '.aliases.json';
        if (!file_exists($path)) return [];
        $data = @json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveAliases(string $dir, array $aliases): void
    {
        $path = $dir . '.aliases.json';
        if (empty($aliases)) {
            if (file_exists($path)) @unlink($path);
        } else {
            file_put_contents($path, json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function saveTagesbuchExport(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($_GET['baustelleId'] ?? 0);
        $htmlContent = $this->body['html'] ?? '';
        if (!$baustelleId || !$htmlContent) jsonOut(['error' => 'Fehlende Parameter.'], 400);

        $folderName = $this->getBaustelleFolder($baustelleId);
        $dir = TAGEBUCH_DIR . $folderName . '/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $fileName = 'Bautagebuch_KW' . date('W') . '_' . date('Y-m-d') . '.html';
        file_put_contents($dir . $fileName, $htmlContent);
        jsonOut(['ok' => true, 'file' => $fileName]);
    }

    public function saveWeeklyExport(): void
    {
        Auth::requireAuth();
        $fileContent = $this->body['content'] ?? '';
        $fileName    = basename($this->body['fileName'] ?? ('Export_' . date('Y-m-d') . '.xlsx'));
        $fileName    = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-. ]/u', '_', $fileName);
        if (!$fileContent) jsonOut(['error' => 'Keine Daten.'], 400);
        $decoded = base64_decode($fileContent);
        if ($decoded === false) jsonOut(['error' => 'Ungültige Daten.'], 400);
        if (!is_dir(EXPORT_DIR)) mkdir(EXPORT_DIR, 0750, true);
        file_put_contents(EXPORT_DIR . $fileName, $decoded);
        jsonOut(['ok' => true, 'file' => $fileName]);
    }

    public function saveTagesbericht(): void
    {
        Auth::requireAuth();
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        $datumVon    = trim($this->body['datumVon'] ?? $this->body['datum'] ?? date('Y-m-d'));
        $datumBis    = trim($this->body['datumBis'] ?? $datumVon);
        $htmlReport  = $this->body['htmlReport'] ?? '';
        $signature   = $this->body['signature'] ?? '';
        $pdfBase64   = $this->body['pdfData'] ?? '';
        if (!$baustelleId || !$htmlReport) jsonOut(['error' => 'Fehlende Parameter.'], 400);

        $folderName = $this->getBaustelleFolder($baustelleId);
        $baseDir = UPLOADS_DIR . $folderName . '/';
        $this->ensureFolderStructure($baseDir);
        $dir = $baseDir . 'Protokolle/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $safeVon = preg_replace('/[^0-9\-]/', '', $datumVon);
        $safeBis = preg_replace('/[^0-9\-]/', '', $datumBis);
        $isRange = ($safeVon !== $safeBis);
        $prefix  = $isRange ? 'Baubericht' : 'Tagesbericht';
        $datumPart = $isRange ? $safeVon . '_bis_' . $safeBis : $safeVon;
        $fileName  = $prefix . '_' . $datumPart . '_' . date('His') . '.html';

        $row = $this->db->prepare("SELECT name FROM baustellen WHERE id = ?");
        $row->execute([$baustelleId]);
        $bName = $row->fetch()['name'] ?? 'Unbekannt';

        $title = $prefix . ' ' . htmlspecialchars($bName) . ' – ' . ($isRange ? $safeVon . ' bis ' . $safeBis : $safeVon);
        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>' . $title . '</title>'
              . '<style>'
              . 'body{font-family:Arial,Helvetica,sans-serif;max-width:800px;margin:0 auto;padding:20px;color:#1a1a1a;font-size:14px;}'
              . 'h1{font-size:20px;border-bottom:2px solid #C41E3A;padding-bottom:8px;color:#C41E3A;}'
              . 'h2{font-size:16px;margin-top:24px;color:#333;border-bottom:1px solid #ddd;padding-bottom:4px;}'
              . 'table{width:100%;border-collapse:collapse;margin:8px 0 16px;font-size:13px;}'
              . 'th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;}'
              . 'th{background:#FDE8EC;font-weight:600;}'
              . 'td.num{text-align:right;}'
              . 'tfoot td{font-weight:700;background:#FDE8EC;}'
              . '.sig-block{margin-top:32px;border-top:1px solid #999;padding-top:16px;}'
              . '.sig-block img{max-width:340px;display:block;}'
              . '.sig-line{margin-top:8px;color:#666;font-size:12px;}'
              . '@media print{body{padding:10px;}}'
              . '</style></head><body>';
        $html .= $htmlReport;
        if ($signature) {
            $datumLabel = $isRange ? $safeVon . ' – ' . $safeBis : $safeVon;
            $html .= '<div class="sig-block"><p style="font-weight:600;">Unterschrift Auftraggeber:</p>'
                   . '<img src="' . $signature . '" alt="Unterschrift">'
                   . '<p class="sig-line">Datum: ' . $datumLabel . ' | Digital unterschrieben</p></div>';
        }
        $html .= '<p style="margin-top:24px;font-size:11px;color:#aaa;text-align:center;">Erstellt am '
               . date('d.m.Y H:i') . ' Uhr – Baukalkulation</p></body></html>';
        file_put_contents($dir . $fileName, $html);

        $pdfFileName = '';
        if ($pdfBase64) {
            $pdfFileName = str_replace('.html', '.pdf', $fileName);
            $pdfData = base64_decode($pdfBase64, true);
            if ($pdfData !== false) file_put_contents($dir . $pdfFileName, $pdfData);
        }
        jsonOut(['ok' => true, 'fileName' => $fileName, 'pdfFileName' => $pdfFileName, 'folder' => $folderName]);
    }
}
