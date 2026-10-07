<?php
declare(strict_types=1);

namespace App\Services;

final class Stundenauswertung
{
    /**
     * @param array<string,mixed> $settings
     * @return list<array<string,mixed>>
     */
    public static function monat(\PDO $db, array $settings, string $monat): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/D', $monat)) {
            return [];
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $monat . '-01');
        if (!$start instanceof \DateTimeImmutable || $start->format('Y-m') !== $monat) {
            return [];
        }
        $ende = $start->modify('last day of this month');
        $startKey = $start->format('Y-m-d');
        $endeKey = $ende->format('Y-m-d');
        $jahr = (int)$start->format('Y');
        $feiertage = Feiertage::fuerJahr($jahr, Feiertage::customAusEinstellungen($settings));

        $users = $db->prepare(
            'SELECT username, kuerzel, role, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, showInZeitverwaltung
               FROM users
              WHERE role != ? AND showInZeitverwaltung != 0
              ORDER BY LOWER(username)'
        );
        if ($users === false) {
            return [];
        }
        $users->execute(['admin']);

        $stmt = $db->prepare('SELECT username, datum, typ, stunden FROM zeiterfassung WHERE datum >= ? AND datum <= ?');
        if ($stmt === false) {
            return [];
        }
        $stmt->execute([$startKey, $endeKey]);
        $byUser = [];
        foreach ($stmt->fetchAll() as $entry) {
            if (is_array($entry)) {
                $byUser[(string)$entry['username']][] = $entry;
            }
        }

        $customTypen = $settings['ze_custom_typen'] ?? [];
        if (!is_array($customTypen)) {
            $customTypen = [];
        }

        $rows = [];
        foreach ($users->fetchAll() as $user) {
            if (!is_array($user)) {
                continue;
            }
            $username = (string)$user['username'];
            $entries = $byUser[$username] ?? [];
            $entryDates = [];
            $arbeit = 0.0;
            $ist = 0.0;
            $urlaub = [];
            $krank = [];
            foreach ($entries as $entry) {
                $datum = (string)($entry['datum'] ?? '');
                $typ = (string)($entry['typ'] ?? '');
                $entryDates[$datum] = true;
                if ($typ === 'arbeit') {
                    $arbeit += (float)($entry['stunden'] ?? 0);
                }
                if ($typ === 'urlaub') {
                    $urlaub[$datum] = true;
                }
                if ($typ === 'krank') {
                    $krank[$datum] = true;
                }
                $ist += Sollzeit::istStundenEintrag($user, $entry, $customTypen);
            }

            $soll = 0.0;
            for ($tag = $start; $tag <= $ende; $tag = $tag->modify('+1 day')) {
                $datum = $tag->format('Y-m-d');
                $tagesSoll = Sollzeit::tagesSoll($user, $datum);
                $soll += $tagesSoll;
                if ($tagesSoll > 0.0 && isset($feiertage[$datum]) && !isset($entryDates[$datum])) {
                    $ist += $tagesSoll;
                }
            }

            $name = $username . (!empty($user['kuerzel']) ? ' (' . (string)$user['kuerzel'] . ')' : '');
            $rows[] = [
                'username' => $username,
                'name' => $name,
                'soll' => round($soll, 2),
                'ist' => round($ist, 2),
                'diff' => round($ist - $soll, 2),
                'arbeit' => round($arbeit, 2),
                'urlaub' => count($urlaub),
                'krank' => count($krank),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
        return $rows;
    }
}
