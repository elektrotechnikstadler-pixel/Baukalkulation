<?php
namespace App\Handlers;

use App\Auth;

/**
 * KI-gestützte Dokumentenerkennung (v2.9.21)
 * Analysiert Fotos / PDFs per Gemini API und extrahiert Materialpositionen.
 */
class AiActions
{
    /** Erlaubte MIME-Types für den Upload */
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    /** Maximale Base64-Länge ≈ 10 MB Rohdatei */
    private const MAX_B64_LEN = 14_000_000;

    public function __construct(private \PDO $db, private array $body) {}

    // ── scanMaterial ─────────────────────────────────────────
    public function scanMaterial(): void
    {
        Auth::requireAuth();

        $settings = Auth::loadSettings($this->db);
        $apiKey   = trim((string)($settings['gemini_api_key'] ?? ''));
        $model    = trim((string)($settings['gemini_model']   ?? 'gemini-2.5-flash-lite'));

        if ($apiKey === '') {
            \jsonOut(['error' => 'Kein Gemini API-Key konfiguriert. Bitte unter Einstellungen → KI hinterlegen.'], 400);
        }

        $mimeType = trim((string)($this->body['mimeType'] ?? ''));
        $data     = (string)($this->body['data'] ?? '');

        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            \jsonOut(['error' => 'Nicht unterstützter Dateityp. Erlaubt: JPG, PNG, WEBP, GIF, PDF.'], 400);
        }
        if ($data === '') {
            \jsonOut(['error' => 'Keine Dateidaten übermittelt.'], 400);
        }
        if (strlen($data) > self::MAX_B64_LEN) {
            \jsonOut(['error' => 'Datei zu groß (max. 10 MB).'], 400);
        }
        // Nur Base64-Zeichen erlaubt (OWASP Input Validation)
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            \jsonOut(['error' => 'Ungültige Dateidaten (kein gültiges Base64).'], 400);
        }

        $prompt = 'Analysiere dieses Dokument (Lieferschein, Rechnung, Angebot oder Foto) und extrahiere alle Materialpositionen.'
            . ' Antworte ausschließlich mit einem JSON-Objekt (kein Markdown, kein zusätzlicher Text) mit folgender Struktur:'
            . ' { "preisbasis": "netto" | "brutto" | "unbekannt", "mwst_satz": Zahl (z.B. 19, 7 oder 0),'
            . ' "absender": { Objekt }, "positionen": [ Objekte ] }.'
            . ' "preisbasis" gibt an, ob die EINZEL- und Gesamtpreise der Positionen die Mehrwertsteuer bereits ENTHALTEN ("brutto"),'
            . ' NICHT enthalten ("netto") oder ob das nicht eindeutig erkennbar ist ("unbekannt").'
            . ' Achte genau auf Hinweise wie "inkl. MwSt", "Bruttopreis", "Nettopreis", "zzgl. USt", Spaltenüberschriften'
            . ' und ob am Ende des Dokuments noch MwSt auf die Positionssumme aufgeschlagen wird (dann sind die Positionen netto).'
            . ' "mwst_satz" ist der im Dokument verwendete Mehrwertsteuersatz in Prozent (0 wenn keiner erkennbar).'
            . ' Jedes Objekt in "positionen" hat diese Felder:'
            . ' "bezeichnung" (Name/Beschreibung, max. 200 Zeichen),'
            . ' "artikelnummer" (Artikel-/Bestellnummer des Lieferanten, leerer String wenn keine vorhanden, max. 60 Zeichen),'
            . ' "anzahl" (Menge als Dezimalzahl),'
            . ' "einheit" (z.B. "Stk.", "m", "m²", "kg", "l", "Paar", max. 20 Zeichen),'
            . ' "einzelpreis" (Einzelpreis pro Einheit GENAU SO wie im Dokument gedruckt, als Dezimalzahl, 0 wenn nicht erkennbar),'
            . ' "gesamtpreis" (Positions-Gesamtpreis = Menge × Einzelpreis GENAU SO wie im Dokument gedruckt, als Dezimalzahl, 0 wenn nicht erkennbar).'
            . ' Rechne KEINE Preise selbst um, übernimm die gedruckten Werte unverändert.'
            . ' Wenn keine Materialpositionen erkannt werden, gib ein leeres "positionen"-Array zurück.'
            . ' "absender" ist die Absender-/Briefkopf-Adresse des ausstellenden Lieferanten (meist im Briefkopf oben oder im Fußbereich),'
            . ' NICHT der Empfänger/Kunde des Dokuments, mit diesen Feldern (leerer String wenn nicht erkennbar):'
            . ' "firma" (Firmenname des Absenders),'
            . ' "ansprechpartner" (Name einer Kontaktperson, falls vorhanden),'
            . ' "strasse" (Straße und Hausnummer),'
            . ' "plz" (Postleitzahl),'
            . ' "ort" (Ort/Stadt),'
            . ' "telefon" (Telefonnummer),'
            . ' "email" (E-Mail-Adresse).';

        $payload = [
            'contents' => [[
                'parts' => [
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => $data]],
                    ['text'       => $prompt],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
                // Höheres Limit: umfangreiche Belege (viele Positionen) bzw. Thinking-Modelle
                // sprengten das alte Limit von 4096 → JSON wurde abgeschnitten → nichts erkannt.
                'maxOutputTokens'    => 32768,
            ],
        ];

        $url         = 'https://generativelanguage.googleapis.com/v1beta/models/'
                       . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $responseBody = $this->callGemini($url, $jsonPayload);
        if ($responseBody === false) {
            \jsonOut(['error' => 'Gemini API nicht erreichbar. Bitte Internetverbindung prüfen.'], 502);
        }

        $resp = json_decode($responseBody, true);
        if (!is_array($resp)) {
            \jsonOut(['error' => 'Ungültige Antwort der KI-API.'], 502);
        }

        // Fehler von Gemini selbst weiterleiten
        if (isset($resp['error'])) {
            $msg = strip_tags((string)($resp['error']['message'] ?? 'Unbekannter API-Fehler'));
            \jsonOut(['error' => 'Gemini-Fehler: ' . $msg], 502);
        }

        // Gemini 2.5+ Thinking-Modelle: ersten Non-Thought-Part verwenden
        $text = '';
        foreach ($resp['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && !($part['thought'] ?? false)) { $text = $part['text']; break; }
        }
        $finishReason = (string)($resp['candidates'][0]['finishReason'] ?? '');
        [$items, $preisbasis, $mwstSatz, $absender] = $this->parseItems($text);

        // Antwort abgeschnitten (Beleg zu umfangreich fürs Token-Limit) und nichts rettbar:
        // klaren Hinweis statt stiller Leermeldung geben.
        if (empty($items) && $finishReason === 'MAX_TOKENS') {
            \jsonOut([
                'ok'         => true,
                'items'      => [],
                'preisbasis' => $preisbasis,
                'mwstSatz'   => $mwstSatz,
                'absender'   => $absender,
                'hint'       => 'Der Beleg ist sehr umfangreich – die KI-Antwort wurde abgeschnitten. '
                              . 'Bitte ein leistungsfähigeres Modell (z. B. gemini-3.5-flash) wählen oder den Beleg in mehreren Teilen scannen.',
            ]);
        }

        \jsonOut([
            'ok'         => true,
            'items'      => $items,
            'preisbasis' => $preisbasis,   // 'netto' | 'brutto' | 'unbekannt'
            'mwstSatz'   => $mwstSatz,     // Prozent (Zahl)
            'absender'   => $absender,     // Briefkopf-/Lieferantenadresse (assoziatives Array)
            'hint'       => empty($items) ? 'Keine Materialpositionen erkannt.' : null,
        ]);
    }

    // ── HTTP-Call an Gemini ───────────────────────────────────
    private function callGemini(string $url, string $payload): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return ($body !== false) ? (string)$body : false;
        }

        // Fallback: file_get_contents
        $ctx = stream_context_create(['http' => [
            'method'         => 'POST',
            'header'         => "Content-Type: application/json\r\n",
            'content'        => $payload,
            'timeout'        => 45,
            'ignore_errors'  => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        return ($r !== false) ? (string)$r : false;
    }

    // ── Antwort-Text zu sauberem Array parsen ─────────────────
    /**
     * @return array{0: array<int,array<string,mixed>>, 1: string, 2: float, 3: array<string,string>}
     *         [positionen, preisbasis, mwstSatz, absender]
     */
    private function parseItems(string $text): array
    {
        // Markdown-Fences entfernen, falls KI sie trotzdem liefert
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode(trim($text), true);

        // Neue Struktur: { preisbasis, mwst_satz, absender, positionen: [...] }
        // Alte Struktur (Abwärtskompatibilität): [ {...}, {...} ]
        $preisbasis = 'unbekannt';
        $mwstSatz   = 0.0;
        $absender   = $this->cleanAbsender(null);
        $raw        = [];
        if (is_array($decoded)) {
            if (isset($decoded['positionen']) && is_array($decoded['positionen'])) {
                $pb = strtolower(trim((string)($decoded['preisbasis'] ?? 'unbekannt')));
                $preisbasis = in_array($pb, ['netto', 'brutto'], true) ? $pb : 'unbekannt';
                $mwstSatz   = max(0.0, min(100.0, (float)($decoded['mwst_satz'] ?? 0)));
                $absender   = $this->cleanAbsender($decoded['absender'] ?? null);
                $raw        = $decoded['positionen'];
            } else {
                $raw = $decoded;
            }
        } else {
            // Abgeschnittenes/ungültiges JSON: vollständige Positions-Objekte einzeln retten,
            // damit lange Belege nicht komplett verloren gehen. Positions-Objekte sind flach;
            // die abgeschnittene letzte (ohne schließende Klammer) wird dabei verworfen.
            if (preg_match_all('/\{[^{}]*\}/', $text, $m)) {
                foreach ($m[0] as $obj) {
                    $d = json_decode($obj, true);
                    if (is_array($d)) $raw[] = $d;
                }
            }
        }
        if (!is_array($raw) || empty($raw)) return [[], $preisbasis, $mwstSatz, $absender];

        $clean = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $bez = trim(strip_tags((string)($item['bezeichnung'] ?? '')));
            if ($bez === '') continue;
            // Einzelpreis: neues Feld "einzelpreis", Fallback altes "ek"
            $einzel = (float)($item['einzelpreis'] ?? $item['ek'] ?? 0);
            $clean[] = [
                'bezeichnung'   => mb_substr($bez, 0, 200),
                'artikelnummer' => mb_substr(trim(strip_tags((string)($item['artikelnummer'] ?? ''))), 0, 60),
                'anzahl'        => max(0.0, round((float)($item['anzahl'] ?? 1), 4)),
                'einheit'       => mb_substr(trim(strip_tags((string)($item['einheit'] ?? 'Stk.'))), 0, 20),
                // 'ek' bleibt der gedruckte Einzelpreis (Netto-/Brutto-Umrechnung erfolgt im Frontend
                // anhand von preisbasis/mwstSatz, damit der Nutzer die Entscheidung bestätigen kann)
                'ek'            => max(0.0, round($einzel, 2)),
                'gesamtpreis'   => max(0.0, round((float)($item['gesamtpreis'] ?? 0), 2)),
            ];
        }
        return [$clean, $preisbasis, $mwstSatz, $absender];
    }

    /**
     * Absender-/Briefkopf-Adresse säubern (Strings, HTML entfernt, Länge begrenzt).
     * @return array<string,string>
     */
    private function cleanAbsender(mixed $a): array
    {
        $fields = ['firma', 'ansprechpartner', 'strasse', 'plz', 'ort', 'telefon', 'email'];
        $out = [];
        $src = is_array($a) ? $a : [];
        foreach ($fields as $f) {
            $out[$f] = mb_substr(trim(strip_tags((string)($src[$f] ?? ''))), 0, 120);
        }
        return $out;
    }
}
