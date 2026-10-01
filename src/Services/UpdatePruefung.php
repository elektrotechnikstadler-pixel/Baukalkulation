<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Prüft die GitHub-Release-Liste des per `BK_UPDATE_REPO` gesetzten Repos auf eine neuere stabile Version.
 * Host fest im Code (kein SSRF), Abruf und Uhrzeit injizierbar, gedrosselt über eine Cache-Datei.
 */
final class UpdatePruefung
{
    public const API_BASIS = 'https://api.github.com/repos/';
    public const HTML_BASIS = 'https://github.com/';
    public const MELDUNG_NICHT_KONFIGURIERT = 'Update-Quelle nicht eingerichtet: BK_UPDATE_REPO=owner/name in .env setzen und Container neu starten.';
    public const MELDUNG_FEHLER = 'Update-Quelle nicht erreichbar oder Antwort ungültig. Bitte später erneut versuchen.';
    public const MELDUNG_KEINE_RELEASES = 'Im Repository ist noch keine stabile Version veröffentlicht.';
    public const NOTES_MAX = 2000;

    private const ERFOLG_GUELTIG_SEK = 6 * 3600;
    private const FEHLSCHLAG_PAUSE_SEK = 30 * 60;
    private const TIMEOUT_SEK = 5;

    private \Closure $abruf;
    private \DateTimeImmutable $jetzt;
    private string $aktuelleVersion;

    /** @param (\Closure(string): ?string)|null $abruf liefert den Antwort-Body oder null */
    public function __construct(
        private ?string $repo,
        private string $cacheDatei,
        ?\Closure $abruf = null,
        ?\DateTimeImmutable $jetzt = null,
        ?string $aktuelleVersion = null,
    ) {
        $this->jetzt = $jetzt ?? new \DateTimeImmutable();
        $this->aktuelleVersion = $aktuelleVersion ?? self::versionAusDatei();
        $this->abruf = $abruf ?? self::standardAbruf($this->aktuelleVersion);
    }

    public static function ausUmgebung(string $cacheDatei): self
    {
        $repo = getenv('BK_UPDATE_REPO');
        return new self($repo === false ? null : $repo, $cacheDatei);
    }

    public function repoGueltig(): bool
    {
        return $this->repo !== null
            && preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9_-][A-Za-z0-9._-]*$#D', $this->repo) === 1
            && !str_contains($this->repo, '..');
    }

    /** @return list<string> stabile Versionen ohne `v`, absteigend */
    public function verfuegbareVersionen(): array
    {
        $daten = $this->releases(false);
        return $daten === null ? [] : array_column($daten['releases'], 'version');
    }

    /**
     * @return array{status: string, aktuelle_version: string, neueste_version: ?string, veroeffentlicht: ?string,
     *               notes: ?string, link: ?string, geprueft: ?string, meldung: ?string}
     */
    public function pruefen(bool $force = false): array
    {
        return $this->ergebnis(false, $force);
    }

    /**
     * Letzter erfolgreich gecachter Stand ohne Abruf (unabhängig vom Alter); ohne Cache Status `ungeprueft`.
     *
     * @return array{status: string, aktuelle_version: string, neueste_version: ?string, veroeffentlicht: ?string,
     *               notes: ?string, link: ?string, geprueft: ?string, meldung: ?string}
     */
    public function gecachterStand(): array
    {
        return $this->ergebnis(true, false);
    }

    /**
     * @return array{status: string, aktuelle_version: string, neueste_version: ?string, veroeffentlicht: ?string,
     *               notes: ?string, link: ?string, geprueft: ?string, meldung: ?string}
     */
    private function ergebnis(bool $nurCache, bool $force): array
    {
        $ergebnis = [
            'status'           => 'nicht_konfiguriert',
            'aktuelle_version' => $this->aktuelleVersion,
            'neueste_version'  => null,
            'veroeffentlicht'  => null,
            'notes'            => null,
            'link'             => null,
            'geprueft'         => null,
            'meldung'          => self::MELDUNG_NICHT_KONFIGURIERT,
        ];
        if (!$this->repoGueltig()) {
            return $ergebnis;
        }

        if ($nurCache) {
            $daten = $this->cacheLesen()['erfolg'] ?? null;
            if ($daten === null) {
                $ergebnis['status'] = 'ungeprueft';
                $ergebnis['meldung'] = null;
                return $ergebnis;
            }
        } else {
            $daten = $this->releases($force);
        }
        if ($daten === null) {
            $ergebnis['status'] = 'fehler';
            $ergebnis['meldung'] = self::MELDUNG_FEHLER;
            return $ergebnis;
        }

        $ergebnis['geprueft'] = $daten['geprueft'];
        $neueste = $daten['releases'][0] ?? null;
        if ($neueste === null) {
            $ergebnis['status'] = 'keine_releases';
            $ergebnis['meldung'] = self::MELDUNG_KEINE_RELEASES;
            return $ergebnis;
        }

        $ergebnis['status'] = version_compare($neueste['version'], $this->aktuelleVersion, '>') ? 'update_verfuegbar' : 'aktuell';
        $ergebnis['neueste_version'] = $neueste['version'];
        $ergebnis['veroeffentlicht'] = $neueste['veroeffentlicht'];
        $ergebnis['notes'] = $neueste['notes'];
        $ergebnis['link'] = $neueste['link'];
        $ergebnis['meldung'] = null;
        return $ergebnis;
    }

    /**
     * Gecachte oder frisch abgerufene Release-Liste; null bei (gedrosseltem) Fehlschlag oder ohne Repo.
     *
     * @return array{geprueft: string, releases: list<array{version: string, veroeffentlicht: ?string, notes: ?string, link: ?string}>}|null
     */
    private function releases(bool $force): ?array
    {
        if (!$this->repoGueltig()) {
            return null;
        }
        $cache = $this->cacheLesen();
        if (!$force && $cache !== null) {
            if ($cache['erfolg'] !== null && $this->sekundenSeit($cache['erfolg']['geprueft']) < self::ERFOLG_GUELTIG_SEK) {
                return $cache['erfolg'];
            }
            if ($cache['fehlschlag'] !== null && $this->sekundenSeit($cache['fehlschlag']) < self::FEHLSCHLAG_PAUSE_SEK) {
                return null;
            }
        }

        $releases = null;
        try {
            $body = ($this->abruf)(self::API_BASIS . $this->repo . '/releases?per_page=30');
            $releases = is_string($body) && $body !== '' ? $this->parsen($body) : null;
            if ($releases === null) {
                error_log('[UpdatePruefung] Release-Liste für ' . $this->repo . ' leer oder ungültig.');
            }
        } catch (\Throwable $e) {
            error_log('[UpdatePruefung] Abruf fehlgeschlagen: ' . $e->getMessage());
        }

        $jetzt = $this->jetzt->format('c');
        if ($releases === null) {
            $this->cacheSchreiben(['repo' => $this->repo, 'erfolg' => $cache['erfolg'] ?? null, 'fehlschlag' => $jetzt]);
            return null;
        }
        $erfolg = ['geprueft' => $jetzt, 'releases' => $releases];
        $this->cacheSchreiben(['repo' => $this->repo, 'erfolg' => $erfolg, 'fehlschlag' => null]);
        return $erfolg;
    }

    /** @return list<array{version: string, veroeffentlicht: ?string, notes: ?string, link: ?string}>|null */
    private function parsen(string $body): ?array
    {
        $liste = json_decode($body, true);
        if (!is_array($liste) || !array_is_list($liste)) {
            return null;
        }
        $linkPraefix = self::HTML_BASIS . $this->repo . '/releases/';
        $releases = [];
        foreach ($liste as $r) {
            if (!is_array($r) || ($r['draft'] ?? true) !== false || ($r['prerelease'] ?? true) !== false) {
                continue;
            }
            $tag = $r['tag_name'] ?? null;
            if (!is_string($tag) || preg_match('/^v(\d+\.\d+\.\d+)$/D', $tag, $m) !== 1) {
                continue;
            }
            $datum = $r['published_at'] ?? null;
            $notes = $r['body'] ?? null;
            $link = $r['html_url'] ?? null;
            $releases[] = [
                'version'         => $m[1],
                'veroeffentlicht' => is_string($datum) && preg_match('/^\d{4}-\d{2}-\d{2}/', $datum) === 1 ? substr($datum, 0, 10) : null,
                'notes'           => is_string($notes) ? mb_substr($notes, 0, self::NOTES_MAX, 'UTF-8') : null,
                'link'            => is_string($link) && str_starts_with($link, $linkPraefix) ? $link : null,
            ];
        }
        usort($releases, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $releases;
    }

    /**
     * @return array{erfolg: array{geprueft: string, releases: list<array{version: string, veroeffentlicht: ?string, notes: ?string, link: ?string}>}|null, fehlschlag: ?string}|null
     */
    private function cacheLesen(): ?array
    {
        if (!is_file($this->cacheDatei)) {
            return null;
        }
        try {
            $roh = file_get_contents($this->cacheDatei);
        } catch (\Throwable $e) {
            error_log('[UpdatePruefung] Cache nicht lesbar: ' . $e->getMessage());
            return null;
        }
        $daten = is_string($roh) ? json_decode($roh, true) : null;
        if (!is_array($daten) || ($daten['repo'] ?? null) !== $this->repo) {
            return null;
        }
        $erfolg = $daten['erfolg'] ?? null;
        if (!is_array($erfolg) || !is_string($erfolg['geprueft'] ?? null) || !is_array($erfolg['releases'] ?? null)) {
            $erfolg = null;
        }
        $fehlschlag = $daten['fehlschlag'] ?? null;
        return ['erfolg' => $erfolg, 'fehlschlag' => is_string($fehlschlag) ? $fehlschlag : null];
    }

    /** @param array<string, mixed> $daten */
    private function cacheSchreiben(array $daten): void
    {
        $tmp = $this->cacheDatei . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            if (file_put_contents($tmp, (string) json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false
                || !rename($tmp, $this->cacheDatei)) {
                error_log('[UpdatePruefung] Cache nicht schreibbar.');
            }
        } catch (\Throwable $e) {
            error_log('[UpdatePruefung] Cache nicht schreibbar: ' . $e->getMessage());
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function sekundenSeit(string $zeit): int
    {
        $ts = strtotime($zeit);
        return $ts === false ? PHP_INT_MAX : $this->jetzt->getTimestamp() - $ts;
    }

    private static function versionAusDatei(): string
    {
        $datei = dirname(__DIR__, 2) . '/VERSION';
        $inhalt = is_file($datei) ? file_get_contents($datei) : false;
        return is_string($inhalt) && trim($inhalt) !== '' ? trim($inhalt) : '0.0.0';
    }

    private static function standardAbruf(string $version): \Closure
    {
        return static function (string $url) use ($version): ?string {
            $kopf = ['Accept: application/vnd.github+json', 'User-Agent: Baukalkulation/' . $version];
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => self::TIMEOUT_SEK,
                    CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEK,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                    CURLOPT_HTTPHEADER     => $kopf,
                ]);
                $body = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return is_string($body) && $code === 200 ? $body : null;
            }
            $ctx = stream_context_create([
                'http' => ['timeout' => self::TIMEOUT_SEK, 'header' => implode("\r\n", $kopf) . "\r\n", 'follow_location' => 0],
            ]);
            // Warnungen (z. B. HTTP 404) als Fehlschlag werten statt ausgeben; kein @ (E-063).
            set_error_handler(static fn (): bool => true);
            try {
                $body = file_get_contents($url, false, $ctx);
            } finally {
                restore_error_handler();
            }
            return is_string($body) ? $body : null;
        };
    }
}
