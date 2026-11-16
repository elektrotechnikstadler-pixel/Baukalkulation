<?php
declare(strict_types=1);

namespace App\Services;

final class Sollzeit
{
    private const WEEKDAY_FIELDS = [
        1 => 'sollstundenMo',
        2 => 'sollstundenDi',
        3 => 'sollstundenMi',
        4 => 'sollstundenDo',
        5 => 'sollstundenFr',
        6 => 'sollstundenSa',
        7 => 'sollstundenSo',
    ];

    public static function tagesSoll(array $mitarbeiter, string $isoDatum): float
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $isoDatum)) return 0.0;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDatum);
        if ($date === false || $date->format('Y-m-d') !== $isoDatum) return 0.0;

        $weekday = (int)$date->format('N');
        if (!empty($mitarbeiter['sollzeitJeWochentag'])) {
            $field = self::WEEKDAY_FIELDS[$weekday];
            return self::number($mitarbeiter[$field] ?? 0, 0.0);
        }

        $sollTag = self::number($mitarbeiter['sollstundenTag'] ?? $mitarbeiter['sollTag'] ?? null, 8.0);
        $arbeitstage = self::arbeitstage($mitarbeiter['arbeitstage'] ?? '');
        if (!$arbeitstage) {
            $tageProWoche = self::number($mitarbeiter['sollTageWoche'] ?? null, 5.0);
            $anzahl = max(0, min(7, (int)$tageProWoche));
            $arbeitstage = $anzahl > 0 ? range(1, $anzahl) : [];
        }

        return in_array($weekday, $arbeitstage, true) ? $sollTag : 0.0;
    }

    public static function istStundenEintrag(array $mitarbeiter, array $eintrag, array $customTypen = []): float
    {
        $typ = (string)($eintrag['typ'] ?? '');
        if ($typ === 'arbeit') return self::number($eintrag['stunden'] ?? 0, 0.0);
        if ($typ === 'urlaub') {
            return self::tagesSoll($mitarbeiter, (string)($eintrag['datum'] ?? '')) * self::tagesanteil($eintrag);
        }
        if (in_array($typ, ['krank', 'feiertag'], true)) {
            return self::tagesSoll($mitarbeiter, (string)($eintrag['datum'] ?? ''));
        }
        if (in_array($typ, ['abwesend', 'gleitzeit', 'sonstig'], true)) return 0.0;

        foreach ($customTypen as $customTyp) {
            if (is_string($customTyp)) {
                if ($customTyp === $typ) return self::number($eintrag['stunden'] ?? 0, 0.0);
                continue;
            }
            if (!is_array($customTyp) || ($customTyp['value'] ?? null) !== $typ) continue;
            if (($customTyp['isArbeit'] ?? true) === false) return 0.0;
            if (($customTyp['istGleichSoll'] ?? false) === true) {
                return self::tagesSoll($mitarbeiter, (string)($eintrag['datum'] ?? ''));
            }
            return self::number($eintrag['stunden'] ?? 0, 0.0);
        }

        return 0.0;
    }

    public static function urlaubsanspruch(array $mitarbeiter, int $jahr): float
    {
        $raw = $mitarbeiter['urlaubstageProJahr'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return 30.0;
        }

        $key = (string)$jahr;
        return array_key_exists($key, $raw) && is_numeric($raw[$key]) ? (float)$raw[$key] : 30.0;
    }

    /** Tagesanteil eines Urlaubseintrags: 0.5 für einen halben Tag, sonst 1 (auch für alle anderen Typen). */
    public static function tagesanteil(array $eintrag): float
    {
        if ((string)($eintrag['typ'] ?? '') !== 'urlaub') return 1.0;
        $anteil = self::number($eintrag['tagesanteil'] ?? null, 1.0);
        return abs($anteil - 0.5) < 0.001 ? 0.5 : 1.0;
    }

    /** Urlaubstage eines Eintrags (0, 0.5 oder 1). */
    public static function urlaubTage(array $eintrag): float
    {
        return (string)($eintrag['typ'] ?? '') === 'urlaub' ? self::tagesanteil($eintrag) : 0.0;
    }

    /** Urlaubsanspruch-Eingabe auf 0,5-Tage-Schritte normalisieren (Komma erlaubt, nie negativ). */
    public static function urlaubstageNormalisieren(mixed $wert, float $default = 30.0): float
    {
        if (is_string($wert)) $wert = str_replace(',', '.', trim($wert));
        if (!is_numeric($wert)) return $default;
        return max(0.0, round((float)$wert * 2) / 2);
    }

    private static function arbeitstage(mixed $raw): array
    {
        $days = [];
        if (is_array($raw)) {
            foreach ($raw as $day => $enabled) {
                if ($enabled && is_numeric($day) && (int)$day >= 1 && (int)$day <= 7) {
                    $days[] = (int)$day;
                }
            }
        } elseif (is_string($raw) && trim($raw) !== '') {
            foreach (explode(',', $raw) as $day) {
                if (is_numeric(trim($day)) && (int)trim($day) >= 1 && (int)trim($day) <= 7) {
                    $days[] = (int)trim($day);
                }
            }
        }
        return array_values(array_unique($days));
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float)$value : $default;
    }
}