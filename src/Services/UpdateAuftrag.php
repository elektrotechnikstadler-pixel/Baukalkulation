<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Update-Anforderung an den Updater-Sidecar über das Austauschverzeichnis `DATA_DIR/update/`
 * (nur `anforderung/request.json` schreiben, `updater.json`/`status.json`/`maintenance` lesen).
 */
final class UpdateAuftrag
{
    public const WARTUNG_MELDUNG = 'Wartung – Update läuft';

    public const MELDUNGEN = [
        'nicht_konfiguriert' => UpdatePruefung::MELDUNG_NICHT_KONFIGURIERT,
        'updater_inaktiv'    => 'Updater nicht aktiv – bitte manuell aktualisieren.',
        'laeuft_bereits'     => 'Es läuft bereits ein Update.',
        'version_ungueltig'  => 'Zielversion ist keine veröffentlichte Release-Version.',
        'kein_update'        => 'Zielversion ist nicht neuer als die installierte Version.',
    ];

    private const HEARTBEAT_MAX_SEK = 120;
    private const HEARTBEAT_ZUKUNFT_SEK = 60;
    private const WARTUNG_MAX_SEK = 30 * 60;
    private const STATUS_FELDER = ['id', 'phase', 'version_alt', 'version_ziel', 'ergebnis', 'meldung', 'ts'];
    private const ZUSTAENDE = ['bereit', 'nicht_konfiguriert', 'nicht_unterstuetzt'];

    /** @param \Closure(): \DateTimeImmutable $jetzt */
    public function __construct(
        private string $verzeichnis,
        private UpdatePruefung $pruefung,
        private \Closure $jetzt,
    ) {}

    /**
     * Gemeinsame Wartungsregel aller API-Einstiegspunkte: Nicht-GET-Anfragen sind während eines Updates gesperrt.
     *
     * @param list<string> $ausnahmen trotzdem erlaubte Aktionen
     */
    public static function wartungSperrt(string $methode, string $aktion, array $ausnahmen, string $verzeichnis, int $jetzt): bool
    {
        return $methode !== 'GET'
            && !in_array($aktion, $ausnahmen, true)
            && self::wartungAktiv($verzeichnis, $jetzt);
    }

    /** Wartungsmarker vorhanden und jünger als 30 min; ältere Marker (hängender Sidecar) werden ignoriert. */
    public static function wartungAktiv(string $verzeichnis, int $jetzt): bool
    {
        $marker = rtrim($verzeichnis, '/\\') . '/maintenance';
        clearstatcache(true, $marker);
        $mtime = is_file($marker) ? filemtime($marker) : false;
        if ($mtime === false) {
            return false;
        }
        if ($jetzt - $mtime >= self::WARTUNG_MAX_SEK) {
            error_log('[UpdateAuftrag] Wartungsmarker älter als 30 min – ignoriert.');
            return false;
        }
        return true;
    }

    public function updaterAktiv(): bool
    {
        $ts = $this->heartbeat()['ts'] ?? null;
        if (!is_int($ts)) {
            return false;
        }
        $alter = ($this->jetzt)()->getTimestamp() - $ts;
        return $alter < self::HEARTBEAT_MAX_SEK && $alter >= -self::HEARTBEAT_ZUKUNFT_SEK;
    }

    /** Vom Sidecar gemeldeter Umgebungszustand (`bereit`, `nicht_konfiguriert`, `nicht_unterstuetzt`) oder null. */
    public function zustand(): ?string
    {
        $zustand = $this->heartbeat()['zustand'] ?? null;
        return in_array($zustand, self::ZUSTAENDE, true) ? $zustand : null;
    }

    /** @return array<string, mixed> mindestens `phase` */
    public function status(): array
    {
        $daten = $this->jsonLesen('status.json');
        if ($daten === null || !is_string($daten['phase'] ?? null)) {
            return ['phase' => 'wartet'];
        }
        return array_intersect_key($daten, array_flip(self::STATUS_FELDER));
    }

    /** @return array{ok: false, code: string}|array{ok: true, id: string} */
    public function anfordern(string $version, int $userId): array
    {
        if (!$this->pruefung->repoGueltig()) {
            return ['ok' => false, 'code' => 'nicht_konfiguriert'];
        }
        if (!$this->updaterAktiv()) {
            return ['ok' => false, 'code' => 'updater_inaktiv'];
        }
        $anforderung = $this->pfad('anforderung/request.json');
        if (file_exists($anforderung) || is_dir($this->pfad('lock'))) {
            return ['ok' => false, 'code' => 'laeuft_bereits'];
        }
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1
            || !in_array($version, $this->pruefung->verfuegbareVersionen(true), true)) {
            return ['ok' => false, 'code' => 'version_ungueltig'];
        }
        if (version_compare($version, $this->pruefung->aktuelleVersion(), '<=')) {
            return ['ok' => false, 'code' => 'kein_update'];
        }

        $id = bin2hex(random_bytes(8));
        $inhalt = (string) json_encode([
            'id'           => $id,
            'version'      => $version,
            'user_id'      => $userId,
            'requested_at' => ($this->jetzt)()->format('c'),
        ], JSON_UNESCAPED_SLASHES);
        $ordner = $this->pfad('anforderung');
        if (!is_dir($ordner)) {
            set_error_handler(static fn (): bool => true);
            try {
                $angelegt = mkdir($ordner, 0770, true);
            } finally {
                restore_error_handler();
            }
            if (!$angelegt && !is_dir($ordner)) {
                throw new \RuntimeException('Anforderungsverzeichnis nicht anlegbar.');
            }
        }
        $tmp = $ordner . '/.request.' . $id . '.tmp';
        try {
            if (file_put_contents($tmp, $inhalt) === false) {
                throw new \RuntimeException('Anforderung nicht schreibbar.');
            }
            // link() legt exklusiv an (rename würde eine parallel abgelegte Anforderung überschreiben); kein @ (E-063).
            // Ohne Hardlink-Unterstützung (z. B. CIFS) Rückfall auf fopen 'x', ebenfalls exklusiv.
            set_error_handler(static fn (): bool => true);
            try {
                $angelegt = link($tmp, $anforderung);
                if (!$angelegt && !file_exists($anforderung)) {
                    $datei = fopen($anforderung, 'x');
                    if ($datei !== false) {
                        $angelegt = fwrite($datei, $inhalt) === strlen($inhalt);
                        fclose($datei);
                        if (!$angelegt) {
                            unlink($anforderung);
                        }
                    }
                }
            } finally {
                restore_error_handler();
            }
            if (!$angelegt) {
                if (file_exists($anforderung)) {
                    return ['ok' => false, 'code' => 'laeuft_bereits'];
                }
                throw new \RuntimeException('Anforderung nicht schreibbar.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
        return ['ok' => true, 'id' => $id];
    }

    /** @return array<string, mixed>|null */
    private function heartbeat(): ?array
    {
        return $this->jsonLesen('updater.json');
    }

    /** @return array<string, mixed>|null */
    private function jsonLesen(string $datei): ?array
    {
        $pfad = $this->pfad($datei);
        if (!is_file($pfad)) {
            return null;
        }
        try {
            $roh = file_get_contents($pfad);
        } catch (\Throwable $e) {
            error_log('[UpdateAuftrag] ' . $datei . ' nicht lesbar: ' . $e->getMessage());
            return null;
        }
        $daten = is_string($roh) ? json_decode($roh, true) : null;
        return is_array($daten) && !array_is_list($daten) ? $daten : null;
    }

    private function pfad(string $datei): string
    {
        return rtrim($this->verzeichnis, '/\\') . '/' . $datei;
    }
}
