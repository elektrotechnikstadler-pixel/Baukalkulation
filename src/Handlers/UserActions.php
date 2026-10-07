<?php
namespace App\Handlers;

use App\Auth;
use App\Services\AuditService;

class UserActions
{
    public function __construct(private \PDO $db, private array $body) {}

    public function addUser(): void
    {
        Auth::requireRole('admin');
        $username = trim($this->body['username'] ?? '');
        $password = $this->body['password'] ?? '';
        $role     = $this->body['role'] ?? 'normal';
        if (!in_array($role, ['admin','master','normal'])) $role = 'normal';
        if (mb_strlen($username) < 3) jsonOut(['error' => 'Benutzername mindestens 3 Zeichen.'], 400);
        if (mb_strlen($password) < 6) jsonOut(['error' => 'Passwort mindestens 6 Zeichen.'], 400);
        if (strtolower($username) === 'systemadmin') jsonOut(['error' => 'Systemadmin kann nicht über die API angelegt werden.'], 403);

        $exists = $this->db->prepare("SELECT 1 FROM users WHERE username = ?");
        $exists->execute([$username]);
        if ($exists->fetchColumn()) jsonOut(['error' => 'Benutzername bereits vergeben.'], 409);

        $kuerzel = mb_strtoupper(mb_substr(trim($this->body['kuerzel'] ?? ''), 0, 2));
        // Doppelte Kürzel sind erlaubt, aber bestätigungspflichtig: sie machen
        // Legacy-Projektpositionen ohne zeitUser unzuordenbar.
        if ($kuerzel !== '' && empty($this->body['confirmKuerzelDuplicate'])) {
            $dupe = \App\Database::fetchOne($this->db, "SELECT username FROM users WHERE kuerzel = ?", [$kuerzel]);
            if ($dupe) {
                jsonOut([
                    'error'        => "Das Kürzel \"{$kuerzel}\" ist bereits an \"{$dupe['username']}\" vergeben. "
                                    . "Beide Mitarbeiter sind in Projektlisten dann nicht mehr unterscheidbar.",
                    'code'         => 'kuerzel_duplicate',
                    'needsConfirm' => true,
                ], 409);
            }
        }
        $isSub   = (bool)($this->body['isSubunternehmer'] ?? false);
        $dlId    = isset($this->body['dienstleisterId']) && $this->body['dienstleisterId'] !== '' ? (int)$this->body['dienstleisterId'] : null;
        $personalnummer = trim((string)($this->body['personalnummer'] ?? ''));

        $settings = Auth::loadSettings($this->db);
        $auto = (bool)($settings['personalnummer_auto'] ?? true);
        $length = max(1, min(12, (int)($settings['personalnummer_stellen'] ?? 4)));
        if ($auto) {
            $personalnummer = $this->nextPersonalnummer($length);
        } elseif ($personalnummer === '') {
            jsonOut(['error' => 'Personalnummer ist erforderlich (Auto-Vergabe ist deaktiviert).'], 400);
        }

        $this->db->prepare(
            "INSERT INTO users (username, password, role, kuerzel, personalnummer, visibleBaustellen, mustChangePassword, isSubunternehmer, dienstleisterId, stundenKategorie) VALUES (?,?,?,?,?,'all',1,?,?,?)"
        )->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $kuerzel, $personalnummer, (int)$isSub, $dlId, trim($this->body['stundenKategorie'] ?? '')]);
        AuditService::log('user_create', 'Username=' . $username . ', Role=' . $role);
        jsonOut(['ok' => true]);
    }

    public function deleteUser(): void
    {
        Auth::requireRole('admin');
        $del = trim($this->body['username'] ?? '');
        if ($del === $_SESSION['username']) jsonOut(['error' => 'Sie können sich nicht selbst löschen.'], 400);
        if (strtolower($del) === 'systemadmin') jsonOut(['error' => 'Systemadmin kann nicht gelöscht werden.'], 403);

        $u = $this->db->prepare("SELECT role FROM users WHERE username = ?");
        $u->execute([$del]);
        $row = $u->fetch();
        if (!$row) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);

        if ($row['role'] === 'admin') {
            $cnt = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($cnt <= 1) jsonOut(['error' => 'Der letzte Admin-Benutzer kann nicht gelöscht werden.'], 400);
        }

        $this->db->prepare("DELETE FROM users WHERE username = ?")->execute([$del]);
        AuditService::log('user_delete', 'Username=' . $del . ', Role=' . $row['role']);
        jsonOut(['ok' => true]);
    }

    public function listUsers(): void
    {
        Auth::requireRole('admin');
        $this->ensurePersonalnummerAutofill();
        $rows = $this->db->query("SELECT username, role, kuerzel, personalnummer, visibleBaustellen, showInZeitverwaltung, showInWochenplanung, mustChangePassword, isSubunternehmer, dienstleisterId, stundenKategorie, isLocked, failedLoginAttempts, lastLoginAt, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, urlaubstageProJahr FROM users ORDER BY LOWER(username)")->fetchAll();
        $list = array_map(fn($r) => [
            'username'             => $r['username'],
            'role'                 => $r['role'],
            'kuerzel'              => $r['kuerzel'] ?? '',
            'personalnummer'       => $r['personalnummer'] ?? '',
            'visibleBaustellen'    => ($r['visibleBaustellen'] === 'all') ? 'all' : (json_decode($r['visibleBaustellen'], true) ?? 'all'),
            'showInZeitverwaltung' => (bool)$r['showInZeitverwaltung'],
            'showInWochenplanung'  => (bool)$r['showInWochenplanung'],
            'mustChangePassword'   => (bool)$r['mustChangePassword'],
            'isSubunternehmer'     => (bool)$r['isSubunternehmer'],
            'dienstleisterId'      => $r['dienstleisterId'] ? (int)$r['dienstleisterId'] : null,
            'stundenKategorie'     => $r['stundenKategorie'] ?? '',
            'isLocked'             => (bool)($r['isLocked'] ?? 0),
            'failedLoginAttempts'  => (int)($r['failedLoginAttempts'] ?? 0),
            'lastLoginAt'          => (int)($r['lastLoginAt'] ?? 0),
            'sollstundenTag'       => (float)($r['sollstundenTag'] ?? 8),
            'sollTageWoche'        => (float)($r['sollTageWoche'] ?? 5),
            'arbeitstage'          => $r['arbeitstage'] ?? '1,2,3,4,5',
            'sollzeitJeWochentag'  => (bool)($r['sollzeitJeWochentag'] ?? 0),
            'sollstundenMo' => (float)($r['sollstundenMo'] ?? 0),
            'sollstundenDi' => (float)($r['sollstundenDi'] ?? 0),
            'sollstundenMi' => (float)($r['sollstundenMi'] ?? 0),
            'sollstundenDo' => (float)($r['sollstundenDo'] ?? 0),
            'sollstundenFr' => (float)($r['sollstundenFr'] ?? 0),
            'sollstundenSa' => (float)($r['sollstundenSa'] ?? 0),
            'sollstundenSo' => (float)($r['sollstundenSo'] ?? 0),
            'urlaubstageProJahr'   => json_decode($r['urlaubstageProJahr'] ?? '{}', true) ?? [],
        ], $rows);
        jsonOut(['ok' => true, 'users' => $list]);
    }

    public function unlockUser(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (!$target) jsonOut(['error' => 'Benutzername fehlt.'], 400);
        $stmt = $this->db->prepare("UPDATE users SET isLocked = 0, failedLoginAttempts = 0 WHERE username = ?");
        $stmt->execute([$target]);
        if ($stmt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        AuditService::log('user_unlock', 'Username=' . $target . ', durch=' . ($_SESSION['username'] ?? ''));
        jsonOut(['ok' => true]);
    }

    public function listUsersBasic(): void
    {
        Auth::requireAuth();
        $this->ensurePersonalnummerAutofill();
        $rows = $this->db->query("SELECT username, kuerzel, showInWochenplanung, isSubunternehmer, dienstleisterId, stundenKategorie, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, urlaubstageProJahr FROM users ORDER BY LOWER(username)")->fetchAll();
        $list = array_map(fn($r) => [
            'username'            => $r['username'],
            'kuerzel'             => $r['kuerzel'] ?? '',
            'showInWochenplanung' => (bool)$r['showInWochenplanung'],
            'isSubunternehmer'    => (bool)$r['isSubunternehmer'],
            'dienstleisterId'     => $r['dienstleisterId'] ? (int)$r['dienstleisterId'] : null,
            'stundenKategorie'    => $r['stundenKategorie'] ?? '',
            'sollstundenTag'      => (float)($r['sollstundenTag'] ?? 8),
            'sollTageWoche'       => (float)($r['sollTageWoche'] ?? 5),
            'arbeitstage'         => $r['arbeitstage'] ?? '1,2,3,4,5',
            'sollzeitJeWochentag'  => (bool)($r['sollzeitJeWochentag'] ?? 0),
            'sollstundenMo' => (float)($r['sollstundenMo'] ?? 0),
            'sollstundenDi' => (float)($r['sollstundenDi'] ?? 0),
            'sollstundenMi' => (float)($r['sollstundenMi'] ?? 0),
            'sollstundenDo' => (float)($r['sollstundenDo'] ?? 0),
            'sollstundenFr' => (float)($r['sollstundenFr'] ?? 0),
            'sollstundenSa' => (float)($r['sollstundenSa'] ?? 0),
            'sollstundenSo' => (float)($r['sollstundenSo'] ?? 0),
            'urlaubstageProJahr'  => json_decode($r['urlaubstageProJahr'] ?? '{}', true) ?? [],
        ], $rows);
        jsonOut(['ok' => true, 'users' => $list]);
    }

    public function setRole(): void
    {
        Auth::requireRole('admin');
        $target  = trim($this->body['username'] ?? '');
        $newRole = $this->body['role'] ?? 'normal';
        if (!in_array($newRole, ['admin','master','normal'])) jsonOut(['error' => 'Ungültige Rolle.'], 400);
        $cnt = $this->db->prepare("UPDATE users SET role = ?, sessionInvalidatedAt = ? WHERE username = ?");
        $cnt->execute([$newRole, time(), $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        AuditService::log('role_change', 'Username=' . $target . ', NeueRolle=' . $newRole);
        jsonOut(['ok' => true]);
    }

    public function setKuerzel(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $kz = mb_strtoupper(mb_substr(trim($this->body['kuerzel'] ?? ''), 0, 2));

        // Das Kürzel verknüpft Legacy-Projektpositionen (ohne zeitUser) mit dem
        // Mitarbeiter. Doppelvergabe ist möglich, muss aber bewusst bestätigt werden.
        if ($kz !== '' && empty($this->body['confirmKuerzelDuplicate'])) {
            $dupe = \App\Database::fetchOne(
                $this->db,
                "SELECT username FROM users WHERE kuerzel = ? AND LOWER(username) <> LOWER(?)",
                [$kz, $target]
            );
            if ($dupe) {
                jsonOut([
                    'error'        => "Das Kürzel \"{$kz}\" ist bereits an \"{$dupe['username']}\" vergeben. "
                                    . "Beide Mitarbeiter sind in Projektlisten dann nicht mehr unterscheidbar, "
                                    . "und ältere Projektbuchungen ohne Benutzer-Verknüpfung lassen sich "
                                    . "keinem von beiden mehr eindeutig zuordnen.",
                    'code'         => 'kuerzel_duplicate',
                    'needsConfirm' => true,
                ], 409);
            }
        }

        $cnt = $this->db->prepare("UPDATE users SET kuerzel = ? WHERE username = ?");
        $cnt->execute([$kz, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function setPersonalnummer(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $pn = trim((string)($this->body['personalnummer'] ?? ''));
        $cnt = $this->db->prepare("UPDATE users SET personalnummer = ? WHERE username = ?");
        $cnt->execute([$pn, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function setVisibility(): void
    {
        Auth::requireRole('admin');
        $target  = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $visible = $this->body['visibleBaustellen'] ?? 'all';
        $val = is_array($visible) ? json_encode($visible) : 'all';
        // K2: Sichtbarkeitsänderung invalidiert laufende Sessions des Users.
        $cnt = $this->db->prepare("UPDATE users SET visibleBaustellen = ?, sessionInvalidatedAt = ? WHERE username = ?");
        $cnt->execute([$val, time(), $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function setShowInZeitverwaltung(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $show = (int)(bool)($this->body['show'] ?? true);
        $cnt = $this->db->prepare("UPDATE users SET showInZeitverwaltung = ? WHERE username = ?");
        $cnt->execute([$show, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function setShowInWochenplanung(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $show = (int)(bool)($this->body['show'] ?? true);
        $cnt = $this->db->prepare("UPDATE users SET showInWochenplanung = ? WHERE username = ?");
        $cnt->execute([$show, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function adminResetPassword(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        $newPw  = $this->body['password'] ?? '';
        if (mb_strlen($newPw) < 6) jsonOut(['error' => 'Neues Passwort mindestens 6 Zeichen.'], 400);
        // K2: Passwort-Reset invalidiert laufende Sessions des Users.
        $cnt = $this->db->prepare("UPDATE users SET password = ?, mustChangePassword = 1, sessionInvalidatedAt = ? WHERE username = ?");
        $cnt->execute([password_hash($newPw, PASSWORD_BCRYPT), time(), $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        AuditService::log('admin_password_reset', 'Username=' . $target);
        jsonOut(['ok' => true]);
    }

    public function setSubunternehmer(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (strtolower($target) === 'systemadmin') jsonOut(['error' => 'Eigenschaften von Systemadmin nicht änderbar.'], 403);
        $isSub = (int)(bool)($this->body['isSubunternehmer'] ?? false);
        $dlId  = ($isSub && isset($this->body['dienstleisterId']) && $this->body['dienstleisterId'] !== '' && $this->body['dienstleisterId'] !== null)
                 ? (int)$this->body['dienstleisterId'] : null;
        $cnt = $this->db->prepare("UPDATE users SET isSubunternehmer = ?, dienstleisterId = ? WHERE username = ?");
        $cnt->execute([$isSub, $dlId, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function setStundenKategorie(): void
    {
        Auth::requireRole('admin');
        $target    = trim($this->body['username'] ?? '');
        $kategorie = trim($this->body['stundenKategorie'] ?? '');
        $cnt = $this->db->prepare("UPDATE users SET stundenKategorie = ? WHERE username = ?");
        $cnt->execute([$kategorie, $target]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    /** Theme-Einstellung speichern (jeder User für sich selbst). */
    public function setTheme(): void
    {
        Auth::requireAuth();
        $theme = in_array($this->body['theme'] ?? '', ['light', 'dark'], true)
            ? $this->body['theme']
            : 'light';
        $this->db->prepare("UPDATE users SET theme = ? WHERE username = ?")
                 ->execute([$theme, $_SESSION['username']]);
        jsonOut(['ok' => true]);
    }

    /** Benutzer-Profildaten laden (Anschrift, Soll, Urlaub etc.) */
    public function getUserProfile(): void
    {
        Auth::requireRole('admin');
        $target = trim($_GET['username'] ?? '');
        if (!$target) jsonOut(['error' => 'Username fehlt.'], 400);
        $stmt = $this->db->prepare("SELECT username, kuerzel, personalnummer, anschrift, email, telefon, sollstundenTag, sollTageWoche, arbeitstage, sollzeitJeWochentag, sollstundenMo, sollstundenDi, sollstundenMi, sollstundenDo, sollstundenFr, sollstundenSa, sollstundenSo, urlaubstageProJahr, stundenKategorie, mobileLightOnly FROM users WHERE username = ?");
        $stmt->execute([$target]);
        $row = $stmt->fetch();
        if (!$row) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        jsonOut(['ok' => true, 'profile' => [
            'username'           => $row['username'],
            'kuerzel'            => $row['kuerzel'] ?? '',
            'personalnummer'     => $row['personalnummer'] ?? '',
            'anschrift'          => $row['anschrift'] ?? '',
            'email'              => $row['email'] ?? '',
            'telefon'            => $row['telefon'] ?? '',
            'sollstundenTag'     => (float)($row['sollstundenTag'] ?? 8),
            'sollTageWoche'      => (float)($row['sollTageWoche'] ?? 5),
            'arbeitstage'        => $row['arbeitstage'] ?? '1,2,3,4,5',
            'sollzeitJeWochentag' => (bool)($row['sollzeitJeWochentag'] ?? 0),
            'sollstundenMo'      => (float)($row['sollstundenMo'] ?? 0),
            'sollstundenDi'      => (float)($row['sollstundenDi'] ?? 0),
            'sollstundenMi'      => (float)($row['sollstundenMi'] ?? 0),
            'sollstundenDo'      => (float)($row['sollstundenDo'] ?? 0),
            'sollstundenFr'      => (float)($row['sollstundenFr'] ?? 0),
            'sollstundenSa'      => (float)($row['sollstundenSa'] ?? 0),
            'sollstundenSo'      => (float)($row['sollstundenSo'] ?? 0),
            'urlaubstageProJahr' => json_decode($row['urlaubstageProJahr'] ?: '{}', true) ?: [],
            'stundenKategorie'   => $row['stundenKategorie'] ?? '',
            'mobileLightOnly'    => (bool)($row['mobileLightOnly'] ?? 0),
        ]]);
    }

    /** Benutzer-Profildaten speichern */
    public function saveUserProfile(): void
    {
        Auth::requireRole('admin');
        $target = trim($this->body['username'] ?? '');
        if (!$target) jsonOut(['error' => 'Username fehlt.'], 400);

        $fields = [];
        $params = [];

        if (array_key_exists('anschrift', $this->body)) {
            $fields[] = 'anschrift = ?'; $params[] = trim($this->body['anschrift']);
        }
        if (array_key_exists('email', $this->body)) {
            $fields[] = 'email = ?'; $params[] = trim($this->body['email']);
        }
        if (array_key_exists('telefon', $this->body)) {
            $fields[] = 'telefon = ?'; $params[] = trim($this->body['telefon']);
        }
        if (array_key_exists('sollstundenTag', $this->body)) {
            $value = $this->body['sollstundenTag'];
            if (!is_numeric($value) || (float)$value < 0 || (float)$value > 24) {
                jsonOut(['error' => 'Sollstunden müssen numerisch zwischen 0 und 24 liegen.'], 400);
            }
            $fields[] = 'sollstundenTag = ?'; $params[] = (float)$value;
        }
        if (array_key_exists('sollTageWoche', $this->body)) {
            $fields[] = 'sollTageWoche = ?'; $params[] = max(1, min(7, (float)$this->body['sollTageWoche']));
        }
        if (array_key_exists('arbeitstage', $this->body)) {
            $raw = trim((string)($this->body['arbeitstage'] ?? ''));
            $valid = implode(',', array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 7))));
            $fields[] = 'arbeitstage = ?'; $params[] = $valid ?: '1,2,3,4,5';
        }
        if (array_key_exists('sollzeitJeWochentag', $this->body)) {
            $enabled = $this->body['sollzeitJeWochentag'];
            if (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
                jsonOut(['error' => 'Ungültiger Wert für Sollzeit je Wochentag.'], 400);
            }
            $fields[] = 'sollzeitJeWochentag = ?'; $params[] = $enabled ? 1 : 0;
        }
        foreach ([
            'sollstundenMo', 'sollstundenDi', 'sollstundenMi', 'sollstundenDo',
            'sollstundenFr', 'sollstundenSa', 'sollstundenSo',
        ] as $field) {
            if (!array_key_exists($field, $this->body)) continue;
            $value = $this->body[$field];
            if (!is_numeric($value) || (float)$value < 0 || (float)$value > 24) {
                jsonOut(['error' => 'Wochentags-Sollstunden müssen numerisch zwischen 0 und 24 liegen.'], 400);
            }
            $fields[] = $field . ' = ?'; $params[] = (float)$value;
        }
        if (array_key_exists('urlaubstageProJahr', $this->body)) {
            $fields[] = 'urlaubstageProJahr = ?'; $params[] = json_encode($this->body['urlaubstageProJahr']);
        }
        if (array_key_exists('stundenKategorie', $this->body)) {
            $fields[] = 'stundenKategorie = ?'; $params[] = trim($this->body['stundenKategorie']);
        }
        if (array_key_exists('mobileLightOnly', $this->body)) {
            $fields[] = 'mobileLightOnly = ?'; $params[] = $this->body['mobileLightOnly'] ? 1 : 0;
        }

        if (empty($fields)) jsonOut(['error' => 'Keine Daten zum Speichern.'], 400);

        $params[] = $target;
        $this->db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE username = ?")->execute($params);
        AuditService::log('user_profile_update', 'Username=' . $target);
        jsonOut(['ok' => true]);
    }

    private function nextPersonalnummer(int $length): string
    {
        $rows = $this->db->query("SELECT personalnummer FROM users WHERE TRIM(COALESCE(personalnummer, '')) <> ''")->fetchAll();
        $max = 0;
        foreach ($rows as $r) {
            $digits = preg_replace('/\D+/', '', (string)($r['personalnummer'] ?? ''));
            if ($digits === '') continue;
            $num = (int)$digits;
            if ($num > $max) $max = $num;
        }
        return str_pad((string)($max + 1), $length, '0', STR_PAD_LEFT);
    }

    private function ensurePersonalnummerAutofill(): void
    {
        $settings = Auth::loadSettings($this->db);
        $auto = (bool)($settings['personalnummer_auto'] ?? true);
        if (!$auto) return;
        $length = max(1, min(12, (int)($settings['personalnummer_stellen'] ?? 4)));
        $rows = $this->db->query("SELECT username FROM users WHERE TRIM(COALESCE(personalnummer, '')) = '' ORDER BY id")->fetchAll();
        if (!$rows) return;
        foreach ($rows as $row) {
            $next = $this->nextPersonalnummer($length);
            $this->db->prepare("UPDATE users SET personalnummer = ? WHERE username = ?")->execute([$next, $row['username']]);
        }
    }
}
