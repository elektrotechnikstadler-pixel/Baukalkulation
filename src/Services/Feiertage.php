<?php
declare(strict_types=1);

namespace App\Services;

final class Feiertage
{
    /**
     * @param list<array<string,mixed>>|array<string,mixed> $customFeiertage
     * @return array<string,string>
     */
    public static function fuerJahr(int $jahr, array $customFeiertage = []): array
    {
        $easter = self::ostersonntag($jahr);
        $holidays = [
            sprintf('%04d-01-01', $jahr) => 'Neujahr',
            sprintf('%04d-01-06', $jahr) => 'Heilige Drei Könige',
            $easter->modify('-2 days')->format('Y-m-d') => 'Karfreitag',
            $easter->modify('+1 day')->format('Y-m-d') => 'Ostermontag',
            sprintf('%04d-05-01', $jahr) => 'Tag der Arbeit',
            $easter->modify('+39 days')->format('Y-m-d') => 'Christi Himmelfahrt',
            $easter->modify('+50 days')->format('Y-m-d') => 'Pfingstmontag',
            $easter->modify('+60 days')->format('Y-m-d') => 'Fronleichnam',
            sprintf('%04d-08-15', $jahr) => 'Mariä Himmelfahrt',
            sprintf('%04d-10-03', $jahr) => 'Tag der Deutschen Einheit',
            sprintf('%04d-11-01', $jahr) => 'Allerheiligen',
            sprintf('%04d-12-25', $jahr) => '1. Weihnachtstag',
            sprintf('%04d-12-26', $jahr) => '2. Weihnachtstag',
        ];

        foreach ($customFeiertage as $custom) {
            if (!is_array($custom)) {
                continue;
            }
            $datum = (string)($custom['datum'] ?? '');
            if (!self::istDatumDesJahres($datum, $jahr)) {
                continue;
            }
            $name = trim((string)($custom['name'] ?? ''));
            $holidays[$datum] = $name !== '' ? $name : 'Feiertag';
        }

        ksort($holidays);
        return $holidays;
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<array<string,mixed>>
     */
    public static function customAusEinstellungen(array $settings): array
    {
        $raw = $settings['custom_feiertage'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    private static function ostersonntag(int $jahr): \DateTimeImmutable
    {
        $a = $jahr % 19;
        $b = intdiv($jahr, 100);
        $c = $jahr % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $month, $day));
    }

    private static function istDatumDesJahres(string $datum, int $jahr): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $datum
            && (int)$date->format('Y') === $jahr;
    }
}
