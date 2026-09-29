<?php
namespace App\Handlers;

use App\Auth;
use App\Database;
use App\Services\Kupferpreis;

class CatalogActions
{
    public function __construct(private \PDO $db, private array $body) {}

    private const METALL_PROFILE_DEFAULTS = [
        ['typ' => 'halbzeug', 'kategorie' => 'Flachstahl', 'bezeichnung' => '40x5', 'kgProM' => 1.57, 'material' => 'S235'],
        ['typ' => 'halbzeug', 'kategorie' => 'Flachstahl', 'bezeichnung' => '50x8', 'kgProM' => 3.14, 'material' => 'S235'],
        ['typ' => 'halbzeug', 'kategorie' => 'Rundstahl', 'bezeichnung' => '12', 'kgProM' => 0.89, 'material' => 'S235'],
        ['typ' => 'halbzeug', 'kategorie' => 'Rundstahl', 'bezeichnung' => '20', 'kgProM' => 2.47, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'Vierkantrohr', 'bezeichnung' => '40x40x2', 'kgProM' => 2.39, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'Vierkantrohr', 'bezeichnung' => '60x60x3', 'kgProM' => 5.33, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'Rechteckrohr', 'bezeichnung' => '60x40x3', 'kgProM' => 4.39, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'Winkel', 'bezeichnung' => '40x40x4', 'kgProM' => 2.42, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'U-Profil', 'bezeichnung' => 'UPE 80', 'kgProM' => 8.64, 'material' => 'S235'],
        ['typ' => 'profil', 'kategorie' => 'HEA', 'bezeichnung' => 'HEA 100', 'kgProM' => 16.70, 'material' => 'S235'],
    ];

    // ══════════════════════════════════════════════════════════
    // DATANORM
    // ══════════════════════════════════════════════════════════

    public function datanormReindex(): void
    {
        Auth::requireRole('admin', 'master');
        $datanormDir = defined('DATANORM_DIR') ? DATANORM_DIR : (dirname(__DIR__, 2) . '/Datanorm/');
        $datanormDir = rtrim($datanormDir, '/\\') . '/';
        $indexFile   = DATA_DIR . 'datanorm_index.tsv';

        if (!is_dir($datanormDir)) {
            jsonOut(['error' => "Datanorm-Verzeichnis nicht gefunden: $datanormDir", 'count' => 0], 400);
        }

        // Tolerant gegenüber Groß-/Kleinschreibung und alternativen Endungen.
        $findFile = function(array $candidates) use ($datanormDir): ?string {
            foreach ($candidates as $name) {
                $p = $datanormDir . $name;
                if (file_exists($p)) return $p;
            }
            // Fallback: case-insensitive scan
            $entries = @scandir($datanormDir) ?: [];
            foreach ($entries as $e) {
                foreach ($candidates as $name) {
                    if (strcasecmp($e, $name) === 0) return $datanormDir . $e;
                }
            }
            return null;
        };

        $artFile = $findFile(['datanorm.001', 'DATANORM.001']);
        $wrgFile = $findFile(['datanorm.wrg', 'DATANORM.WRG']);
        $prcFile = $findFile(['datpreis.001', 'DATPREIS.001']);

        if (!$artFile) {
            jsonOut([
                'error' => "Keine datanorm.001 in '$datanormDir' gefunden. Bitte Pfad in den Speicherpfaden prüfen.",
                'count' => 0,
                'searched' => $datanormDir,
            ], 400);
        }

        // Warengruppen
        $wgMap  = [];
        if ($wrgFile && file_exists($wrgFile)) {
            $fh = fopen($wrgFile, 'r');
            while (($line = fgets($fh)) !== false) {
                $line = mb_convert_encoding(rtrim($line, "\r\n"), 'UTF-8', 'ISO-8859-1');
                $p = explode(';', $line);
                if (($p[0] ?? '') !== 'S') continue;
                $hg = trim($p[1] ?? ''); $ug = trim($p[2] ?? ''); $name = trim($p[3] ?? '');
                if ($hg !== '' && $name !== '') {
                    $wgMap[($ug !== '') ? "{$hg}-{$ug}" : "g{$hg}"] = $name;
                }
            }
            fclose($fh);
        }

        // Preise
        $prices = [];
        if ($prcFile && file_exists($prcFile)) {
            $fh = fopen($prcFile, 'r');
            while (($line = fgets($fh)) !== false) {
                $p = explode(';', $line);
                if (($p[0] ?? '') !== 'P') continue;
                $artNr = trim($p[1] ?? ''); $pkz = (int)($p[2] ?? 0); $pe = (int)($p[3] ?? 1); $preis = (int)($p[4] ?? 0);
                if ($artNr === '' || $pe < 1) continue;
                if (!isset($prices[$artNr])) $prices[$artNr] = ['lp'=>0,'ek'=>0,'pe'=>$pe];
                $prices[$artNr]['pe'] = $pe;
                if ($pkz === 1) $prices[$artNr]['lp'] = $preis;
                if ($pkz === 2) $prices[$artNr]['ek'] = $preis;
            }
            fclose($fh);
        }

        // Artikel
        $count = 0;
        $out = fopen($indexFile, 'w');
        if (!$out) jsonOut(['error' => "Index-Datei '$indexFile' nicht beschreibbar.", 'count' => 0], 500);
        $fh  = fopen($artFile, 'r');
        $einheitMap = ['STK'=>'Stk.','STCK'=>'Stk.','ST'=>'Stk.','STUE'=>'Stk.','MTR'=>'m','M'=>'m','M2'=>'m²','QM'=>'m²','M3'=>'m³','CBM'=>'m³','KG'=>'kg','T'=>'t','L'=>'l','LTR'=>'l','LFM'=>'lfm','VE'=>'VE','PACK'=>'VE','PCK'=>'VE','PAK'=>'VE','BDL'=>'VE','ROL'=>'Rolle','RG'=>'Ring','SET'=>'Set','PAA'=>'Paar','STD'=>'h','H'=>'h'];
        while (($line = fgets($fh)) !== false) {
            $line = mb_convert_encoding(rtrim($line, "\r\n"), 'UTF-8', 'ISO-8859-1');
            $p = explode(';', $line);
            if (($p[0] ?? '') !== 'A') continue;
            $artNr = trim($p[2] ?? ''); $text1 = trim($p[3] ?? ''); $text2 = trim($p[4] ?? '');
            $me = strtoupper(trim($p[5] ?? '')); $peArt = (int)($p[7] ?? 1) ?: 1;
            $preisArt = (int)($p[8] ?? 0); $hwg = trim($p[10] ?? ''); $wg = trim($p[11] ?? '');
            $bezeichnung = $text1 . ($text2 !== '' ? ' ' . $text2 : '');
            if ($artNr === '' || $bezeichnung === '') continue;
            $einheit = $einheitMap[$me] ?? 'Stk.';
            $ekCent = 0; $lpCent = 0; $pe = $peArt;
            if (isset($prices[$artNr])) { $pe = $prices[$artNr]['pe'] ?: $peArt; $ekCent = $prices[$artNr]['ek']; $lpCent = $prices[$artNr]['lp']; }
            if ($ekCent === 0) $ekCent = $preisArt;
            if ($lpCent === 0) $lpCent = $preisArt;
            $ekEur = round($ekCent / 100 / max($pe, 1), 4);
            $lpEur = round($lpCent / 100 / max($pe, 1), 4);
            $wgKey = ($hwg !== '' && $wg !== '') ? "{$hwg}-{$wg}" : '';
            $wgName = $wgMap[$wgKey] ?? $wgMap["g{$hwg}"] ?? '';
            fwrite($out, implode("\t", [$artNr, $bezeichnung, $einheit, $ekEur, $lpEur, $wgName]) . "\n");
            $count++;
        }
        fclose($fh);
        fclose($out);

        jsonOut(['ok' => true, 'count' => $count, 'source' => $datanormDir]);
    }

    public function datanormSearch(): void
    {
        Auth::requireAuth();
        $q = trim($this->body['q'] ?? $_GET['q'] ?? '');
        if (mb_strlen($q) < 2) jsonOut(['results' => []]);
        $indexFile = DATA_DIR . 'datanorm_index.tsv';
        if (!file_exists($indexFile)) jsonOut(['results' => [], 'hint' => 'Index nicht vorhanden.']);
        $terms = array_filter(array_map('trim', explode(' ', mb_strtolower($q, 'UTF-8'))));
        if (empty($terms)) jsonOut(['results' => []]);
        $results = [];
        $fh = fopen($indexFile, 'r');
        while (($line = fgets($fh)) !== false && count($results) < 50) {
            $lower = mb_strtolower($line, 'UTF-8');
            $ok = true;
            foreach ($terms as $t) { if (mb_strpos($lower, $t) === false) { $ok = false; break; } }
            if (!$ok) continue;
            $p = explode("\t", rtrim($line, "\r\n"));
            $results[] = ['artNr' => $p[0] ?? '', 'bezeichnung' => $p[1] ?? '', 'einheit' => $p[2] ?? 'Stk.', 'ek' => round((float)($p[3] ?? 0), 4), 'lp' => round((float)($p[4] ?? 0), 4), 'warengruppe' => $p[5] ?? ''];
        }
        fclose($fh);
        jsonOut(['results' => $results]);
    }

    // v2.0.1: Status der Datanorm-Quelle (Verzeichnis + vorhandene Dateien + Index).
    public function datanormStatus(): void
    {
        Auth::requireRole('admin', 'master');
        $dir = defined('DATANORM_DIR') ? DATANORM_DIR : (DATA_DIR . 'datanorm/');
        $dir = rtrim($dir, '/\\') . '/';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);

        $resolve = function(array $candidates) use ($dir): array {
            $entries = @scandir($dir) ?: [];
            foreach ($candidates as $name) {
                foreach ($entries as $e) {
                    if (strcasecmp($e, $name) === 0) {
                        $p = $dir . $e;
                        return ['present' => true, 'name' => $e, 'size' => (int)@filesize($p), 'mtime' => (int)@filemtime($p)];
                    }
                }
            }
            return ['present' => false];
        };

        $artFile = $resolve(['datanorm.001']);
        $wrgFile = $resolve(['datanorm.wrg']);
        $prcFile = $resolve(['datpreis.001']);

        $indexFile = DATA_DIR . 'datanorm_index.tsv';
        $indexInfo = file_exists($indexFile)
            ? ['present' => true, 'size' => (int)@filesize($indexFile), 'mtime' => (int)@filemtime($indexFile), 'lines' => $this->countLines($indexFile)]
            : ['present' => false];

        jsonOut([
            'ok' => true,
            'directory' => $dir,
            'writable'  => is_writable($dir),
            'files' => [
                'datanorm.001' => $artFile,
                'datanorm.wrg' => $wrgFile,
                'datpreis.001' => $prcFile,
            ],
            'index' => $indexInfo,
        ]);
    }

    private function countLines(string $path): int
    {
        $n = 0;
        $fh = @fopen($path, 'r');
        if (!$fh) return 0;
        while (!feof($fh)) {
            $b = fread($fh, 65536);
            if ($b === false) break;
            $n += substr_count($b, "\n");
        }
        fclose($fh);
        return $n;
    }

    // v2.0.1: Datanorm-Datei(en) hochladen (multipart/form-data).
    // Akzeptiert Datei-Felder im Multipart-Body: datanorm.001 / datanorm.wrg / datpreis.001
    // Generisches Feld 'file' ist auch erlaubt – Zielname wird aus dem Original-Dateinamen
    // abgeleitet, sofern dieser einer der drei Standardnamen entspricht.
    public function datanormUpload(): void
    {
        Auth::requireRole('admin', 'master');
        $dir = defined('DATANORM_DIR') ? DATANORM_DIR : (DATA_DIR . 'datanorm/');
        $dir = rtrim($dir, '/\\') . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            jsonOut(['error' => "Datanorm-Verzeichnis '$dir' konnte nicht angelegt werden."], 500);
        }
        if (!is_writable($dir)) jsonOut(['error' => "Datanorm-Verzeichnis '$dir' ist nicht beschreibbar."], 500);

        if (empty($_FILES)) jsonOut(['error' => 'Keine Dateien hochgeladen.'], 400);

        $allowed = ['datanorm.001', 'datanorm.wrg', 'datpreis.001'];
        $maxSize = 100 * 1024 * 1024; // 100 MB (passt zu upload_max_filesize)
        $saved = [];

        foreach ($_FILES as $field => $f) {
            if (!is_array($f) || empty($f['tmp_name'])) continue;
            // PHP liefert bei mehreren Files unter einem Feld arrays – wir behandeln nur Einzeldateien.
            if (is_array($f['tmp_name'])) {
                jsonOut(['error' => 'Bitte je Feld genau eine Datei senden.'], 400);
            }
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                jsonOut(['error' => "Upload-Fehler bei '$field' (Code " . (int)$f['error'] . ").", 'field' => $field], 400);
            }
            if (($f['size'] ?? 0) > $maxSize) {
                jsonOut(['error' => "Datei '$field' überschreitet 200 MB."], 400);
            }

            // Zielnamen ermitteln: zuerst Feldname, dann Original-Dateiname
            $candidate = strtolower(trim($field));
            if (!in_array($candidate, $allowed, true)) {
                $candidate = strtolower(basename($f['name'] ?? ''));
            }
            if (!in_array($candidate, $allowed, true)) {
                jsonOut(['error' => "Unbekannter Dateityp '$field' / '" . ($f['name'] ?? '') . "'. Erlaubt: " . implode(', ', $allowed)], 400);
            }
            $target = $dir . $candidate;
            if (!@move_uploaded_file($f['tmp_name'], $target)) {
                jsonOut(['error' => "Speichern von '$candidate' fehlgeschlagen."], 500);
            }
            @chmod($target, 0640);
            $saved[$candidate] = (int)@filesize($target);
        }

        if (empty($saved)) jsonOut(['error' => 'Keine gültige Datei verarbeitet.'], 400);
        jsonOut(['ok' => true, 'saved' => $saved, 'directory' => $dir]);
    }

    // v2.0.1: Datanorm-Quelldateien + Index löschen (Reset).
    public function datanormClear(): void
    {
        Auth::requireRole('admin', 'master');
        $dir = defined('DATANORM_DIR') ? DATANORM_DIR : (DATA_DIR . 'datanorm/');
        $dir = rtrim($dir, '/\\') . '/';
        $deleted = [];
        if (is_dir($dir)) {
            foreach (['datanorm.001','datanorm.wrg','datpreis.001'] as $name) {
                foreach ((@scandir($dir) ?: []) as $e) {
                    if (strcasecmp($e, $name) === 0 && @unlink($dir . $e)) $deleted[] = $e;
                }
            }
        }
        $indexFile = DATA_DIR . 'datanorm_index.tsv';
        if (file_exists($indexFile) && @unlink($indexFile)) $deleted[] = 'datanorm_index.tsv';
        jsonOut(['ok' => true, 'deleted' => $deleted]);
    }

    // ══════════════════════════════════════════════════════════
    // METALLZUSCHLAG
    // ══════════════════════════════════════════════════════════

    private const METALLZUSCHLAG_DEFAULTS = ['delNotierung' => 0, 'basisNotierung' => Kupferpreis::BASIS_STANDARD, 'datum' => '', 'quelle' => 'none'];

    public function metallzuschlagGet(): void
    {
        Auth::requireAuth();
        if (!$this->kupferAktiv()) jsonOut(['delNotierung' => 0, 'aktiv' => false]);
        $data = $this->loadMetallzuschlag();
        jsonOut(array_merge(self::METALLZUSCHLAG_DEFAULTS, $data, [
            'veraltet' => $this->kupferpreis()->istVeraltet($data),
            'aktiv'    => true,
        ]));
    }

    public function metallzuschlagSet(): void
    {
        Auth::requireRole('admin', 'master');
        $del = $this->body['delNotierung'] ?? null;
        $del = (is_int($del) || is_float($del) || (is_string($del) && is_numeric($del))) ? (float)$del : NAN;
        if (!is_finite($del) || $del < Kupferpreis::PREIS_MIN || $del > Kupferpreis::PREIS_MAX) {
            jsonOut(['error' => 'DEL-Notierung muss zwischen 100 und 5000 €/100 kg liegen.'], 400);
        }
        $basis = $this->body['basisNotierung'] ?? Kupferpreis::BASIS_STANDARD;
        $basis = (is_int($basis) || is_float($basis) || (is_string($basis) && is_numeric($basis))) ? (float)$basis : NAN;
        if (!is_finite($basis) || $basis < 0 || $basis > Kupferpreis::PREIS_MAX) {
            jsonOut(['error' => 'Basis-Notierung muss zwischen 0 und 5000 €/100 kg liegen.'], 400);
        }
        $heute = date('Y-m-d');
        $data = [
            'delNotierung'   => $del,
            'basisNotierung' => $basis,
            'stand'          => $heute,
            'datum'          => $heute,
            'quelle'         => 'manual',
            'updated'        => date('c'),
        ];
        $this->saveMetallzuschlag($data);
        jsonOut(['ok' => true] + $data + ['veraltet' => false, 'aktiv' => true]);
    }

    public function metallzuschlagAutoFetch(): void
    {
        Auth::requireAuth();
        $force = !empty($_GET['force'] ?? $this->body['force'] ?? null);
        if ($force) Auth::requireRole('admin', 'master');
        if (!$this->kupferAktiv()) jsonOut(['delNotierung' => 0, 'aktiv' => false]);
        // force überschreibt den manuellen Wert – nicht per GET-Link auslösbar (CSRF).
        if ($force && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Erzwungener Abruf nur per POST.'], 405);
        }

        $roh = $this->loadMetallzuschlagRoh();
        $data = self::decodeMetallzuschlag($roh);
        $kp = $this->kupferpreis();
        if (!$kp->sollAbrufen($data, true, $force)) {
            jsonOut(array_merge(self::METALLZUSCHLAG_DEFAULTS, $data, [
                'veraltet' => $kp->istVeraltet($data),
                'aktiv'    => true,
                'cached'   => true,
            ]));
        }

        $neu = $kp->aktualisieren($data, $force);
        $gespeichert = $neu;
        unset($gespeichert['veraltet']);
        // Während des langsamen Abrufs gespeicherte Werte (z. B. manuell) nicht überschreiben.
        if (!$this->saveMetallzuschlag($gespeichert, $roh)) {
            $aktuell = $this->loadMetallzuschlag();
            jsonOut(array_merge(self::METALLZUSCHLAG_DEFAULTS, $aktuell, [
                'veraltet' => $kp->istVeraltet($aktuell),
                'aktiv'    => true,
                'cached'   => true,
            ]));
        }

        if (!empty($neu['fetchError'])) {
            error_log('[Kupferpreis] ' . $neu['fetchError']);
            jsonOut(array_merge(self::METALLZUSCHLAG_DEFAULTS, $neu, ['aktiv' => true]));
        }
        jsonOut(['ok' => true] + $neu + ['aktiv' => true]);
    }

    private function kupferAktiv(): bool
    {
        return (Auth::loadSettings($this->db)['modul_kupfer_del'] ?? true) !== false;
    }

    private function kupferpreis(): Kupferpreis
    {
        return new Kupferpreis(static fn (string $url): ?string => fetchUrl($url, 5));
    }

    /** @return array<string,mixed> */
    private function loadMetallzuschlag(): array
    {
        return self::decodeMetallzuschlag($this->loadMetallzuschlagRoh());
    }

    private function loadMetallzuschlagRoh(): ?string
    {
        $row = $this->db->query("SELECT data FROM metallzuschlag WHERE id = 1")->fetch();
        return ($row && $row['data'] !== null) ? (string)$row['data'] : null;
    }

    /** @return array<string,mixed> */
    private static function decodeMetallzuschlag(?string $roh): array
    {
        $data = $roh !== null ? json_decode($roh, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Mit $erwartet nur schreiben, wenn die Zeile noch diesen Rohtext enthält.
     *
     * @param array<string,mixed> $data
     */
    private function saveMetallzuschlag(array $data, ?string $erwartet = null): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($erwartet === null) {
            $this->db->prepare("UPDATE metallzuschlag SET data = ? WHERE id = 1")->execute([$json]);
            return true;
        }
        $stmt = $this->db->prepare("UPDATE metallzuschlag SET data = ? WHERE id = 1 AND data = ?");
        $stmt->execute([$json, $erwartet]);
        return $stmt->rowCount() > 0;
    }

    public function metallprofileCatalog(): void
    {
        Auth::requireAuth();
        $state = $this->ensureMetallProfileCatalog(false);
        jsonOut([
            'ok' => true,
            'entries' => $state['entries'] ?? [],
            'updated' => $state['updated'] ?? '',
            'quelle' => $state['quelle'] ?? 'local',
            'autoUpdateDue' => $this->isMetallProfileUpdateDue($state),
            'fetchError' => $state['fetchError'] ?? '',
        ]);
    }

    public function metallprofileAutoUpdate(): void
    {
        Auth::requireRole('admin', 'master');
        $state = $this->ensureMetallProfileCatalog(true);
        jsonOut([
            'ok' => true,
            'entries' => $state['entries'] ?? [],
            'updated' => $state['updated'] ?? '',
            'quelle' => $state['quelle'] ?? 'local',
            'fetchError' => $state['fetchError'] ?? '',
        ]);
    }

    private function ensureMetallProfileCatalog(bool $forceUpdate): array
    {
        $row = $this->db->query("SELECT data FROM metall_profile_catalog WHERE id = 1")->fetch();
        $state = $row ? (json_decode($row['data'], true) ?: []) : [];
        $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];

        $baseDir   = dirname(__DIR__, 2);
        $hicaidDir = $baseDir . '/Materialbibliothek HiCAD';
        $hasHiCad  = is_dir($hicaidDir);

        // Bevorzugte schlanke Quelle: CSV-Übersicht (im Image enthalten,
        // ersetzt die große HiCAD-Rohbibliothek auf kleinen NAS-Systemen).
        $csvFile   = $baseDir . '/Materialbibliothek_Uebersicht.csv';
        $hasCsv    = is_file($csvFile);

        if ($hasCsv && !$hasHiCad && ($forceUpdate || empty($entries) || (($state['quelle'] ?? '') !== 'hicaid-csv'))) {
            $csvEntries = $this->loadHiCadMetallProfileCsv($csvFile);
            if (!empty($csvEntries)) {
                $state = [
                    'entries' => $csvEntries,
                    'updated' => date('c'),
                    'quelle'  => 'hicaid-csv',
                ];
                $this->saveMetallProfileCatalog($state);
                return $state;
            }
        }

        // Lokale HiCAD-Bibliothek priorisieren, wenn vorhanden.
        if ($hasHiCad && ($forceUpdate || empty($entries) || (($state['quelle'] ?? '') !== 'hicaid-local'))) {
            $hicaidEntries = $this->loadHiCadMetallProfileCatalog($hicaidDir);
            if (!empty($hicaidEntries)) {
                $state = [
                    'entries' => $hicaidEntries,
                    'updated' => date('c'),
                    'quelle' => 'hicaid-local',
                ];
                $this->saveMetallProfileCatalog($state);
                return $state;
            }
        }

        if (empty($entries)) {
            $state = [
                'entries' => self::METALL_PROFILE_DEFAULTS,
                'updated' => date('c'),
                'quelle' => 'local-default',
            ];
            $this->saveMetallProfileCatalog($state);
            return $state;
        }

        if (!$forceUpdate && !$this->isMetallProfileUpdateDue($state)) {
            return $state;
        }

        $updated = $this->fetchOnlineMetallProfileCatalog();
        if ($updated !== null && !empty($updated)) {
            $state['entries'] = $updated;
            $state['updated'] = date('c');
            $state['quelle'] = 'online-json';
            unset($state['fetchError']);
            $this->saveMetallProfileCatalog($state);
            return $state;
        }

        $state['fetchError'] = 'Online-Aktualisierung fehlgeschlagen; lokale Daten bleiben aktiv.';
        $this->saveMetallProfileCatalog($state);
        return $state;
    }

    private function loadHiCadMetallProfileCatalog(string $dir): array
    {
        $files = glob($dir . '/*.IPT') ?: [];
        if (empty($files)) return [];

        $result = [];
        $seen = [];
        $maxEntries = 15000;

        foreach ($files as $file) {
            if (count($result) >= $maxEntries) break;

            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (!$lines) continue;

            $section = '';
            $params = [];
            $category = '';

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || str_starts_with($line, '#')) continue;

                if ($line[0] === '[' && str_ends_with($line, ']')) {
                    $section = strtoupper(trim($line, '[]'));
                    continue;
                }

                if ($section === 'CATEGORY') {
                    if ($category === '') $category = strtoupper($line);
                    continue;
                }

                if ($section === 'PARAMETER') {
                    if (strpos($line, ':') !== false) {
                        [, $paramRaw] = explode(':', $line, 2);
                        $paramRaw = trim($paramRaw);
                        $param = preg_replace('/[^A-Z0-9_].*$/i', '', strtoupper($paramRaw));
                        $params[] = $param;
                    }
                    continue;
                }

                if ($section !== 'DATA') continue;

                $cols = preg_split('/\t+/', $line);
                if (!is_array($cols) || count($cols) < 2) continue;

                $idxGew = array_search('GEW', $params, true);
                $idxBez = array_search('BEZ', $params, true);
                $idxBz  = array_search('BZ', $params, true);
                $idxSize = array_search('SIZE', $params, true);
                $idxMat = array_search('MATERIAL', $params, true);

                $kg = ($idxGew !== false && isset($cols[$idxGew])) ? (float)str_replace(',', '.', $cols[$idxGew]) : 0.0;
                if ($kg <= 0 || $kg > 5000) continue;

                $bezCandidates = [];
                if ($idxBez !== false && isset($cols[$idxBez])) $bezCandidates[] = trim($cols[$idxBez], " '\"");
                if ($idxBz !== false && isset($cols[$idxBz])) $bezCandidates[] = trim($cols[$idxBz], " '\"");
                if ($idxSize !== false && isset($cols[$idxSize])) $bezCandidates[] = trim($cols[$idxSize], " '\"");
                $bezeichnung = '';
                foreach ($bezCandidates as $candidate) {
                    if ($candidate !== '') { $bezeichnung = $candidate; break; }
                }
                if ($bezeichnung === '') continue;

                $material = ($idxMat !== false && isset($cols[$idxMat]))
                    ? trim($cols[$idxMat], " '\"")
                    : 'S235';

                $catRaw = strtoupper(pathinfo($file, PATHINFO_FILENAME));
                $categoryText = str_replace('_', ' ', $catRaw);
                if ($category !== '') {
                    $catHint = trim(explode(':', $category)[0]);
                    if ($catHint !== '') $categoryText = $catHint;
                }

                $type = 'profil';
                if (preg_match('/BLECH|FLACH|RUNDSTAHL|VIERKANTSTAHL|SECHSKANT|HALBZEUG|HOLZ|GITTERROST/i', $categoryText)) {
                    $type = 'halbzeug';
                }

                $key = mb_strtolower($type . '|' . $categoryText . '|' . $bezeichnung, 'UTF-8');
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $result[] = [
                    'typ' => $type,
                    'kategorie' => $categoryText,
                    'bezeichnung' => $bezeichnung,
                    'kgProM' => round($kg, 4),
                    'material' => $material !== '' ? $material : 'S235',
                ];
            }
        }

        return $result;
    }

    /**
     * Schlanke CSV-Variante der HiCAD-Materialbibliothek.
     * Format: Datei;Kategorie;Bezeichnung;Groesse;Material;Gewicht_kg_pro_m;Norm
     * Wird genutzt wenn die Roh-Bibliothek (HiCAD-Ordner) nicht im Image ist.
     */
    private function loadHiCadMetallProfileCsv(string $file): array
    {
        $fh = @fopen($file, 'r');
        if (!$fh) return [];

        $result = [];
        $seen   = [];
        $header = null;

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if ($header === null) {
                $header = array_map(static fn($s) => trim((string)$s), $row);
                continue;
            }
            if (count($row) < 5) continue;

            $rec = array_combine(
                array_slice($header, 0, count($row)),
                array_slice($row, 0, count($header))
            );
            if (!is_array($rec)) continue;

            $datei       = (string)($rec['Datei'] ?? '');
            $kategorie   = (string)($rec['Kategorie'] ?? '');
            $bezeichnung = trim((string)($rec['Bezeichnung'] ?? ''));
            $material    = trim((string)($rec['Material'] ?? '')) ?: 'S235';
            $kg          = (float)str_replace(',', '.', (string)($rec['Gewicht_kg_pro_m'] ?? '0'));

            if ($bezeichnung === '' || $kg <= 0 || $kg > 5000) continue;

            $categoryText = $kategorie !== ''
                ? trim(explode(':', $kategorie)[0])
                : strtoupper(pathinfo($datei, PATHINFO_FILENAME));
            $categoryText = str_replace('_', ' ', $categoryText);

            $type = 'profil';
            if (preg_match('/BLECH|FLACH|RUNDSTAHL|VIERKANTSTAHL|SECHSKANT|HALBZEUG|HOLZ|GITTERROST/i', $categoryText)) {
                $type = 'halbzeug';
            }

            $key = mb_strtolower($type . '|' . $categoryText . '|' . $bezeichnung, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $result[] = [
                'typ'         => $type,
                'kategorie'   => $categoryText,
                'bezeichnung' => $bezeichnung,
                'kgProM'      => round($kg, 4),
                'material'    => $material,
            ];
        }
        fclose($fh);
        return $result;
    }

    private function isMetallProfileUpdateDue(array $state): bool
    {
        $updated = strtotime((string)($state['updated'] ?? ''));
        if (!$updated) return true;
        return (time() - $updated) > (30 * 24 * 3600);
    }

    private function saveMetallProfileCatalog(array $state): void
    {
        $this->db->prepare("UPDATE metall_profile_catalog SET data = ? WHERE id = 1")
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    private function fetchOnlineMetallProfileCatalog(): ?array
    {
        $urls = [
            'https://raw.githubusercontent.com/metallbau-tools/profile-weights/main/de_profiles.json',
            'https://raw.githubusercontent.com/metallbau-tools/profile-weights/main/profiles.json',
        ];
        foreach ($urls as $url) {
            try {
                $raw = fetchUrl($url);
                if (!$raw) continue;
                $json = json_decode($raw, true);
                if (!is_array($json)) continue;
                $list = is_array($json['entries'] ?? null) ? $json['entries'] : $json;
                $normalized = [];
                foreach ($list as $entry) {
                    if (!is_array($entry)) continue;
                    $kg = (float)($entry['kgProM'] ?? $entry['kg_per_m'] ?? 0);
                    $bez = trim((string)($entry['bezeichnung'] ?? $entry['size'] ?? ''));
                    $kat = trim((string)($entry['kategorie'] ?? $entry['type'] ?? 'Profil'));
                    if ($kg <= 0 || $bez === '') continue;
                    $normalized[] = [
                        'typ' => trim((string)($entry['typ'] ?? 'profil')) ?: 'profil',
                        'kategorie' => $kat,
                        'bezeichnung' => $bez,
                        'kgProM' => round($kg, 4),
                        'material' => trim((string)($entry['material'] ?? 'S235')),
                    ];
                }
                if (!empty($normalized)) return $normalized;
            } catch (\Throwable) {
                // next source
            }
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════
    // HICAD-MATERIALBIBLIOTHEK (eigene Tabelle hicad_profile)
    // ══════════════════════════════════════════════════════════

    public function hicadList(): void
    {
        Auth::requireAuth();
        $kategorie = trim((string)($_GET['kategorie'] ?? ''));
        $typ       = trim((string)($_GET['typ'] ?? ''));

        $sql = "SELECT id, kategorie_pfad, kategorie, tabelle, bezeichnung, typ, kg_pro_m, material
                FROM hicad_profile WHERE 1=1";
        $params = [];
        if ($kategorie !== '') { $sql .= " AND kategorie = ?"; $params[] = $kategorie; }
        if ($typ !== '')       { $sql .= " AND typ = ?";       $params[] = $typ; }
        $sql .= " ORDER BY kategorie, bezeichnung";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $entries = [];
        foreach ($stmt->fetchAll() as $r) {
            $entries[] = [
                'id'            => (int)$r['id'],
                'kategoriePfad' => $r['kategorie_pfad'],
                'kategorie'     => $r['kategorie'],
                'tabelle'       => $r['tabelle'],
                'bezeichnung'   => $r['bezeichnung'],
                'typ'           => $r['typ'],
                'kgProM'        => $r['kg_pro_m'] !== null ? (float)$r['kg_pro_m'] : null,
                'material'      => $r['material'] ?: 'S235',
            ];
        }

        $stateRow = $this->db->query("SELECT data FROM hicad_profile_state WHERE id = 1")->fetch();
        $state = $stateRow ? (json_decode($stateRow['data'], true) ?: []) : [];

        jsonOut([
            'ok'       => true,
            'entries'  => $entries,
            'count'    => count($entries),
            'updated'  => $state['updated']    ?? '',
            'matched'  => $state['matched']    ?? 0,
            'fehler'   => $state['fehler']     ?? '',
        ]);
    }

    public function hicadImport(): void
    {
        Auth::requireRole('admin', 'master');

        $baseDir = dirname(__DIR__, 2);
        $dir     = $baseDir . '/Materialbibliothek HiCAD';
        $csv     = $baseDir . '/Materialbibliothek_Uebersicht.csv';

        // Wenn die Roh-Bibliothek nicht im Image ist, aus CSV-Übersicht importieren.
        if (!is_dir($dir)) {
            if (is_file($csv)) {
                $this->hicadImportFromCsv($csv);
                return;
            }
            jsonOut(['ok' => false, 'error' => 'Weder "Materialbibliothek HiCAD" noch "Materialbibliothek_Uebersicht.csv" vorhanden.']);
        }

        try {
            $entries = $this->parseHicadIplCatalog($dir);
        } catch (\Throwable $e) {
            jsonOut(['ok' => false, 'error' => 'IPL-Parser: ' . $e->getMessage()]);
        }

        if (empty($entries)) {
            jsonOut(['ok' => false, 'error' => 'Keine Einträge in HALBZEUGE.IPL gefunden.']);
        }

        // Gewichte (kg/m) aus Online-Katalog matchen (falls verfügbar)
        $weights = $this->fetchOnlineMetallProfileCatalog() ?? self::METALL_PROFILE_DEFAULTS;
        $weightMap = [];
        foreach ($weights as $w) {
            $bez = mb_strtolower(trim((string)($w['bezeichnung'] ?? '')), 'UTF-8');
            if ($bez === '') continue;
            $weightMap[$bez] = (float)($w['kgProM'] ?? 0);
        }

        $matched = 0;
        foreach ($entries as &$e) {
            $bez = mb_strtolower($e['bezeichnung'], 'UTF-8');
            if (isset($weightMap[$bez]) && $weightMap[$bez] > 0) {
                $e['kgProM'] = $weightMap[$bez];
                $matched++;
            }
        }
        unset($e);

        $now = date('c');

        $this->db->beginTransaction();
        try {
            $this->db->exec("DELETE FROM hicad_profile");
            $stmt = $this->db->prepare(
                "INSERT INTO hicad_profile
                 (kategorie_pfad, kategorie, tabelle, bezeichnung, typ, kg_pro_m, material, quelle, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($entries as $e) {
                $stmt->execute([
                    $e['kategoriePfad'],
                    $e['kategorie'],
                    $e['tabelle'],
                    $e['bezeichnung'],
                    $e['typ'],
                    isset($e['kgProM']) && $e['kgProM'] > 0 ? round($e['kgProM'], 4) : null,
                    $e['material'] ?: 'S235',
                    'hicad-ipl',
                    $now,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            jsonOut(['ok' => false, 'error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()]);
        }

        $state = [
            'updated' => $now,
            'count'   => count($entries),
            'matched' => $matched,
            'quelle'  => 'hicad-ipl',
        ];
        $this->db->prepare("UPDATE hicad_profile_state SET data = ? WHERE id = 1")
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE)]);

        jsonOut([
            'ok'      => true,
            'count'   => count($entries),
            'matched' => $matched,
            'updated' => $now,
        ]);
    }

    /**
     * Import der HiCAD-Materialdaten aus der CSV-Übersicht
     * (Materialbibliothek_Uebersicht.csv). Wird genutzt, wenn das
     * Image ohne die große Roh-Bibliothek gebaut wurde.
     */
    private function hicadImportFromCsv(string $csvFile): void
    {
        $fh = @fopen($csvFile, 'r');
        if (!$fh) {
            jsonOut(['ok' => false, 'error' => 'CSV konnte nicht geöffnet werden.']);
        }

        $header  = null;
        $entries = [];
        $seen    = [];

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if ($header === null) {
                $header = array_map(static fn($s) => trim((string)$s), $row);
                continue;
            }
            if (count($row) < 5) continue;
            $rec = array_combine(
                array_slice($header, 0, count($row)),
                array_slice($row, 0, count($header))
            );
            if (!is_array($rec)) continue;

            $datei       = (string)($rec['Datei'] ?? '');
            $kategorie   = (string)($rec['Kategorie'] ?? '');
            $bezeichnung = trim((string)($rec['Bezeichnung'] ?? ''));
            $material    = trim((string)($rec['Material'] ?? '')) ?: 'S235';
            $kg          = (float)str_replace(',', '.', (string)($rec['Gewicht_kg_pro_m'] ?? '0'));

            if ($bezeichnung === '') continue;

            $kategoriePfad = $kategorie !== '' ? $kategorie : strtoupper(pathinfo($datei, PATHINFO_FILENAME));
            $kategorieText = trim(explode(':', $kategoriePfad)[0]);
            $tabelle       = strtoupper(pathinfo($datei, PATHINFO_FILENAME));

            $type = 'profil';
            if (preg_match('/BLECH|FLACH|RUNDSTAHL|VIERKANTSTAHL|SECHSKANT|HALBZEUG|HOLZ|GITTERROST/i', $kategorieText . ' ' . $tabelle)) {
                $type = 'halbzeug';
            }

            $key = mb_strtolower($tabelle . '|' . $bezeichnung, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $entries[] = [
                'kategoriePfad' => $kategoriePfad,
                'kategorie'     => $kategorieText,
                'tabelle'       => $tabelle,
                'bezeichnung'   => $bezeichnung,
                'typ'           => $type,
                'kgProM'        => $kg > 0 ? $kg : 0.0,
                'material'      => $material,
            ];
        }
        fclose($fh);

        if (empty($entries)) {
            jsonOut(['ok' => false, 'error' => 'CSV enthält keine Einträge.']);
        }

        $now     = date('c');
        $matched = 0;
        foreach ($entries as $e) {
            if (($e['kgProM'] ?? 0) > 0) $matched++;
        }

        $this->db->beginTransaction();
        try {
            $this->db->exec("DELETE FROM hicad_profile");
            $stmt = $this->db->prepare(
                "INSERT INTO hicad_profile
                 (kategorie_pfad, kategorie, tabelle, bezeichnung, typ, kg_pro_m, material, quelle, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($entries as $e) {
                $stmt->execute([
                    $e['kategoriePfad'],
                    $e['kategorie'],
                    $e['tabelle'],
                    $e['bezeichnung'],
                    $e['typ'],
                    ($e['kgProM'] ?? 0) > 0 ? round((float)$e['kgProM'], 4) : null,
                    $e['material'] ?: 'S235',
                    'hicad-csv',
                    $now,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            jsonOut(['ok' => false, 'error' => 'Speichern fehlgeschlagen: ' . $e->getMessage()]);
        }

        $state = [
            'updated' => $now,
            'count'   => count($entries),
            'matched' => $matched,
            'quelle'  => 'hicad-csv',
        ];
        $this->db->prepare("UPDATE hicad_profile_state SET data = ? WHERE id = 1")
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE)]);

        jsonOut([
            'ok'      => true,
            'count'   => count($entries),
            'matched' => $matched,
            'updated' => $now,
            'quelle'  => 'hicad-csv',
        ]);
    }

    /**
     * Parst HALBZEUGE.IPL (ISD Catalogue File, UTF-16LE) und liefert Einträge
     * mit Kategoriepfad + Tabellen-Referenz. Bezeichnung = Tabellenname (Basis),
     * zusätzlich Splits aus Dateinamen-Konventionen.
     */
    private function parseHicadIplCatalog(string $dir): array
    {
        $iplFile = $dir . '/HALBZEUGE.IPL';
        if (!file_exists($iplFile)) return [];

        $raw = file_get_contents($iplFile);
        if ($raw === false || $raw === '') return [];

        // BOM erkennen (ff fe = UTF-16LE, fe ff = UTF-16BE)
        $bom2 = substr($raw, 0, 2);
        if ($bom2 === "\xFF\xFE") {
            $text = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif ($bom2 === "\xFE\xFF") {
            $text = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        } else {
            // Fallback: wenn jedes zweite Byte 0x00 ist, wahrscheinlich UTF-16LE
            $nulls = substr_count(substr($raw, 0, min(200, strlen($raw))), "\x00");
            if ($nulls > 50) {
                $text = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            } else {
                $text = $raw;
            }
        }

        // Whitespace normalisieren: <CAT>/<TBL>/{ /} durch Leerzeichen separieren
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Tokenisierung via preg: Tokens = <CAT> | <TBL> | { | } | NAME | ,
        if (!preg_match_all('/<CAT>|<TBL>|\{|\}|,|[A-Za-z0-9_\-]+/u', $text, $m)) {
            return [];
        }
        $tokens = $m[0];

        $stack = [];   // aktueller Kategoriepfad
        $entries = [];
        $seen = [];
        $i = 0;
        $n = count($tokens);

        while ($i < $n) {
            $t = $tokens[$i];

            if ($t === '<CAT>') {
                // Erwartet: NAME {
                $name = $tokens[$i + 1] ?? '';
                $brace = $tokens[$i + 2] ?? '';
                if ($name !== '' && $brace === '{') {
                    $stack[] = $name;
                    $i += 3;
                    continue;
                }
                $i++;
                continue;
            }

            if ($t === '}') {
                array_pop($stack);
                $i++;
                continue;
            }

            if ($t === '<TBL>') {
                // Es folgen ein oder mehrere NAMEs, ggf. durch "," oder weitere <TBL> getrennt.
                // Liest genau einen Namen pro <TBL>.
                $name = $tokens[$i + 1] ?? '';
                if ($name !== '' && $name !== '{' && $name !== '}' && $name !== ',') {
                    $entry = $this->buildHicadEntryFromTable($stack, $name);
                    if ($entry !== null) {
                        $key = $entry['kategoriePfad'] . '|' . $entry['bezeichnung'];
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $entries[] = $entry;
                        }
                    }
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            $i++;
        }

        return $entries;
    }

    private function buildHicadEntryFromTable(array $stack, string $tableName): ?array
    {
        $tableName = trim($tableName);
        if ($tableName === '') return null;

        $path      = implode('/', $stack);
        $kategorie = end($stack) ?: 'HALBZEUGE';

        // Typ-Heuristik auf Basis des Kategoriepfads
        $joined = strtoupper($path . '/' . $tableName);
        $typ = 'profil';
        if (preg_match('/BLECH|FLACH|RUNDSTAHL|VIERKANTSTAHL|SECHSKANT|HALBZEUG|HOLZ|GITTERROST/', $joined)) {
            $typ = 'halbzeug';
        }

        // Bezeichnung = Tabellenname mit _ → Leerzeichen
        $bezeichnung = str_replace('_', ' ', $tableName);
        $kategorieAnzeige = str_replace('_', ' ', $kategorie);

        return [
            'kategoriePfad' => $path,
            'kategorie'     => $kategorieAnzeige,
            'tabelle'       => $tableName,
            'bezeichnung'   => $bezeichnung,
            'typ'           => $typ,
            'material'      => 'S235',
            'kgProM'        => null,
        ];
    }

    // ══════════════════════════════════════════════════════════
    // v2.0.1: "Oft verbaut" – Top-Materialien aus allen Baustellen
    // ══════════════════════════════════════════════════════════
    public function materialTopUsed(): void
    {
        Auth::requireAuth();
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 30)));
        $rows = $this->db->query("SELECT data FROM baustellen")->fetchAll();
        $agg = []; // key => ['bezeichnung','einheit','ek','count','sumAnzahl']
        foreach ($rows as $r) {
            $data = json_decode($r['data'] ?? '{}', true);
            if (!is_array($data) || !isset($data['material']) || !is_array($data['material'])) continue;
            foreach ($data['material'] as $m) {
                $bez = trim((string)($m['bezeichnung'] ?? ''));
                if ($bez === '') continue;
                $einheit = trim((string)($m['einheit'] ?? 'Stk'));
                $key = mb_strtolower($bez) . '|' . mb_strtolower($einheit);
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'bezeichnung' => $bez,
                        'einheit'     => $einheit,
                        'ek'          => (float)($m['ek'] ?? 0),
                        'count'       => 0,
                        'sumAnzahl'   => 0.0,
                    ];
                }
                $agg[$key]['count']++;
                $agg[$key]['sumAnzahl'] += (float)($m['anzahl'] ?? 0);
                if (($m['ek'] ?? 0) > 0) {
                    // Letzten bekannten EK übernehmen (jüngste Erfassung gewinnt nicht – einfach ein Wert)
                    $agg[$key]['ek'] = (float)$m['ek'];
                }
            }
        }
        usort($agg, fn($a, $b) => $b['count'] <=> $a['count']);
        jsonOut(['ok' => true, 'items' => array_values(array_slice($agg, 0, $limit))]);
    }
}
