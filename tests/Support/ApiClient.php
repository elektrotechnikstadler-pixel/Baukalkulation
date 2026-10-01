<?php

declare(strict_types=1);

namespace Tests\Support;

/** Minimaler HTTP-Client für api.php mit eigener Session (Cookie) pro Instanz. */
final class ApiClient
{
    /** @var array<string,string> */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl) {}

    public function get(string $action, array $query = []): ApiResponse
    {
        return $this->request('GET', $action, $query);
    }

    /** @param list<string> $headers zusätzliche Kopfzeilen, z. B. `Origin: …` */
    public function post(string $action, array $body = [], array $query = [], array $headers = []): ApiResponse
    {
        return $this->request('POST', $action, $query, [
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        ], ['Content-Type: application/json', ...$headers]);
    }

    /** JSON-Body unverändert senden (z. B. `1e999`, das json_encode nicht erzeugen kann). */
    public function postRaw(string $action, string $json, array $query = []): ApiResponse
    {
        return $this->request('POST', $action, $query, [
            CURLOPT_POSTFIELDS => $json,
        ], ['Content-Type: application/json']);
    }

    public function upload(string $action, string $field, string $path, array $query = [], array $fields = []): ApiResponse
    {
        return $this->request('POST', $action, $query, [
            CURLOPT_POSTFIELDS => [$field => new \CURLFile($path, 'application/zip', basename($path))] + $fields,
        ]);
    }

    /** Anderen Endpunkt (z. B. `din1090_api.php`) mit derselben Session aufrufen; ohne `action`-Parameter. */
    public function skript(string $method, string $script, array $query = [], ?array $body = null): ApiResponse
    {
        $opts = $body === null ? [] : [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)];
        $headers = $body === null ? [] : ['Content-Type: application/json'];
        return $this->request($method, $script, $query, $opts, $headers, $script);
    }

    private function request(string $method, string $action, array $query, array $opts = [], array $headers = [], ?string $script = null): ApiResponse
    {
        $url = $script === null
            ? $this->baseUrl . '/api.php?' . http_build_query(['action' => $action] + $query)
            : $this->baseUrl . '/' . $script . ($query ? '?' . http_build_query($query) : '');
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name  = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $respHeaders[$name][] = $value;
                    if ($name === 'set-cookie') {
                        $this->storeCookie($value);
                    }
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new \RuntimeException("HTTP-Fehler bei {$action}: " . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new ApiResponse($action, $status, (string) $body, $respHeaders);
    }

    private function storeCookie(string $header): void
    {
        [$pair] = explode(';', $header, 2);
        [$name, $value] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
        if ($value === '' || $value === 'deleted') {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}
