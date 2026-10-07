<?php
declare(strict_types=1);

namespace App\Services;

final class ZeitErinnerung
{
    /**
     * @return array{datum:string,fehlend:list<array<string,mixed>>}
     */
    public static function pruefe(\PDO $db, string $datum): array
    {
        $settings = self::settings($db);
        $feiertage = Feiertage::fuerJahr((int)substr($datum, 0, 4), Feiertage::customAusEinstellungen($settings));
        if (isset($feiertage[$datum])) {
            return ['datum' => $datum, 'fehlend' => []];
        }

        $usersStmt = $db->prepare(
            'SELECT username, kuerzel, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo
               FROM users'
        );
        if ($usersStmt === false) {
            return ['datum' => $datum, 'fehlend' => []];
        }
        $usersStmt->execute();
        $users = [];
        foreach ($usersStmt->fetchAll() as $user) {
            if (is_array($user)) {
                $users[(string)$user['username']] = $user;
            }
        }

        $baustellen = [];
        $bauStmt = $db->query('SELECT id, name FROM baustellen');
        if ($bauStmt !== false) {
            foreach ($bauStmt->fetchAll() as $row) {
                if (is_array($row)) {
                    $baustellen[(int)$row['id']] = (string)$row['name'];
                }
            }
        }

        $planStmt = $db->prepare("SELECT username, baustelleId FROM wochenplanung WHERE datum = ? AND typ = 'baustelle' AND baustelleId IS NOT NULL");
        if ($planStmt === false) {
            return ['datum' => $datum, 'fehlend' => []];
        }
        $planStmt->execute([$datum]);
        $geplant = [];
        foreach ($planStmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $username = (string)$row['username'];
            $baustelleId = (int)$row['baustelleId'];
            $geplant[$username][$baustelleId] = $baustellen[$baustelleId] ?? 'Baustelle #' . $baustelleId;
        }

        if ($geplant === []) {
            return ['datum' => $datum, 'fehlend' => []];
        }

        $zeitStmt = $db->prepare('SELECT DISTINCT username FROM zeiterfassung WHERE datum = ?');
        if ($zeitStmt === false) {
            return ['datum' => $datum, 'fehlend' => []];
        }
        $zeitStmt->execute([$datum]);
        $mitEintrag = [];
        foreach ($zeitStmt->fetchAll() as $row) {
            if (is_array($row)) {
                $mitEintrag[(string)$row['username']] = true;
            }
        }

        $fehlend = [];
        foreach ($geplant as $username => $baustellenNamen) {
            $user = $users[$username] ?? null;
            if ($user === null || isset($mitEintrag[$username])) {
                continue;
            }
            if (Sollzeit::tagesSoll($user, $datum) <= 0.0) {
                continue;
            }
            $fehlend[] = [
                'username' => $username,
                'display_name' => !empty($user['kuerzel']) ? $username . ' (' . (string)$user['kuerzel'] . ')' : $username,
                'baustellen' => array_values($baustellenNamen),
            ];
        }

        return ['datum' => $datum, 'fehlend' => $fehlend];
    }

    /** @return array<string,mixed> */
    private static function settings(\PDO $db): array
    {
        $stmt = $db->query('SELECT data FROM settings WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch() : false;
        $settings = is_array($row) ? json_decode((string)$row['data'], true) : [];
        return is_array($settings) ? $settings : [];
    }
}
