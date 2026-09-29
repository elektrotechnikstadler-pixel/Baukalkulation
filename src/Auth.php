<?php
namespace App;

/**
 * Authentifizierung, Autorisierung, Berechtigungen, Einstellungen.
 */
class Auth
{
    private static ?array $settingsCache = null;
    private static ?array $permissionsCache = null;

    /** In der DB verschlüsselte Einstellungen (SecretBox); werden nie an den Browser geliefert. */
    public const SECRET_SETTINGS = ['smtp_pass', 'gemini_api_key'];

    // ── Auth-Checks ──────────────────────────────────────────
    public static function requireAuth(): void
    {
        if (empty($_SESSION['authenticated'])) {
            jsonOut(['error' => 'Nicht angemeldet.', 'redirect' => 'login.html'], 401);
        }
        // K2 (v1.7.1): Wenn die Rolle/Sicherheit des Users seit Session-
        // Beginn geändert wurde, Session verwerfen und neu anmelden lassen.
        $loginAt = (int)($_SESSION['_login_time'] ?? 0);
        $username = (string)($_SESSION['username'] ?? '');
        if ($loginAt > 0 && $username !== '') {
            try {
                $db = Database::connect();
                $stmt = $db->prepare("SELECT sessionInvalidatedAt FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $invalidatedAt = (int)($stmt->fetchColumn() ?: 0);
                if ($invalidatedAt > 0 && $invalidatedAt > $loginAt) {
                    session_unset();
                    session_destroy();
                    jsonOut(['error' => 'Ihre Sitzung wurde aufgrund einer Berechtigungsänderung beendet. Bitte erneut anmelden.', 'redirect' => 'login.html'], 401);
                }
            } catch (\Throwable $e) {
                // Fehler beim Sicherheits-Check nicht eskalieren – fail-open
                // wäre hier riskant, aber DB-Ausfall darf App nicht blockieren.
                error_log('[Auth] sessionInvalidatedAt check failed: ' . $e->getMessage());
            }
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireAuth();
        if (!in_array($_SESSION['role'] ?? 'normal', $roles, true)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Aktion.'], 403);
        }
    }

    // ── Systemadmin sicherstellen ────────────────────────────
    public static function ensureSystemadmin(\PDO $db): void
    {
        // Nur Systemadmin erstellen wenn mindestens 1 User über Setup angelegt wurde
        $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount === 0) return; // Setup-Endpoint soll zuerst den Admin anlegen

        $exists = $db->prepare("SELECT 1 FROM users WHERE LOWER(username) = 'systemadmin'");
        $exists->execute();
        if ($exists->fetchColumn()) return;

        // Passwort je Installation (Env oder zufällig, siehe SystemadminPassword).
        // mustChangePassword=1 erzwingt Änderung beim ersten Login.
        $db->prepare(
            "INSERT INTO users (username, password, role, kuerzel, visibleBaustellen, mustChangePassword) VALUES (?, ?, 'admin', 'SA', 'all', 1) ON CONFLICT DO NOTHING"
        )->execute([Services\SystemadminPassword::USERNAME, Services\SystemadminPassword::newHash()]);
    }

    // ── Einstellungen laden (mit Defaults) ───────────────────
    public static function loadSettings(\PDO $db): array
    {
        if (self::$settingsCache !== null) return self::$settingsCache;
        $defaults = [
            'modul_pauschalen'        => true,
            'modul_dienstleister'     => true,
            'modul_schnellnotizen'    => true,
            'modul_wochenplanung'     => true,
            'modul_zeiterfassung'     => true,
            'modul_stundenabgleich'   => true,
            'modul_stundenerfassung'  => true,
            'modul_rechnungen'        => true,
            'modul_termine'           => true,
            'bau_material'            => true,
            'bau_arbeitszeit'         => true,
            'bau_pauschalen'          => true,
            'bau_bautagebuch'         => true,
            'bau_fotos'               => true,
            'bau_dateien'             => true,
            'auto_import_stunden'     => false,
            'erweiterte_zeiterfassung' => false,
            'gleitzeit_startdatum'    => '',
            'wochenplan_display_port' => '',
            'session_timeout_minutes' => 480,
            'firma_name'              => '',
            'firma_strasse'           => '',
            'firma_plz'               => '',
            'firma_ort'               => '',
            'firma_telefon'           => '',
            'firma_email'             => '',
            'firma_website'           => '',
            'firma_steuernr'          => '',
            'firma_ustid'             => '',
            'firma_bankname'          => '',
            'firma_iban'              => '',
            'firma_bic'               => '',
            'firma_logo_url'          => '',
            'firma_mwst_satz'         => '19',
            'firma_datenschutz'       => '',
            'firma_kleinunternehmer'  => false,
            'firma_reverse_charge'    => false,
            'firma_reverse_charge_text' => 'Steuerschuldnerschaft des Leistungsempfängers gemäß § 13b UStG (Reverse Charge).',
            'kundennummer_auto'       => true,
            'kundennummer_stellen'    => '6',
            'personalnummer_auto'     => true,
            'personalnummer_stellen'  => '4',
            'branche'                 => '',
            'branche_initialized'     => false,
            'modul_datanorm'          => true,
            'modul_kupfer_del'        => true,
            'modul_metall_erfassung'  => false,
            'modul_din1090'           => false,
            'modul_lager'             => false,
            'modul_aufmass'           => false,
            'modul_stundenauswertung' => true,
            'modul_auswertung'        => true,
            'modul_dashboard'         => false,
            'modul_vde0100'           => false,
            'startseite'              => '',
            'mobile_startseite'       => '',
            'mobile_btn_order'        => '',
            'gemini_api_key'          => '',
            'gemini_model'            => 'gemini-2.5-flash-lite',
            // ── SMTP / E-Mail ────────────────────────────────
            'smtp_host'               => '',
            'smtp_port'               => '587',
            'smtp_user'               => '',
            'smtp_pass'               => '',
            'smtp_from_email'         => '',
            'smtp_from_name'          => '',
            'smtp_security'           => 'tls',
            'gleitzeit_enabled'       => true,
            'ze_custom_typen'         => [],
            'zeiterfassung_abschluss_jahr' => 0,
            'auswertung_archiv_abschluss_jahr' => 0,
        ];
        $row   = $db->query("SELECT data FROM settings WHERE id = 1")->fetch();
        $saved = $row ? (json_decode($row['data'], true) ?? []) : [];
        $settings = array_merge($defaults, $saved);
        foreach (self::SECRET_SETTINGS as $key) {
            try {
                $settings[$key] = \App\Services\SecretBox::decrypt((string)$settings[$key]);
            } catch (\Throwable $e) {
                // z. B. Sicherung einer anderen Installation eingespielt (anderer Schlüssel)
                error_log("[Auth] Einstellung {$key} nicht entschlüsselbar: " . $e->getMessage());
                $settings[$key] = '';
            }
        }
        return self::$settingsCache = $settings;
    }

    /** Einstellungen für den Browser: Geheimnisse nie ausliefern, nur ob sie gesetzt sind. */
    public static function publicSettings(array $settings): array
    {
        foreach (self::SECRET_SETTINGS as $key) {
            $settings[$key . '_gesetzt'] = ($settings[$key] ?? '') !== '';
            $settings[$key] = '';
        }
        $logo = $settings['firma_logo_url'] ?? '';
        if (!is_string($logo) || !Services\FirmenLogo::isValidSetting($logo)) {
            $settings['firma_logo_url'] = '';
        }
        return $settings;
    }

    // ── Einstellungen speichern ──────────────────────────────
    public static function saveSettings(\PDO $db, array $settings): void
    {
        foreach (self::SECRET_SETTINGS as $key) {
            $value = (string)($settings[$key] ?? '');
            $settings[$key] = $value === '' ? '' : \App\Services\SecretBox::encrypt($value);
        }
        $db->prepare("UPDATE settings SET data = ? WHERE id = 1")
           ->execute([json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        self::$settingsCache = null;
    }

    // ── Session-Timeout (Inaktivität) in Sekunden ────────────
    // Liest den konfigurierbaren Wert (Minuten) aus den Einstellungen und
    // klemmt ihn auf einen sinnvollen Bereich (5 Min bis 30 Tage). Fällt bei
    // fehlender/leerer DB (z.B. Setup) auf 8 Stunden zurück.
    public static function sessionTimeoutSeconds(\PDO $db): int
    {
        try {
            $s   = self::loadSettings($db);
            $min = (int)($s['session_timeout_minutes'] ?? 480);
        } catch (\Throwable $e) {
            return 28800;
        }
        if ($min < 5)      $min = 5;      // mindestens 5 Minuten
        if ($min > 43200)  $min = 43200;  // höchstens 30 Tage
        return $min * 60;
    }

    // ── Standard-Berechtigungen ──────────────────────────────
    public static function getDefaultPermissions(): array
    {
        return [
            'master' => [
                'canSeePrices'              => true,
                'canEditMaterial'           => true,
                'canEditArbeitszeit'        => true,
                'canEditPauschalen'         => true,
                'canArchive'                => true,
                'canOrderMaterial'          => true,
                'canExport'                 => true,
                'canManageKatalog'          => true,
                'canManageStundenKatalog'   => true,
                'canSeeBautagebuch'         => true,
                'canSeeOffenesMaterial'     => true,
                'canSeeAbschlagsrechnungen' => true,
                'canSeeZeituebersicht'      => true,
                'canSeeGewinn'              => true,
                'canDeleteBaustelle'        => true,
                'canCreateBaustelle'        => true,
                'canSeeAllWochenplan'       => true,
                'canSeeAllStunden'          => true,
                'canReadKunden'             => true,
                'canWriteKunden'            => true,
                'canManageUnterprojekte'    => true,
                'canExportInform'           => true,
                'canManageDienstleister'    => true,
                'canSeeSubunternehmerReport'=> true,
                'canSeeRechnungen'          => true,
                'canManageRechnungen'       => true,
                'canSeeAngebote'            => true,
                'canManageAngebote'         => true,
                'canSeeOwnWochenplan'       => true,
                'canSeeOwnTermine'          => true,
                'canSeeAllTermine'          => true,
                'canManageTermine'          => true,
                'canSeeStundenauswertung'   => true,
                'canSeeAuswertung'          => true,
                // Dokumentenordner
                'canReadEingangsbelege'     => true,
                'canWriteEingangsbelege'    => true,
                'canReadAusgangsbelege'     => true,
                'canWriteAusgangsbelege'    => true,
                'canReadAngebote'           => true,
                'canWriteAngebote'          => true,
                'canReadDateien'            => true,
                'canWriteDateien'           => true,
                'canReadFotos'              => true,
                'canWriteFotos'             => true,
                'canReadProtokolle'         => true,
                'canWriteProtokolle'        => true,
                'canReadSonstiges'          => true,
                'canWriteSonstiges'         => true,
                // Aufmaß / Lager (v2.1)
                'canReadAufmass'            => true,
                'canWriteAufmass'           => true,
                'canApproveAufmass'         => true,
                'canReadLager'              => true,
                'canWriteLager'             => true,
                'canManageLagerorte'        => true,
                // Dashboard (v2.4)
                'canReadDashboard'          => true,
                'canWriteDashboard'         => true,
                'canManageDashboard'        => true,
                // VDE 0100 (v2.7.0)
                'canReadVde0100'            => true,
                'canWriteVde0100'           => true,
                'canFixateVde0100'          => true,
                // DIN EN 1090 (v2.8.8)
                'canReadDin1090'            => true,
                'canWriteDin1090'           => true,
            ],
            'normal' => [
                'canSeePrices'              => false,
                'canEditMaterial'           => false,
                'canEditArbeitszeit'        => false,
                'canEditPauschalen'         => false,
                'canArchive'                => false,
                'canOrderMaterial'          => false,
                'canExport'                 => false,
                'canManageKatalog'          => false,
                'canManageStundenKatalog'   => false,
                'canSeeBautagebuch'         => true,
                'canSeeOffenesMaterial'     => false,
                'canSeeAbschlagsrechnungen' => false,
                'canSeeZeituebersicht'      => false,
                'canSeeGewinn'              => false,
                'canDeleteBaustelle'        => false,
                'canCreateBaustelle'        => true,
                'canSeeAllWochenplan'       => false,
                'canSeeAllStunden'          => false,
                'canReadKunden'             => false,
                'canWriteKunden'            => false,
                'canManageUnterprojekte'    => false,
                'canExportInform'           => false,
                'canManageDienstleister'    => false,
                'canSeeSubunternehmerReport'=> false,
                'canSeeRechnungen'          => false,
                'canManageRechnungen'       => false,
                'canSeeAngebote'            => false,
                'canManageAngebote'         => false,
                'canSeeOwnWochenplan'       => true,
                'canSeeOwnTermine'          => true,
                'canSeeAllTermine'          => false,
                'canManageTermine'          => false,
                'canSeeStundenauswertung'   => false,
                'canSeeAuswertung'          => false,
                // Dokumentenordner
                'canReadEingangsbelege'     => false,
                'canWriteEingangsbelege'    => false,
                'canReadAusgangsbelege'     => false,
                'canWriteAusgangsbelege'    => false,
                'canReadAngebote'           => false,
                'canWriteAngebote'          => false,
                'canReadDateien'            => true,
                'canWriteDateien'           => true,
                'canReadFotos'              => true,
                'canWriteFotos'             => true,
                'canReadProtokolle'         => true,
                'canWriteProtokolle'        => false,
                'canReadSonstiges'          => true,
                'canWriteSonstiges'         => false,
                // Aufmaß / Lager (v2.1)
                'canReadAufmass'            => true,
                'canWriteAufmass'           => false,
                'canApproveAufmass'         => false,
                'canReadLager'              => true,
                'canWriteLager'             => false,
                'canManageLagerorte'        => false,
                // Dashboard (v2.4)
                'canReadDashboard'          => true,
                'canWriteDashboard'         => true,
                'canManageDashboard'        => false,
                // VDE 0100 (v2.7.0)
                'canReadVde0100'            => true,
                'canWriteVde0100'           => false,
                'canFixateVde0100'          => false,
                // DIN EN 1090 (v2.8.8)
                'canReadDin1090'            => true,
                'canWriteDin1090'           => false,
            ],
        ];
    }

    // ── Berechtigungen laden (mit Merge) ─────────────────────
    public static function loadPermissions(\PDO $db): array
    {
        if (self::$permissionsCache !== null) return self::$permissionsCache;
        $defaults = self::getDefaultPermissions();
        $row = $db->query("SELECT data FROM permissions_config WHERE id = 1")->fetch();
        if (!$row) return $defaults;
        $stored = json_decode($row['data'], true);
        if (!$stored) return $defaults;
        foreach ($defaults as $role => $keys) {
            if (!isset($stored[$role])) {
                $stored[$role] = $keys;
            } else {
                foreach ($keys as $key => $val) {
                    if (!array_key_exists($key, $stored[$role])) {
                        $stored[$role][$key] = $val;
                    }
                }
            }
        }
        self::$permissionsCache = $stored;
        return $stored;
    }

    public static function savePermissions(\PDO $db, array $perms): void
    {
        $db->prepare("UPDATE permissions_config SET data = ? WHERE id = 1")
           ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE)]);
        self::$permissionsCache = null;
    }

    // ── Aktuelle Berechtigungen des Users ────────────────────
    public static function getCurrentUserPerms(\PDO $db): array
    {
        $role = $_SESSION['role'] ?? 'normal';
        if ($role === 'admin') {
            $keys = [
                'canSeePrices','canEditMaterial','canEditArbeitszeit','canEditPauschalen',
                'canArchive','canOrderMaterial','canExport','canManageKatalog',
                'canManageStundenKatalog','canSeeBautagebuch','canSeeOffenesMaterial',
                'canSeeAbschlagsrechnungen','canSeeZeituebersicht','canSeeGewinn',
                'canDeleteBaustelle','canCreateBaustelle','canSeeAllWochenplan','canSeeAllStunden',
                'canReadKunden','canWriteKunden','canManageUnterprojekte','canExportInform',
                'canManageDienstleister','canSeeSubunternehmerReport',
                'canSeeRechnungen','canManageRechnungen','canSeeAngebote','canManageAngebote',
                'canSeeOwnWochenplan',
                'canSeeOwnTermine','canSeeAllTermine','canManageTermine',
                'canSeeStundenauswertung',
                'canSeeAuswertung',
                // Aufmaß / Lager (v2.1)
                'canReadAufmass','canWriteAufmass','canApproveAufmass',
                'canReadLager','canWriteLager','canManageLagerorte',
                // Dashboard (v2.4)
                'canReadDashboard','canWriteDashboard','canManageDashboard',
                // VDE 0100 (v2.7.0)
                'canReadVde0100','canWriteVde0100','canFixateVde0100',
            ];
            $result = array_fill_keys($keys, true);
            return $result;
        }
        $perms = self::loadPermissions($db);
        return $perms[$role] ?? self::getDefaultPermissions()[$role] ?? [];
    }

    public static function canDo(\PDO $db, string $perm): bool
    {
        $role = $_SESSION['role'] ?? 'normal';
        if ($role === 'admin') return true;
        $perms = self::getCurrentUserPerms($db);
        return !empty($perms[$perm]);
    }

    // ── Sichtbarkeit: Oberprojekt ↔ Unterprojekt-Cascade ─────
    /**
     * Erweitert eine Sichtbarkeits-Liste rekursiv um zugehörige Ober- und
     * Unterprojekte. Berechtigungen, die für ein Oberprojekt gelten, sollen
     * automatisch auch für alle dessen Unterprojekte (parentId == oberId)
     * gelten — und umgekehrt darf der Zugriff auf ein Unterprojekt das
     * Oberprojekt zumindest in der Navigation sichtbar machen.
     *
     * @param mixed $visibility 'all' oder array<int> mit Baustellen-IDs.
     * @return mixed 'all' oder array<int> (eindeutig, sortiert).
     */
    public static function expandVisibleBaustellenIds(\PDO $db, mixed $visibility): mixed
    {
        if ($visibility === 'all') return 'all';
        if (!is_array($visibility)) return 'all';

        $ids = [];
        foreach ($visibility as $v) {
            $i = (int)$v;
            if ($i > 0) $ids[$i] = true;
        }
        if (empty($ids)) return [];

        // Eltern/Kind-Mapping einmalig aus baustellen-Tabelle ableiten.
        // parentId steckt im JSON-Datenblob, daher kompletter Tabellen-Scan.
        static $relCache = null;
        if ($relCache === null) {
            $relCache = ['childrenOf' => [], 'parentOf' => []];
            $rows = $db->query("SELECT id, data FROM baustellen WHERE archiviert = 0")->fetchAll();
            foreach ($rows as $r) {
                $blob = json_decode($r['data'], true) ?: [];
                $pid  = (int)($blob['parentId'] ?? 0);
                $id   = (int)$r['id'];
                if ($pid > 0 && $id > 0) {
                    $relCache['parentOf'][$id] = $pid;
                    $relCache['childrenOf'][$pid][] = $id;
                }
            }
        }

        // BFS: alle Unter- und Oberprojekte einsammeln (transitive Hülle).
        $queue = array_keys($ids);
        while (!empty($queue)) {
            $cur = array_shift($queue);
            // Kinder
            foreach ($relCache['childrenOf'][$cur] ?? [] as $child) {
                if (!isset($ids[$child])) {
                    $ids[$child] = true;
                    $queue[] = $child;
                }
            }
            // Eltern (bidirektional, damit Navigation funktioniert)
            $parent = $relCache['parentOf'][$cur] ?? null;
            if ($parent && !isset($ids[$parent])) {
                $ids[$parent] = true;
                $queue[] = $parent;
            }
        }

        $result = array_keys($ids);
        sort($result);
        return $result;
    }

    /**
     * Liefert die effektive Sichtbarkeits-Liste des aktuellen Users
     * (bereits Cascade-erweitert). 'all' wenn unbeschränkt (Admin oder
     * visibleBaustellen='all').
     */
    public static function getEffectiveVisibility(\PDO $db): mixed
    {
        if (($_SESSION['role'] ?? 'normal') === 'admin') return 'all';
        $username = $_SESSION['username'] ?? '';
        if ($username === '') return 'all';
        $stmt = $db->prepare("SELECT visibleBaustellen FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $vb = $stmt->fetchColumn();
        if ($vb === false || $vb === 'all' || $vb === null) return 'all';
        $list = json_decode((string)$vb, true);
        if (!is_array($list)) return 'all';
        return self::expandVisibleBaustellenIds($db, $list);
    }

    /**
     * Prüft, ob der aktuelle User auf eine bestimmte Baustelle zugreifen
     * darf (nach Cascade). Admin und 'all'-Sichtbarkeit dürfen immer.
     */
    public static function canSeeBaustelle(\PDO $db, int $baustelleId): bool
    {
        $vis = self::getEffectiveVisibility($db);
        if ($vis === 'all') return true;
        if (!is_array($vis)) return true;
        return in_array($baustelleId, $vis, true);
    }
}
