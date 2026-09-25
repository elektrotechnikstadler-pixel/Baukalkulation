<?php
namespace App\Handlers;

use App\Auth;
use App\Database;
use App\DataService;

class PlanActions
{
    public function __construct(private \PDO $db, private array $body) {}

    // ══════════════════════════════════════════════════════════
    // WOCHENPLANUNG
    // ══════════════════════════════════════════════════════════

    public function saveWochenplanung(): void
    {
        Auth::requireAuth();
        $newPlan = $this->body['plan'] ?? [];
        $username = $_SESSION['username'];
        $role     = $_SESSION['role'] ?? 'normal';
        $isAdmin  = in_array($role, ['admin', 'master']);

        // ── Datenverlust-Schutz: destruktives Full-Replace abfangen ──
        // saveWochenplanung ersetzt den kompletten Plan (Admin: ALLER User). Ein
        // veralteter/leerer Client-Payload (fehlgeschlagenes Laden, zweites Gerät)
        // würde sonst die gesamte Wochenplanung überschreiben. Bei drastischer
        // Schrumpfung ohne Bestätigung (forceReplace) wird NICHTS verändert.
        $forceReplace = !empty($this->body['forceReplace']);
        $inCount = 0;
        foreach ($newPlan as $planUser => $dates) {
            if (!$isAdmin && $planUser !== $username) continue;
            if (!is_array($dates)) continue;
            foreach ($dates as $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (is_array($entry)) $inCount++;
                }
            }
        }
        $dbCount = $isAdmin
            ? (int)$this->db->query("SELECT COUNT(*) FROM wochenplanung")->fetchColumn()
            : (int)(Database::fetchOne($this->db, "SELECT COUNT(*) AS c FROM wochenplanung WHERE username = ?", [$username])['c'] ?? 0);
        if (!$forceReplace && $dbCount > 0 && $inCount < $dbCount) {
            $deletes = $dbCount - $inCount;
            $drastic = ($inCount === 0 && $dbCount >= 3) || ($deletes >= 5 && $inCount < $dbCount / 2);
            if ($drastic) {
                jsonOut([
                    'error'        => "Speichern abgebrochen: Es würden {$deletes} von {$dbCount} Wochenplan-Einträgen gelöscht. "
                                    . "Vermutlich ist der Plan nicht vollständig geladen – es wurde NICHTS verändert.",
                    'needsConfirm' => true,
                ], 409);
            }
        }

        $this->db->beginTransaction();
        try {
            if ($isAdmin) {
                $this->db->exec("DELETE FROM wochenplanung");
            } else {
                $this->db->prepare("DELETE FROM wochenplanung WHERE username = ?")->execute([$username]);
            }

            $stmt = $this->db->prepare("INSERT INTO wochenplanung (username, datum, typ, baustelleId, bemerkung, betriebTyp, position) VALUES (?,?,?,?,?,?,?)");
            foreach ($newPlan as $user => $dates) {
                if (!$isAdmin && $user !== $username) continue;
                foreach ($dates as $datum => $entries) {
                    if (!is_array($entries)) continue;
                    foreach ($entries as $pos => $entry) {
                        if (!is_array($entry)) continue;
                        $stmt->execute([$user, $datum, $entry['typ'] ?? '', $entry['baustelleId'] ?? null, $entry['bemerkung'] ?? '', $entry['betriebTyp'] ?? '', (int)$pos]);
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            jsonOut(['error' => 'Wochenplanung konnte nicht gespeichert werden.'], 500);
        }
        jsonOut(['ok' => true]);
    }

    public function loadWochenplanung(): void
    {
        Auth::requireAuth();
        jsonOut(['ok' => true, 'plan' => $this->buildPlanStructure()]);
    }

    public function loadWochenplanungDisplay(): void
    {
        // Öffentlich (kein Auth) – nur read-only
        $plan = $this->buildPlanStructure();
        $userList = array_values(array_map(
            fn($u) => ['username' => $u['username']],
            array_filter(
                $this->db->query("SELECT username, showInWochenplanung FROM users")->fetchAll(),
                fn($u) => (bool)$u['showInWochenplanung']
            )
        ));
        $bNames = [];
        foreach ($this->db->query("SELECT id, name FROM baustellen")->fetchAll() as $b) {
            $bNames[(int)$b['id']] = $b['name'];
        }
        // Zeiterfassung laden
        $zeRows = $this->db->query("SELECT username, entryId, datum, typ, baustelleId, stunden, bemerkung FROM zeiterfassung ORDER BY username, datum, pk")->fetchAll();
        $zeData = [];
        foreach ($zeRows as $r) {
            $u = $r['username'];
            if (!isset($zeData[$u])) $zeData[$u] = ['entries' => [], 'nextId' => 1];
            $entry = [
                'id' => $r['entryId'] !== null ? (int)$r['entryId'] : null,
                'datum' => $r['datum'], 'typ' => $r['typ'],
                'baustelleId' => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
                'stunden' => (float)$r['stunden'], 'bemerkung' => $r['bemerkung'],
            ];
            $zeData[$u]['entries'][] = $entry;
            if ($entry['id'] !== null && $entry['id'] >= $zeData[$u]['nextId']) $zeData[$u]['nextId'] = $entry['id'] + 1;
        }
        $settings        = \App\Auth::loadSettings($this->db);
        $customFeiertage = [];
        if (!empty($settings['custom_feiertage'])) {
            $decoded = json_decode($settings['custom_feiertage'], true);
            if (is_array($decoded)) $customFeiertage = $decoded;
        }
        jsonOut(['ok' => true, 'plan' => $plan, 'users' => $userList, 'baustellenNamen' => $bNames, 'zeiterfassung' => $zeData, 'custom_feiertage' => $customFeiertage]);
    }

    public function getCalToken(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'];
        $role     = $_SESSION['role'] ?? 'normal';

        // Optionaler Personen-Filter: nur zulässig, wenn der Aufrufer alle Daten
        // sehen darf (sonst enthält der Feed ohnehin nur die eigenen Einträge).
        $reqUsers   = $this->body['users'] ?? null;
        $filterJson = '';
        if (is_array($reqUsers)) {
            $perms   = Auth::loadPermissions($this->db);
            $myPerms = ($role === 'admin') ? ['canSeeAllWochenplan' => true, 'canSeeAllTermine' => true] : ($perms[$role] ?? []);
            $canAll  = ($role === 'admin') || !empty($myPerms['canSeeAllWochenplan']) || !empty($myPerms['canSeeAllTermine']);
            if ($canAll) {
                $clean = array_values(array_unique(array_filter(array_map('strval', $reqUsers), fn($u) => $u !== '')));
                $filterJson = $clean ? json_encode(['users' => $clean], JSON_UNESCAPED_UNICODE) : '';
            }
        }

        $existing = Database::fetchOne($this->db, "SELECT token, filter FROM cal_tokens WHERE username = ?", [$username]);
        if ($existing) {
            if ($reqUsers !== null) {
                $this->db->prepare("UPDATE cal_tokens SET filter = ? WHERE username = ?")->execute([$filterJson, $username]);
            } else {
                $filterJson = $existing['filter'] ?? '';
            }
            jsonOut(['ok' => true, 'token' => $existing['token'], 'filterUsers' => $this->_parseFilterUsers($filterJson)]);
        }
        $token = bin2hex(random_bytes(32));
        $this->db->prepare("INSERT INTO cal_tokens (token, username, role, created, filter) VALUES (?,?,?,?,?)")
                  ->execute([$token, $username, $role, date('c'), $filterJson]);
        jsonOut(['ok' => true, 'token' => $token, 'filterUsers' => $this->_parseFilterUsers($filterJson)]);
    }

    /** Extrahiert die Nutzerliste aus dem gespeicherten Filter-JSON. */
    private function _parseFilterUsers(string $filterJson): array
    {
        $fj = json_decode($filterJson ?: '', true);
        return (is_array($fj) && !empty($fj['users']) && is_array($fj['users']))
            ? array_map('strval', $fj['users'])
            : [];
    }

    public function exportIcal(): void
    {
        $token = trim($_GET['token'] ?? '');
        if (!$token) { http_response_code(400); echo 'Bad Request: token missing'; exit; }
        $tokenInfo = Database::fetchOne($this->db, "SELECT username, role, filter FROM cal_tokens WHERE token = ?", [$token]);
        if (!$tokenInfo) { http_response_code(403); echo 'Forbidden: invalid token'; exit; }

        $calUser = $tokenInfo['username'];
        $calRole = $tokenInfo['role'] ?? 'normal';
        // Personen-Filter (nur relevant, wenn der Token-Nutzer alle Daten sieht)
        $filterUsers = $this->_parseFilterUsers($tokenInfo['filter'] ?? '');
        $passUser    = fn(string $u): bool => empty($filterUsers) || in_array($u, $filterUsers, true);
        $plan    = $this->buildPlanStructure();

        $bNames = [];
        foreach ($this->db->query("SELECT id, name FROM baustellen")->fetchAll() as $b) {
            $bNames[(int)$b['id']] = $b['name'];
        }

        $perms     = Auth::loadPermissions($this->db);
        $userPerms = ($calRole === 'admin') ? ['canSeeAllWochenplan' => true] : ($perms[$calRole] ?? []);
        $seeAll    = ($calRole === 'admin') || !empty($userPerms['canSeeAllWochenplan']);

        $isDownload = !empty($_GET['download']);
        $disposition = $isDownload ? 'attachment; filename="kalender.ics"' : 'inline; filename="wochenplanung.ics"';
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: ' . $disposition);
        header('Cache-Control: no-cache, must-revalidate');

        // Hilfsfunktion: Zeile bauen, escapen und falten
        $line = fn(string $prop, string $val): string => icalFold($prop . ':' . icalEscape($val));
        $raw  = fn(string $l): string => icalFold($l);  // Zeile ohne weiteres Escaping

        // ── VCALENDAR-Header ────────────────────────────────────────────────────
        $cal  = "BEGIN:VCALENDAR\r\n";
        $cal .= "VERSION:2.0\r\n";
        $cal .= "PRODID:-//Baukalkulation ES//Wochenplanung//DE\r\n";
        $cal .= "X-WR-CALNAME:Baukalkulation\r\n";
        $cal .= "CALSCALE:GREGORIAN\r\n";
        $cal .= "METHOD:PUBLISH\r\n";

        // ── VTIMEZONE Europe/Berlin ──────────────────────────────────────────────
        $cal .= "BEGIN:VTIMEZONE\r\n";
        $cal .= "TZID:Europe/Berlin\r\n";
        $cal .= "X-LIC-LOCATION:Europe/Berlin\r\n";
        $cal .= "BEGIN:DAYLIGHT\r\n";
        $cal .= "TZOFFSETFROM:+0100\r\n";
        $cal .= "TZOFFSETTO:+0200\r\n";
        $cal .= "TZNAME:CEST\r\n";
        $cal .= "DTSTART:19700329T020000\r\n";
        $cal .= "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3\r\n";
        $cal .= "END:DAYLIGHT\r\n";
        $cal .= "BEGIN:STANDARD\r\n";
        $cal .= "TZOFFSETFROM:+0200\r\n";
        $cal .= "TZOFFSETTO:+0100\r\n";
        $cal .= "TZNAME:CET\r\n";
        $cal .= "DTSTART:19701025T030000\r\n";
        $cal .= "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\n";
        $cal .= "END:STANDARD\r\n";
        $cal .= "END:VTIMEZONE\r\n";

        $dtstamp = gmdate('Ymd\THis\Z');

        // ── Wochenplanung-Einträge ───────────────────────────────────────────────
        foreach ($plan as $uname => $dates) {
            if (!$seeAll && $uname !== $calUser) continue;
            if (!$passUser($uname)) continue;
            foreach ($dates as $dateKey => $entries) {
                if (!is_array($entries) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) continue;
                $dtStart = str_replace('-', '', $dateKey);
                $dtEnd   = date('Ymd', strtotime($dateKey . ' +1 day'));
                $idx = 0;
                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $typ = $entry['typ'] ?? '';
                    $summary = ''; $description = '';
                    if ($typ === 'baustelle') {
                        $bId     = (int)($entry['baustelleId'] ?? 0);
                        $summary = $bNames[$bId] ?? ('Baustelle #' . $bId);
                        if ($seeAll) $summary .= ' (' . $uname . ')';
                        if (!empty($entry['bemerkung'])) $description = $entry['bemerkung'];
                    } elseif ($typ === 'urlaub') {
                        $summary = 'Urlaub' . ($seeAll ? ' (' . $uname . ')' : '');
                    } elseif ($typ === 'sonstig') {
                        $summary = 'Sonstiges: ' . ($entry['bemerkung'] ?? '');
                        if ($seeAll) $summary .= ' (' . $uname . ')';
                    } else { $idx++; continue; }

                    $uid  = 'wp-' . md5($uname . '-' . $dateKey . '-' . $idx) . '@baukalkulation';
                    $cal .= "BEGIN:VEVENT\r\n";
                    $cal .= $raw("UID:{$uid}");
                    $cal .= $raw("DTSTART;VALUE=DATE:{$dtStart}");
                    $cal .= $raw("DTEND;VALUE=DATE:{$dtEnd}");
                    $cal .= $line("SUMMARY", $summary);
                    if ($description) $cal .= $line("DESCRIPTION", $description);
                    $cal .= "SEQUENCE:0\r\n";
                    $cal .= "DTSTAMP:{$dtstamp}\r\n";
                    $cal .= "END:VEVENT\r\n";
                    $idx++;
                }
            }
        }

        // ── Stundenerfassung: Urlaub / Krank / Arbeit ───────────────────────────
        $seStmt = $this->db->prepare(
            "SELECT username, datum, typ, baustelleId, SUM(stunden) AS stunden, "
             . \App\Database\Dialect::for($this->db)->groupConcat('bemerkung', "' / '") . " AS bemerkungen
             FROM zeiterfassung
             WHERE typ IN ('urlaub','krank','arbeit')
             " . (!$seeAll ? "AND username = ?" : "") . "
             GROUP BY username, datum, typ, baustelleId
             ORDER BY username, datum"
        );
        $seeAll ? $seStmt->execute() : $seStmt->execute([$calUser]);
        $seRows = $seStmt->fetchAll();

        foreach ($seRows as $r) {
            $uname   = $r['username'];
            if (!$passUser($uname)) continue;
            $typ     = $r['typ'];
            $dateKey = $r['datum'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) continue;

            $dtStart = str_replace('-', '', $dateKey);
            $dtEnd   = date('Ymd', strtotime($dateKey . ' +1 day'));

            if ($typ === 'urlaub') {
                $summary = 'Urlaub' . ($seeAll ? ' (' . $uname . ')' : '');
                $uid     = 'se-urlaub-' . md5($uname . '-' . $dateKey) . '@baukalkulation';
                $desc    = trim($r['bemerkungen'] ?? '');
            } elseif ($typ === 'krank') {
                $summary = 'Krank' . ($seeAll ? ' (' . $uname . ')' : '');
                $uid     = 'se-krank-' . md5($uname . '-' . $dateKey) . '@baukalkulation';
                $desc    = trim($r['bemerkungen'] ?? '');
            } elseif ($typ === 'arbeit') {
                $bId     = (int)($r['baustelleId'] ?? 0);
                $bName   = $bNames[$bId] ?? ('Baustelle #' . $bId);
                $stunden = round((float)$r['stunden'], 2);
                $summary = $bName . ' – ' . $stunden . 'h' . ($seeAll ? ' (' . $uname . ')' : '');
                $uid     = 'se-arbeit-' . md5($uname . '-' . $dateKey . '-' . $bId) . '@baukalkulation';
                $desc    = trim($r['bemerkungen'] ?? '');
            } else {
                continue;
            }

            $cal .= "BEGIN:VEVENT\r\n";
            $cal .= $raw("UID:{$uid}");
            $cal .= $raw("DTSTART;VALUE=DATE:{$dtStart}");
            $cal .= $raw("DTEND;VALUE=DATE:{$dtEnd}");
            $cal .= $line("SUMMARY", $summary);
            if ($desc) $cal .= $line("DESCRIPTION", $desc);
            $cal .= "SEQUENCE:0\r\n";
            $cal .= "DTSTAMP:{$dtstamp}\r\n";
            $cal .= "END:VEVENT\r\n";
        }

        // ── Termine als VEVENT ───────────────────────────────────────────────────
        $seeAllTermine = ($calRole === 'admin') || !empty($userPerms['canSeeAllTermine']);
        $seeOwnTermine = ($calRole === 'admin') || !empty($userPerms['canSeeOwnTermine']);
        if ($seeAllTermine || $seeOwnTermine) {
            $termine = $this->db->query("SELECT * FROM termine ORDER BY datum")->fetchAll();
            foreach ($termine as $t) {
                if (!$seeAllTermine) {
                    $zugewiesen = json_decode($t['zugewiesen'] ?: '[]', true) ?: [];
                    if ($t['ersteller'] !== $calUser && !in_array($calUser, $zugewiesen)) continue;
                }
                // Personen-Filter: Termin nur, wenn Ersteller oder ein Zugewiesener passt.
                if (!empty($filterUsers)) {
                    $zu = json_decode($t['zugewiesen'] ?: '[]', true) ?: [];
                    $match = in_array($t['ersteller'], $filterUsers, true);
                    foreach ($zu as $z) { if (in_array($z, $filterUsers, true)) { $match = true; break; } }
                    if (!$match) continue;
                }

                $uid     = 'termin-' . $t['id'] . '@baukalkulation';
                $summary = $t['titel'];

                // Beschreibung zusammenbauen
                $descParts = [];
                if (!empty($t['beschreibung'])) $descParts[] = $t['beschreibung'];
                $zuList = json_decode($t['zugewiesen'] ?: '[]', true) ?: [];
                if (!empty($zuList)) $descParts[] = 'Zugewiesen: ' . implode(', ', $zuList);
                if (!empty($t['ersteller'])) $descParts[] = 'Erstellt von: ' . $t['ersteller'];
                $description = implode("\n", $descParts);

                // Zeitstempel (LAST-MODIFIED)
                $lastMod = $t['erstelltAm']
                    ? gmdate('Ymd\THis\Z', strtotime($t['erstelltAm']))
                    : $dtstamp;

                $cal .= "BEGIN:VEVENT\r\n";
                $cal .= $raw("UID:{$uid}");

                if ($t['ganztags'] || empty($t['zeitVon'])) {
                    $dtStart = str_replace('-', '', $t['datum']);
                    $dtEnd   = date('Ymd', strtotime($t['datum'] . ' +1 day'));
                    $cal .= $raw("DTSTART;VALUE=DATE:{$dtStart}");
                    $cal .= $raw("DTEND;VALUE=DATE:{$dtEnd}");
                } else {
                    $tStart = str_replace('-', '', $t['datum']) . 'T' . str_replace(':', '', $t['zeitVon']) . '00';
                    $zeitBis = !empty($t['zeitBis']) ? $t['zeitBis'] : $t['zeitVon'];
                    $tEnd   = str_replace('-', '', $t['datum']) . 'T' . str_replace(':', '', $zeitBis) . '00';
                    $cal .= $raw("DTSTART;TZID=Europe/Berlin:{$tStart}");
                    $cal .= $raw("DTEND;TZID=Europe/Berlin:{$tEnd}");
                }

                $cal .= $line("SUMMARY", $summary);
                if ($description) $cal .= $line("DESCRIPTION", $description);
                if (!empty($t['ort'])) $cal .= $line("LOCATION", $t['ort']);
                $cal .= "SEQUENCE:0\r\n";
                $cal .= "DTSTAMP:{$dtstamp}\r\n";
                $cal .= "LAST-MODIFIED:{$lastMod}\r\n";

                // VALARM: 30 Minuten vor Terminen mit Uhrzeit
                if (!$t['ganztags'] && !empty($t['zeitVon'])) {
                    $cal .= "BEGIN:VALARM\r\n";
                    $cal .= "ACTION:DISPLAY\r\n";
                    $cal .= $line("DESCRIPTION", 'Erinnerung: ' . $t['titel']);
                    $cal .= "TRIGGER:-PT30M\r\n";
                    $cal .= "END:VALARM\r\n";
                }

                $cal .= "END:VEVENT\r\n";
            }
        }

        $cal .= "END:VCALENDAR\r\n";
        echo $cal;
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // SCHNELLNOTIZEN
    // ══════════════════════════════════════════════════════════

    public function loadSchnellnotizen(): void
    {
        Auth::requireAuth();
        $role = $_SESSION['role'] ?? 'normal';
        $user = $_SESSION['username'] ?? '';
        $baustelleId = $_GET['baustelleId'] ?? null;

        if ($baustelleId !== null) {
            // Baustelle notes view: all notes for this Baustelle
            $stmt = $this->db->prepare("SELECT * FROM schnellnotizen WHERE baustelleId = ? ORDER BY id");
            $stmt->execute([(int)$baustelleId]);
            $rows = $stmt->fetchAll();
        } elseif (in_array($role, ['admin', 'master'], true)) {
            $rows = $this->db->query("SELECT * FROM schnellnotizen ORDER BY id")->fetchAll();
        } else {
            // Normale User sehen eigene + ihnen zugewiesene + alle + Gruppeneinträge
            $gruppenStmt = $this->db->prepare(
                "SELECT gruppen_id FROM gruppen_mitglieder WHERE username = ?"
            );
            $gruppenStmt->execute([$user]);
            $gruppenIds = array_column($gruppenStmt->fetchAll(), 'gruppen_id');

            $placeholders = [];
            $params       = [$user, $user, 'alle'];
            foreach ($gruppenIds as $gid) {
                $placeholders[] = '?';
                $params[]       = 'gruppe:' . $gid;
            }
            $extraIn = count($placeholders) > 0
                ? ' OR zugewiesen_an IN (' . implode(',', $placeholders) . ')'
                : '';

            $stmt = $this->db->prepare(
                "SELECT * FROM schnellnotizen
                  WHERE ersteller = ? OR zugewiesen_an = ? OR zugewiesen_an = ?
                  {$extraIn}
                  ORDER BY id"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }
        $notes = array_map(fn($r) => [
            'id' => (int)$r['id'], 'text' => $r['text'],
            'baustelleId' => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
            'baustelleName' => $r['baustelleName'], 'ersteller' => $r['ersteller'],
            'kuerzel' => $r['kuerzel'], 'datum' => $r['datum'],
            'archiviert' => (bool)$r['archiviert'],
            'archiviertAm' => $r['archiviertAm'], 'archiviertVon' => $r['archiviertVon'],
            'zugewiesen_an' => $r['zugewiesen_an'] ?? '',
        ], $rows);
        jsonOut(['ok' => true, 'notes' => $notes]);
    }

    public function saveSchnellnotiz(): void
    {
        Auth::requireAuth();
        $text = trim($this->body['text'] ?? '');
        if ($text === '') jsonOut(['error' => 'Notiztext fehlt.'], 400);

        $role        = $_SESSION['role'] ?? 'normal';
        $canAssign   = in_array($role, ['admin', 'master'], true);
        $zugewiesen  = $canAssign ? trim($this->body['zugewiesen_an'] ?? '') : '';

        $this->db->prepare(
            "INSERT INTO schnellnotizen (text, baustelleId, baustelleName, ersteller, kuerzel, datum, archiviert, zugewiesen_an) VALUES (?,?,?,?,?,?,0,?)"
        )->execute([
            $text,
            $this->body['baustelleId'] ?? null,
            $this->body['baustelleName'] ?? '',
            $_SESSION['username'] ?? '',
            $_SESSION['kuerzel'] ?? '',
            date('Y-m-d H:i:s'),
            $zugewiesen,
        ]);
        $id = (int)$this->db->lastInsertId();
        $note = Database::fetchOne($this->db, "SELECT * FROM schnellnotizen WHERE id = ?", [$id]);
        $note['id'] = (int)$note['id'];
        $note['archiviert'] = (bool)$note['archiviert'];
        $note['baustelleId'] = $note['baustelleId'] !== null ? (int)$note['baustelleId'] : null;
        $note['zugewiesen_an'] = $note['zugewiesen_an'] ?? '';
        jsonOut(['ok' => true, 'note' => $note]);
    }

    public function archiveSchnellnotiz(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');
        $noteId = (int)($this->body['id'] ?? 0);
        $this->db->prepare("UPDATE schnellnotizen SET archiviert = 1, archiviertAm = ?, archiviertVon = ? WHERE id = ?")
                  ->execute([date('Y-m-d H:i:s'), $_SESSION['username'] ?? '', $noteId]);
        jsonOut(['ok' => true]);
    }

    public function archiveAllSchnellnotizen(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');
        $this->db->prepare("UPDATE schnellnotizen SET archiviert = 1, archiviertAm = ?, archiviertVon = ? WHERE archiviert = 0")
                  ->execute([date('Y-m-d H:i:s'), $_SESSION['username'] ?? '']);
        jsonOut(['ok' => true]);
    }

    public function deleteSchnellnotiz(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');
        $noteId = (int)($this->body['id'] ?? 0);
        $this->db->prepare("DELETE FROM schnellnotizen WHERE id = ?")->execute([$noteId]);
        jsonOut(['ok' => true]);
    }

    // ── Hilfsfunktion: Wochenplan aus DB rekonstruieren ──────
    private function buildPlanStructure(): array
    {
        $rows = $this->db->query("SELECT username, datum, typ, baustelleId, bemerkung, betriebTyp FROM wochenplanung ORDER BY username, datum, position")->fetchAll();
        $plan = [];
        foreach ($rows as $r) {
            $entry = ['typ' => $r['typ']];
            if ($r['baustelleId'] !== null) $entry['baustelleId'] = (int)$r['baustelleId'];
            if ($r['bemerkung'] !== '') $entry['bemerkung'] = $r['bemerkung'];
            if (!empty($r['betriebTyp'])) $entry['betriebTyp'] = $r['betriebTyp'];
            $plan[$r['username']][$r['datum']][] = $entry;
        }
        return $plan;
    }
}
