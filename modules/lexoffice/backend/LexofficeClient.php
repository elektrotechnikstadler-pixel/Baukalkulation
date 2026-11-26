<?php
// ============================================================
// Lexware Office (ehem. lexoffice) – API-Client
// ============================================================
// Drosselung auf < 2 Anfragen/s (Limit der API), einmaliger
// Wiederholversuch bei 429 mit kurzem Retry-After, feste
// Fehlermeldungen für die Oberfläche (E-014) – Details nur ins Log.
// ============================================================

namespace App\Modules\Lexoffice;

final class LexofficeFehler extends \RuntimeException
{
    private const MELDUNGEN = [
        'schluessel_fehlt'     => 'Kein API-Schlüssel für Lexware Office hinterlegt.',
        'schluessel_ungueltig' => 'Der API-Schlüssel wurde von Lexware Office abgelehnt.',
        'tarif'                => 'Der Lexware-Office-Tarif erlaubt keinen Zugriff über die Schnittstelle.',
        'rate_limit'           => 'Lexware Office meldet zu viele Anfragen – bitte später erneut versuchen.',
        'nicht_erreichbar'     => 'Lexware Office ist nicht erreichbar.',
        'fehler'               => 'Lexware Office hat die Anfrage abgelehnt.',
    ];

    public function __construct(public readonly string $fehlerCode, public readonly int $httpStatus = 0, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $fehlerCode);
    }

    /** Für die Oberfläche: nie Antworttexte oder Ausnahmedetails ausliefern. */
    public function meldung(): string
    {
        return self::MELDUNGEN[$this->fehlerCode] ?? self::MELDUNGEN['fehler'];
    }
}

final class LexofficeClient
{
    private static float $letzterAufruf = 0.0;

    public function __construct(
        private LexofficeTransport $transport,
        #[\SensitiveParameter] private string $schluessel,
        private float $mindestAbstandSek = 0.55,
        private int $maxRetryAfterSek = 3,
    ) {}

    public static function ausEinstellungen(array $settings): self
    {
        $schluessel = (string) ($settings['lexoffice_api_key'] ?? '');
        if ($schluessel === '') {
            throw new LexofficeFehler('schluessel_fehlt');
        }
        $fake = getenv('BK_LEXOFFICE_FAKE_DIR');
        if (is_string($fake) && $fake !== '') {
            return new self(new FakeTransport($fake, $schluessel), $schluessel, 0.0);
        }
        return new self(new CurlTransport(), $schluessel);
    }

    /** @return array<string,mixed> */
    public function get(string $pfad): array
    {
        return $this->anfrage('GET', $pfad, null);
    }

    /**
     * @param array<string,mixed> $daten
     * @return array<string,mixed>
     */
    public function post(string $pfad, array $daten): array
    {
        return $this->anfrage('POST', $pfad, $daten);
    }

    public static function codeFuerStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'schluessel_ungueltig',
            $status === 402                  => 'tarif',
            $status === 429                  => 'rate_limit',
            $status === 0, $status >= 500    => 'nicht_erreichbar',
            default                          => 'fehler',
        };
    }

    /**
     * @param array<string,mixed>|null $daten
     * @return array<string,mixed>
     */
    private function anfrage(string $methode, string $pfad, ?array $daten): array
    {
        $header = [
            'Authorization: Bearer ' . $this->schluessel,
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $body = $daten === null ? null : json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        for ($versuch = 0; ; $versuch++) {
            $this->drosseln();
            $antwort = $this->transport->senden($methode, $pfad, $header, $body);
            $status = $antwort['status'];
            if ($status === 429 && $versuch === 0) {
                $warten = (int) ($antwort['headers']['retry-after'] ?? 1);
                if ($warten <= $this->maxRetryAfterSek) {
                    if ($warten > 0) {
                        sleep($warten);
                    }
                    continue;
                }
            }
            if ($status >= 200 && $status < 300) {
                $json = json_decode($antwort['body'], true);
                return is_array($json) ? $json : [];
            }
            throw new LexofficeFehler(self::codeFuerStatus($status), $status, "{$methode} {$pfad} → HTTP {$status}: " . substr($antwort['body'], 0, 300));
        }
    }

    private function drosseln(): void
    {
        if ($this->mindestAbstandSek <= 0) {
            return;
        }
        $warten = self::$letzterAufruf + $this->mindestAbstandSek - microtime(true);
        if ($warten > 0) {
            usleep((int) ($warten * 1_000_000));
        }
        self::$letzterAufruf = microtime(true);
    }
}
