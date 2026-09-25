<?php
namespace App\Handlers;

use App\Auth;
use App\Core\ModuleLoader;
use App\Services\AuditService;
use App\Services\LicenseService;

class AuthActions
{
    public function __construct(private \PDO $db, private array $body) {}

    /** check – Session-Status + Benutzerinfo + Settings */
    public function check(): void
    {
        $perms    = [];
        $role     = null;
        $visibility = 'all';
        $kuerzel  = null;
        $isSubunternehmer = false;
        $dienstleisterId  = null;
        $stundenKategorie = '';
        $mustChange = false;

        if (!empty($_SESSION['authenticated'])) {
            $user = $this->db->prepare("SELECT * FROM users WHERE username = ?");
            $user->execute([$_SESSION['username']]);
            $u = $user->fetch();
            if ($u) {
                $role = $u['role'] ?? 'normal';
                $_SESSION['role'] = $role;
                $vb = $u['visibleBaustellen'] ?? 'all';
                $visibility = ($vb === 'all') ? 'all' : (json_decode($vb, true) ?? 'all');
                // Sichtbarkeit auf Ober-/Unterprojekte ausdehnen, damit
                // Berechtigungen, die für das Oberprojekt gelten, auch
                // für dessen Unterprojekte gelten (und umgekehrt für die
                // Navigation).
                if (is_array($visibility)) {
                    $visibility = Auth::expandVisibleBaustellenIds($this->db, $visibility);
                }
                $kuerzel          = $u['kuerzel'] ?? null;
                $isSubunternehmer = (bool)($u['isSubunternehmer'] ?? 0);
                $dienstleisterId  = $u['dienstleisterId'] ? (int)$u['dienstleisterId'] : null;
                $stundenKategorie = $u['stundenKategorie'] ?? '';
                $mustChange       = (bool)($u['mustChangePassword'] ?? 0);
            }
            $perms = Auth::getCurrentUserPerms($this->db);
            // Per-user mobileLightOnly übersteuert Rollen-Berechtigung
            if ($u && !empty($u['mobileLightOnly'])) {
                $perms['mobileLightOnly'] = true;
            }
        }

        $userCount = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();

        jsonOut([
            'loggedIn'           => !empty($_SESSION['authenticated']),
            'version'            => APP_VERSION,
            'username'           => $_SESSION['username'] ?? null,
            'role'               => $role,
            'kuerzel'            => $kuerzel,
            'permissions'        => $perms,
            'visibility'         => $visibility,
            'needSetup'          => $userCount === 0,
            'mustChangePassword' => $mustChange,
            'isSubunternehmer'   => $isSubunternehmer,
            'dienstleisterId'    => $dienstleisterId,
            'stundenKategorie'   => $stundenKategorie,
            'settings'           => Auth::loadSettings($this->db),
            'modules'            => !empty($_SESSION['authenticated'])
                                        ? (new ModuleLoader($this->db))->listForFrontend()
                                        : [],
            'license'            => LicenseService::getPublicInfo(),
        ]);
    }

    /** setup – Erstbenutzer anlegen */
    public function setup(): void
    {
        $cnt = (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($cnt > 0) jsonOut(['error' => 'Setup bereits abgeschlossen. Loggen Sie sich ein.'], 403);

        $username = trim($this->body['username'] ?? '');
        $password = $this->body['password'] ?? '';
        if (mb_strlen($username) < 3) jsonOut(['error' => 'Benutzername mindestens 3 Zeichen.'], 400);
        if (mb_strlen($password) < 6) jsonOut(['error' => 'Passwort mindestens 6 Zeichen.'], 400);

        $this->db->prepare(
            "INSERT INTO users (username, password, role, visibleBaustellen) VALUES (?, ?, 'admin', 'all')"
        )->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);

        AuditService::log('setup', 'Erstbenutzer erstellt: ' . $username);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['authenticated'] = true;
        $_SESSION['username']      = $username;
        $_SESSION['role']          = 'admin';
        $_SESSION['_login_time']   = time();
        jsonOut(['ok' => true]);
    }

    /** login */
    public function login(): void
    {
        $username = trim($this->body['username'] ?? '');
        $password = $this->body['password'] ?? '';

        // ── K4: Rate-Limiting per SQLite-Tabelle ──
        // max 5 Fehlversuche / 60 s pro IP, atomar via Transaktion.
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $now = time();
        try {
            $this->db->beginTransaction();
            // Alte Einträge entfernen (>60s)
            $this->db->prepare("DELETE FROM login_attempts WHERE ts < ?")->execute([$now - 60]);
            $stmtCnt = $this->db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ts >= ?");
            $stmtCnt->execute([$ip, $now - 60]);
            $ipCount = (int)$stmtCnt->fetchColumn();
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $ipCount = 0;
        }
        if ($ipCount >= 5) {
            jsonOut(['error' => 'Zu viele Anmeldeversuche. Bitte 1 Minute warten.'], 429);
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $u = $stmt->fetch();

        // ── Kontosperre prüfen ──
        if ($u && !empty($u['isLocked'])) {
            AuditService::log('login_blocked', 'Konto gesperrt, Username=' . $username . ', IP=' . $ip);
            jsonOut(['error' => 'Ihr Konto ist gesperrt. Bitte wenden Sie sich an den Systemadministrator.', 'locked' => true], 403);
        }

        if ($u && password_verify($password, $u['password'])) {
            // Erfolgreicher Login – Fehlversuche dieser IP entfernen + DB-Zähler zurücksetzen
            $this->db->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
            $this->db->prepare("UPDATE users SET failedLoginAttempts = 0, lastLoginAt = ? WHERE username = ?")
                     ->execute([time(), $username]);

            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['username']      = $username;
            $_SESSION['role']          = $u['role'] ?? 'normal';
            $_SESSION['kuerzel']       = $u['kuerzel'] ?? '';
            $_SESSION['_last_activity'] = time();
            // K2: Login-Zeitstempel für Session-Invalidierungs-Check
            $_SESSION['_login_time']   = time();
            AuditService::log('login', 'Erfolgreich, IP=' . $ip);
            $resp = ['ok' => true];
            if (!empty($u['mustChangePassword'])) $resp['mustChangePassword'] = true;
            jsonOut($resp);
        }

        // Fehlversuch protokollieren
        $this->db->prepare("INSERT INTO login_attempts (ip, username, ts) VALUES (?, ?, ?)")
                 ->execute([$ip, $username, $now]);

        // ── Fehlversuche auf User-Ebene zählen und ggf. sperren ──
        if ($u) {
            $newCount = ((int)($u['failedLoginAttempts'] ?? 0)) + 1;
            if ($newCount >= 3) {
                $this->db->prepare("UPDATE users SET failedLoginAttempts = ?, isLocked = 1 WHERE username = ?")->execute([$newCount, $username]);
                AuditService::log('account_locked', 'Username=' . $username . ' nach ' . $newCount . ' Fehlversuchen, IP=' . $ip);
                jsonOut(['error' => 'Ihr Konto wurde nach 3 fehlgeschlagenen Anmeldeversuchen gesperrt. Bitte wenden Sie sich an den Systemadministrator.', 'locked' => true], 403);
            } else {
                $this->db->prepare("UPDATE users SET failedLoginAttempts = ? WHERE username = ?")->execute([$newCount, $username]);
                $remaining = 3 - $newCount;
                jsonOut(['error' => "Ungültiges Passwort. Noch {$remaining} Versuch(e) bevor Ihr Konto gesperrt wird."], 401);
            }
        }

        jsonOut(['error' => 'Ungültiger Benutzername oder Passwort.'], 401);
    }

    /** logout */
    public function logout(): void
    {
        session_unset();
        session_destroy();
        jsonOut(['ok' => true]);
    }

    /** change_password */
    public function changePassword(): void
    {
        Auth::requireAuth();
        $oldPw = $this->body['old'] ?? '';
        $newPw = $this->body['new'] ?? '';
        if (mb_strlen($newPw) < 6) jsonOut(['error' => 'Neues Passwort mindestens 6 Zeichen.'], 400);

        $stmt = $this->db->prepare("SELECT password, username FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
        $u = $stmt->fetch();
        if (!$u) jsonOut(['error' => 'Benutzer nicht gefunden.'], 404);
        if (!password_verify($oldPw, $u['password'])) {
            jsonOut(['error' => 'Aktuelles Passwort falsch.'], 401);
        }

        $this->db->prepare("UPDATE users SET password = ?, mustChangePassword = 0 WHERE username = ?")
                  ->execute([password_hash($newPw, PASSWORD_BCRYPT), $_SESSION['username']]);
        AuditService::log('password_change', 'Benutzer=' . $_SESSION['username']);
        jsonOut(['ok' => true]);
    }
}
