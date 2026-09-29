<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Kupfer-DEL-Notierung (obere Kupfer WM-Notiz, €/100 kg) von Westmetall lesen,
 * gedrosselt abrufen und Aktualität bewerten. Abruf und Uhrzeit sind injizierbar (Tests ohne Netz).
 */
final class Kupferpreis
{
    public const URL = 'https://www.westmetall.com/de/markdaten.php?action=table&field=WM_Cu_high';
    public const QUELLE = 'auto (westmetall)';
    public const PREIS_MIN = 100.0;
    public const PREIS_MAX = 5000.0;
    public const BASIS_STANDARD = 150;

    private const ERFOLG_GUELTIG_SEK = 6 * 3600;
    private const FEHLSCHLAG_PAUSE_SEK = 30 * 60;
    private const VERALTET_NACH_TAGEN = 5;

    private const MONATE = [
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6,
        'juli' => 7, 'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
    ];

    private \DateTimeImmutable $jetzt;

    /** @param \Closure(string): ?string $abruf liefert den Seiteninhalt oder null */
    public function __construct(private \Closure $abruf, ?\DateTimeImmutable $jetzt = null)
    {
        $this->jetzt = $jetzt ?? new \DateTimeImmutable();
    }

    /**
     * Oberste Tabellenzeile mit gültigem Datum und Zahl; unabhängig von CSS-Klassen.
     *
     * @return array{preis: float, stand: string}|null
     */
    public static function parseWestmetall(string $html): ?array
    {
        if (preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $zeilen) === false) {
            return null;
        }
        foreach ($zeilen[1] as $zeile) {
            if (!preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $zeile, $zellen) || count($zellen[1]) < 2) {
                continue;
            }
            $stand = self::parseDatum(self::zellText($zellen[1][0]));
            $preis = self::parseZahl(self::zellText($zellen[1][1]));
            if ($stand === null || $preis === null) {
                continue;
            }
            if ($preis < self::PREIS_MIN || $preis > self::PREIS_MAX) {
                return null;
            }
            return ['preis' => $preis, 'stand' => $stand];
        }
        return null;
    }

    /** @param array<string,mixed> $gespeichert */
    public function sollAbrufen(array $gespeichert, bool $aktiv = true, bool $force = false): bool
    {
        if (!$aktiv) {
            return false;
        }
        $hatWert = (float) ($gespeichert['delNotierung'] ?? 0) > 0;
        if ($hatWert && ($gespeichert['quelle'] ?? '') === 'manual') {
            return $force;
        }
        if ($force) {
            return true;
        }
        $versuch = $this->zeitstempel($gespeichert['letzterVersuch'] ?? null);
        if ($versuch !== null && $this->jetzt->getTimestamp() - $versuch < self::FEHLSCHLAG_PAUSE_SEK) {
            return false;
        }
        if (!$hatWert) {
            return true;
        }
        $erfolg = $this->zeitstempel($gespeichert['updated'] ?? null);
        return $erfolg === null || $this->jetzt->getTimestamp() - $erfolg >= self::ERFOLG_GUELTIG_SEK;
    }

    /**
     * Ruft bei Bedarf ab; bei Fehlschlag bleibt der letzte Wert mit `veraltet` und `fetchError`.
     *
     * @param array<string,mixed> $gespeichert
     * @return array<string,mixed>
     */
    public function aktualisieren(array $gespeichert, bool $force = false): array
    {
        if (!$this->sollAbrufen($gespeichert, true, $force)) {
            return $gespeichert + ['delNotierung' => 0, 'veraltet' => $this->istVeraltet($gespeichert)];
        }

        $jetzt = $this->jetzt->format('c');
        $fehler = null;
        $ergebnis = null;
        try {
            $html = ($this->abruf)(self::URL);
            if ($html === null || $html === '') {
                $fehler = 'Westmetall nicht erreichbar.';
            } else {
                $ergebnis = self::parseWestmetall($html);
                if ($ergebnis === null) {
                    $fehler = 'Westmetall-Seite nicht lesbar (Aufbau geändert?).';
                }
            }
        } catch (\Throwable $e) {
            error_log('[Kupferpreis] Westmetall-Abruf fehlgeschlagen: ' . $e->getMessage());
            $fehler = 'Westmetall-Abruf fehlgeschlagen.';
        }

        if ($ergebnis === null) {
            $daten = $gespeichert;
            $daten['delNotierung'] = (float) ($gespeichert['delNotierung'] ?? 0);
            $daten['letzterVersuch'] = $jetzt;
            $daten['fetchError'] = $fehler ?? 'Abruf fehlgeschlagen.';
            $daten['veraltet'] = $daten['delNotierung'] > 0;
            return $daten;
        }

        $daten = [
            'delNotierung'   => $ergebnis['preis'],
            'basisNotierung' => $gespeichert['basisNotierung'] ?? self::BASIS_STANDARD,
            'stand'          => $ergebnis['stand'],
            'datum'          => $ergebnis['stand'],
            'quelle'         => self::QUELLE,
            'updated'        => $jetzt,
            'letzterVersuch' => $jetzt,
        ];
        $daten['veraltet'] = $this->istVeraltet($daten);
        return $daten;
    }

    /**
     * Veraltet: letzter Abruf fehlgeschlagen oder Notiz älter als 5 Kalendertage bzw. Datum unlesbar.
     *
     * @param array<string,mixed> $daten
     */
    public function istVeraltet(array $daten): bool
    {
        if ((float) ($daten['delNotierung'] ?? 0) <= 0) {
            return false;
        }
        if (!empty($daten['fetchError'])) {
            return true;
        }
        $stand = $daten['stand'] ?? $daten['datum'] ?? '';
        if (!is_string($stand)) {
            return true;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', $stand, $this->jetzt->getTimezone());
        if ($datum === false || $datum->format('Y-m-d') !== $stand) {
            return true;
        }
        $tage = (int) $datum->diff($this->jetzt->setTime(0, 0))->format('%r%a');
        return $tage > self::VERALTET_NACH_TAGEN;
    }

    private function zeitstempel(mixed $wert): ?int
    {
        if (!is_string($wert) || $wert === '') {
            return null;
        }
        $ts = strtotime($wert);
        return $ts === false ? null : $ts;
    }

    private static function zellText(string $zelle): string
    {
        $text = html_entity_decode(strip_tags($zelle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    private static function parseDatum(string $text): ?string
    {
        if (!preg_match('/^(\d{1,2})\.\s*(\p{L}+)\s+(\d{4})$/u', $text, $m)) {
            return null;
        }
        $monat = self::MONATE[mb_strtolower($m[2], 'UTF-8')] ?? null;
        $tag = (int) $m[1];
        $jahr = (int) $m[3];
        if ($monat === null || !checkdate($monat, $tag, $jahr)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
    }

    /** Deutsches Zahlformat „1.307,62“ oder „987,50“. */
    private static function parseZahl(string $text): ?float
    {
        if (!preg_match('/^(-?)(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?$/', $text, $m)) {
            return null;
        }
        $zahl = (float) (str_replace('.', '', $m[2]) . '.' . ($m[3] ?? '0'));
        return round($m[1] === '-' ? -$zahl : $zahl, 2);
    }
}
