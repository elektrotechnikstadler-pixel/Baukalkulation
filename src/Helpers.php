<?php
// ============================================================
// Globale Hilfsfunktionen
// ============================================================

/**
 * JSON-Antwort senden und Script beenden.
 *
 * v2.9.19: Transparente GZIP-Komprimierung für größere JSON-Responses
 * (typisch: action=load mit 1-10 MB Datenbestand). Greift nur, wenn der
 * Client `Accept-Encoding: gzip` sendet, zlib verfügbar ist, Header noch
 * nicht abgeschickt wurden und der Payload >= 1 KB ist. Kleine Antworten
 * werden unkomprimiert gesendet, weil der Overhead sich nicht lohnt.
 * Binary-Downloads (PDF/XLSX/Logo) gehen NICHT durch jsonOut() und sind
 * damit unberührt – ihre Content-Length-Header bleiben korrekt.
 */
function jsonOut(mixed $data, int $code = 200): never {
    http_response_code($code);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode(['error' => 'JSON-Encoding fehlgeschlagen.'], JSON_UNESCAPED_UNICODE);
    }
    if (strlen($json) >= 1024
        && function_exists('gzencode')
        && !headers_sent()
        && stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
        $compressed = @gzencode($json, 6);
        if ($compressed !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($compressed));
            echo $compressed;
            exit;
        }
    }
    echo $json;
    exit;
}

/**
 * Geldbetrag auf 2 Nachkommastellen normieren (IEEE-754-Rauschen entfernen).
 *
 * Schema ist aktuell REAL (Float). Float-Arithmetik (SQL-SUM, Aufschlag-
 * Berechnungen) erzeugt Werte wie 14.989999999999999. Diese Funktion wird
 * IMMER beim Lesen von Geldbetraegen aus der DB und beim Schreiben in die
 * API-Response angewendet, damit Summen und Anzeige stabil sind.
 *
 * Eine echte INTEGER-Cents-Migration (P0 Plan B) erfolgt separat; bis dahin
 * eliminiert money_round() den Grossteil der praktisch beobachtbaren
 * Bilanzdrift / 0.01-EUR-Differenzen.
 */
function money_round(mixed $value): float {
    if ($value === null || $value === '') return 0.0;
    return round((float)$value, 2);
}

/**
 * Euro-Dezimalwert in Cents (INTEGER) konvertieren – fuer Schreibzugriffe.
 *
 * Beispiel: 14.99 -> 1499, 25.0 -> 2500 (auch fuer Prozent-Aufschlag verwendet:
 * 25% -> 2500 Basispunkte). round() vor (int)-Cast verhindert Float-Truncation
 * wie 14.99 * 100 = 1498.9999... -> (int)1498.
 */
function money_to_cents(mixed $value): int {
    if ($value === null || $value === '') return 0;
    return (int) round((float)$value * 100);
}

/**
 * Cents (INTEGER) in Euro-Dezimalwert konvertieren – fuer Lesezugriffe.
 *
 * Gibt immer einen float mit max. 2 Nachkommastellen zurueck. Eingabe-NULL
 * oder Leer wird zu 0.0.
 */
function money_from_cents(mixed $cents): float {
    if ($cents === null || $cents === '') return 0.0;
    return ((int)$cents) / 100.0;
}

/**
 * Prüft, ob ein optionales Modul/Feature im aktuellen Build aktiv ist.
 *
 * Quelle: ENV-Variablen (im Dockerfile/Compose gesetzt) + tatsächlich
 * vorhandene Dateien/Binaries. Unbekannte Features → true (fail-open für
 * Aufrufe, die das Feature gar nicht brauchen).
 *
 *  ocr       – Tesseract + poppler installiert?
 *  pdf       – dompdf verfügbar? (für PDF-Erzeugung/ZUGFeRD)
 *  pdfparser – smalot/pdfparser verfügbar?
 *  zugferd   – horstoeko/zugferd verfügbar? (E-Rechnung, Kernfeature)
 *  din1090   – DIN EN 1090 Modul-Dateien vorhanden?
 *  whatsapp  – WhatsApp-Bridge in docker-compose aktiviert?
 *  hicad_lib – Roh-Bibliothek "Materialbibliothek HiCAD" im Image?
 *              (wenn nein: CSV-Übersicht wird genutzt)
 */
function feature_enabled(string $name): bool {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];

    $base = dirname(__DIR__);

    $val = match ($name) {
        'ocr'       => (strtolower((string)getenv('APP_ENABLE_OCR')) !== 'false')
                       && (bool)@shell_exec('command -v tesseract 2>/dev/null'),
        'pdf'       => class_exists(\Dompdf\Dompdf::class),
        'pdfparser' => class_exists(\Smalot\PdfParser\Parser::class),
        'zugferd'   => class_exists(\horstoeko\zugferd\ZugferdDocumentBuilder::class),
        'din1090'   => is_file($base . '/modules/din1090/Din1090Actions.php'),
        'whatsapp'  => strtolower((string)getenv('APP_ENABLE_WHATSAPP')) === 'true',
        'hicad_lib' => is_dir($base . '/Materialbibliothek HiCAD'),
        default     => true,
    };
    return $cache[$name] = $val;
}

/**
 * Guard für Route-Handler: bricht mit HTTP 501 ab, wenn ein erforderliches
 * Feature im aktuellen Build nicht verfügbar ist.
 */
function require_feature(string $name, string $label = ''): void {
    if (feature_enabled($name)) return;
    $label = $label !== '' ? $label : $name;
    jsonOut([
        'error'   => "Modul '{$label}' ist in dieser Installation nicht aktiviert.",
        'feature' => $name,
        'hint'    => 'Administrator: Im Build ENABLE_' . strtoupper($name) . '=true setzen und Container neu bauen.',
    ], 501);
}

/**
 * URL abrufen (cURL bevorzugt, file_get_contents Fallback).
 *
 * Standard: TLS-Zertifikate werden geprüft. Mit $insecure=true kann das
 * für Sonderfälle (z. B. interne Test-Endpoints) deaktiviert werden.
 */
function fetchUrl(string $url, int $timeout = 10, array $headers = [], bool $insecure = false): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            // H3 (v1.8.0): TLS-Verify standardmäßig aktiviert.
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: text/html,application/json,*/*',
                'Accept-Language: de-DE,de;q=0.9',
            ], $headers),
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result !== false && $httpCode >= 200 && $httpCode < 400) return $result;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header'  => "User-Agent: Mozilla/5.0\r\nAccept: text/html,application/json,*/*\r\nAccept-Language: de-DE,de;q=0.9\r\n",
            ],
            // H3 (v1.8.0): TLS-Verify standardmäßig aktiviert.
            'ssl' => ['verify_peer' => !$insecure, 'verify_peer_name' => !$insecure],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result !== false) return $result;
    }
    return null;
}

/**
 * iCal-Text escapen.
 */
function icalEscape(string $text): string {
    return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $text);
}

/**
 * RFC 5545 §3.1: iCal-Zeile falten (max. 75 Bytes; UTF-8-sicher).
 * Gibt die fertige Zeile inkl. abschließendem CRLF zurück.
 */
function icalFold(string $line): string {
    $out  = '';
    // Byte-weise aufteilen (nicht Zeichen-weise), damit Multi-Byte-Sequenzen
    // nicht mittendrin getrennt werden.
    while (strlen($line) > 75) {
        // Sicherstellen, dass wir nicht innerhalb einer UTF-8-Sequenz trennen:
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;   // Zurück bis zum Anfang der Multi-Byte-Sequenz
        }
        $out  .= substr($line, 0, $cut) . "\r\n ";
        $line  = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}
