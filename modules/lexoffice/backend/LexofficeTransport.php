<?php
// ============================================================
// Lexware Office (ehem. lexoffice) – Transportschicht
// ============================================================
// CurlTransport spricht nur mit dem festen Gateway (E-012/E-026).
// FakeTransport liefert aufgezeichnete Antworten aus einem
// Verzeichnis und wird ausschließlich über die Umgebungsvariable
// BK_LEXOFFICE_FAKE_DIR aktiviert (Tests, E-034/E-037) – so ruft
// kein automatischer Test je die echte API auf.
// ============================================================

namespace App\Modules\Lexoffice;

interface LexofficeTransport
{
    /**
     * @param list<string> $header
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function senden(string $methode, string $pfad, array $header, ?string $body): array;
}

final class CurlTransport implements LexofficeTransport
{
    public const HOST = 'https://api.lexware.io';

    public function __construct(private int $timeout = 20) {}

    public function senden(string $methode, string $pfad, array $header, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new LexofficeFehler('nicht_erreichbar', 0, 'PHP-Erweiterung curl fehlt');
        }
        $antwortHeader = [];
        $ch = curl_init(self::HOST . $pfad);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $zeile) use (&$antwortHeader): int {
                $teile = explode(':', $zeile, 2);
                if (count($teile) === 2) {
                    $antwortHeader[strtolower(trim($teile[0]))] = trim($teile[1]);
                }
                return strlen($zeile);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $antwort = curl_exec($ch);
        if ($antwort === false) {
            $fehler = curl_error($ch);
            curl_close($ch);
            throw new LexofficeFehler('nicht_erreichbar', 0, $fehler);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $antwortHeader, 'body' => (string) $antwort];
    }
}

final class FakeTransport implements LexofficeTransport
{
    /** @var list<array{methode:string, pfad:string, body:?string}> */
    public array $anfragen = [];

    public function __construct(private string $verzeichnis, #[\SensitiveParameter] private string $schluessel) {}

    public function senden(string $methode, string $pfad, array $header, ?string $body): array
    {
        $this->anfragen[] = ['methode' => $methode, 'pfad' => $pfad, 'body' => $body];
        $ordner = preg_replace('/[^A-Za-z0-9-]/', '', $this->schluessel) ?? '';
        $pfadTeil = preg_replace('/[^A-Za-z0-9]+/', '_', trim(explode('?', $pfad)[0], '/')) ?? '';
        $datei = rtrim($this->verzeichnis, '/\\') . '/' . $ordner . '/' . strtoupper($methode) . '_' . $pfadTeil . '.json';
        if ($ordner === '' || !is_file($datei)) {
            return ['status' => 404, 'headers' => [], 'body' => '{"message":"fake: keine Aufzeichnung"}'];
        }
        $daten = json_decode((string) file_get_contents($datei), true);
        if (!is_array($daten)) {
            return ['status' => 500, 'headers' => [], 'body' => '{"message":"fake: Aufzeichnung ungültig"}'];
        }
        $body = $daten['body'] ?? '';
        return [
            'status'  => (int) ($daten['status'] ?? 200),
            'headers' => array_change_key_case(array_map('strval', (array) ($daten['headers'] ?? [])), CASE_LOWER),
            'body'    => is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        ];
    }
}
