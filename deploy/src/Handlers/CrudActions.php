<?php
namespace App\Handlers;

use App\Auth;
use App\Database;
use App\Services\AuditService;

class CrudActions
{
    public function __construct(private \PDO $db, private array $body) {}

    // ══════════════════════════════════════════════════════════
    // KUNDEN
    // ══════════════════════════════════════════════════════════

    public function loadKunden(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canReadKunden')) jsonOut(['error' => 'Keine Berechtigung.'], 403);
        $this->ensureKundennummernAutofill();
        $rows = $this->db->query("SELECT * FROM kunden ORDER BY id")->fetchAll();
        $kunden = array_map(fn($r) => [
            'id' => (int)$r['id'], 'firma' => $r['firma'], 'anrede' => $r['anrede'],
            'vorname' => $r['vorname'], 'nachname' => $r['nachname'],
            'strasse' => $r['strasse'], 'plz' => $r['plz'], 'ort' => $r['ort'],
            'telefon' => $r['telefon'], 'mobil' => $r['mobil'], 'email' => $r['email'],
            'notizen' => $r['notizen'], 'erstellt' => $r['erstellt'], 'geaendert' => $r['geaendert'],
            'kundennummer' => $r['kundennummer'] ?? '',
        ], $rows);
        jsonOut(['ok' => true, 'kunden' => $kunden]);
    }

    public function saveKunde(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canWriteKunden')) jsonOut(['error' => 'Keine Berechtigung.'], 403);

        $kunde = $this->body;
        if (empty(trim($kunde['nachname'] ?? '')) && empty(trim($kunde['firma'] ?? ''))) {
            jsonOut(['error' => 'Firma oder Nachname ist erforderlich.'], 400);
        }

        $allowed = ['firma','anrede','vorname','nachname','strasse','plz','ort','telefon','mobil','email','notizen','kundennummer'];
        $clean = [];
        foreach ($allowed as $f) $clean[$f] = trim($kunde[$f] ?? '');
        $clean['geaendert'] = date('Y-m-d H:i');

        $settings = Auth::loadSettings($this->db);
        $kundennummerAuto = (bool)($settings['kundennummer_auto'] ?? true);
        $kundennummerLen = max(1, min(12, (int)($settings['kundennummer_stellen'] ?? 6)));
        if (!$kundennummerAuto && $clean['kundennummer'] === '') {
            jsonOut(['error' => 'Kundennummer ist erforderlich (Auto-Vergabe ist deaktiviert).'], 400);
        }

        $existingId = (int)($kunde['id'] ?? 0);
        if ($existingId > 0) {
            $existing = Database::fetchOne($this->db, "SELECT erstellt, kundennummer FROM kunden WHERE id = ?", [$existingId]);
            if (!$existing) jsonOut(['error' => 'Kunde nicht gefunden.'], 404);
            $clean['erstellt'] = $existing['erstellt'];
            $clean['id'] = $existingId;

            if ($kundennummerAuto) {
                if (trim((string)($existing['kundennummer'] ?? '')) === '') {
                    $clean['kundennummer'] = $this->nextFormattedNumber('kunden', 'kundennummer', $kundennummerLen);
                } else {
                    $clean['kundennummer'] = trim((string)$existing['kundennummer']);
                }
            } elseif ($clean['kundennummer'] === '') {
                $clean['kundennummer'] = trim((string)($existing['kundennummer'] ?? ''));
            }

            $this->db->prepare(
                "UPDATE kunden SET firma=?, anrede=?, vorname=?, nachname=?, strasse=?, plz=?, ort=?, telefon=?, mobil=?, email=?, notizen=?, geaendert=?, kundennummer=? WHERE id=?"
            )->execute([$clean['firma'], $clean['anrede'], $clean['vorname'], $clean['nachname'],
                        $clean['strasse'], $clean['plz'], $clean['ort'], $clean['telefon'],
                        $clean['mobil'], $clean['email'], $clean['notizen'], $clean['geaendert'], $clean['kundennummer'], $existingId]);
        } else {
            $clean['erstellt'] = date('Y-m-d H:i');
            // M7 (v1.8.0): Race-Condition-Schutz für Auto-Kundennummer.
            // Vergabe + INSERT laufen in einer SQLite IMMEDIATE-Transaktion;
            // bei UNIQUE-Konflikt (theoretisch) bis zu 3x neu versuchen.
            $needsAuto = ($kundennummerAuto || $clean['kundennummer'] === '');
            $insertSql = "INSERT INTO kunden (firma, anrede, vorname, nachname, strasse, plz, ort, telefon, mobil, email, notizen, erstellt, geaendert, kundennummer) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $tries = 0;
            while (true) {
                $tries++;
                try {
                    $this->db->exec("BEGIN IMMEDIATE");
                    if ($needsAuto) {
                        $clean['kundennummer'] = $this->nextFormattedNumber('kunden', 'kundennummer', $kundennummerLen);
                    }
                    $this->db->prepare($insertSql)->execute([
                        $clean['firma'], $clean['anrede'], $clean['vorname'], $clean['nachname'],
                        $clean['strasse'], $clean['plz'], $clean['ort'], $clean['telefon'],
                        $clean['mobil'], $clean['email'], $clean['notizen'],
                        $clean['erstellt'], $clean['geaendert'], $clean['kundennummer']
                    ]);
                    $clean['id'] = (int)$this->db->lastInsertId();
                    $this->db->exec("COMMIT");
                    break;
                } catch (\Throwable $e) {
                    @$this->db->exec("ROLLBACK");
                    if ($tries >= 3) throw $e;
                    usleep(50_000); // 50ms backoff
                }
            }
        }

        AuditService::log($existingId > 0 ? 'kunde_update' : 'kunde_create', 'ID=' . $clean['id'] . ', ' . trim(($clean['firma'] ?: '') . ' ' . $clean['vorname'] . ' ' . $clean['nachname']));

        // Kompatibilität: "data" mit allen Kunden zurückgeben
        $allKunden = $this->db->query("SELECT * FROM kunden ORDER BY id")->fetchAll();
        $kundenData = ['nextKundeId' => ((int)$this->db->query("SELECT COALESCE(MAX(id),0)+1 FROM kunden")->fetchColumn()), 'kunden' => $allKunden];
        jsonOut(['ok' => true, 'kunde' => $clean, 'data' => $kundenData]);
    }

    public function deleteKunde(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canWriteKunden')) jsonOut(['error' => 'Keine Berechtigung.'], 403);
        $delId = (int)($this->body['id'] ?? 0);
        if ($delId <= 0) jsonOut(['error' => 'Ungültige ID.'], 400);
        $cnt = $this->db->prepare("DELETE FROM kunden WHERE id = ?");
        $cnt->execute([$delId]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Kunde nicht gefunden.'], 404);
        AuditService::log('kunde_delete', 'ID=' . $delId);
        jsonOut(['ok' => true]);
    }

    /** DSGVO Art. 20 – Kundendaten als JSON exportieren (Datenportabilität) */
    public function exportKunde(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canWriteKunden')) jsonOut(['error' => 'Keine Berechtigung.'], 403);
        $id = (int)($this->body['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) jsonOut(['error' => 'Ungültige ID.'], 400);

        $kunde = Database::fetchOne($this->db, "SELECT * FROM kunden WHERE id = ?", [$id]);
        if (!$kunde) jsonOut(['error' => 'Kunde nicht gefunden.'], 404);

        // Zugehörige Rechnungen/Angebote sammeln
        $rechnungen = $this->db->prepare("SELECT id, typ, nummer, datum, status, positionen, notizen FROM rechnungen WHERE kundeId = ?");
        $rechnungen->execute([$id]);
        $reData = $rechnungen->fetchAll();

        $export = [
            'exportDatum' => date('c'),
            'exportTyp'   => 'DSGVO Art. 20 Datenportabilität',
            'kunde'       => [
                'firma'    => $kunde['firma'],
                'anrede'   => $kunde['anrede'],
                'vorname'  => $kunde['vorname'],
                'nachname' => $kunde['nachname'],
                'strasse'  => $kunde['strasse'],
                'plz'      => $kunde['plz'],
                'ort'      => $kunde['ort'],
                'telefon'  => $kunde['telefon'],
                'mobil'    => $kunde['mobil'],
                'email'    => $kunde['email'],
                'notizen'  => $kunde['notizen'],
                'erstellt' => $kunde['erstellt'],
                'geaendert'=> $kunde['geaendert'],
            ],
            'rechnungen'  => array_map(fn($r) => [
                'typ'     => $r['typ'],
                'nummer'  => $r['nummer'],
                'datum'   => $r['datum'],
                'status'  => $r['status'],
            ], $reData),
        ];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="kunde_' . $id . '_dsgvo_export.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** DSGVO Art. 17 – Kundendaten vollständig löschen (Recht auf Löschung) */
    public function dsgvoDeleteKunde(): void
    {
        Auth::requireRole('admin');
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) jsonOut(['error' => 'Ungültige ID.'], 400);

        $kunde = Database::fetchOne($this->db, "SELECT * FROM kunden WHERE id = ?", [$id]);
        if (!$kunde) jsonOut(['error' => 'Kunde nicht gefunden.'], 404);

        // Kundendaten aus Rechnungen anonymisieren (Aufbewahrungspflicht: Rechnungsdaten 10 Jahre)
        $this->db->prepare("UPDATE rechnungen SET absender = JSON_SET(COALESCE(absender,'{}'), '$.kundeAnonymisiert', 1) WHERE kundeId = ?")->execute([$id]);
        $this->db->prepare("UPDATE rechnungen SET kundeId = NULL WHERE kundeId = ?")->execute([$id]);

        // Kundenreferenz aus Baustellen entfernen
        // Baustellen speichern kundeId im JSON-Feld
        $baustellen = $this->db->query("SELECT id, data FROM baustellen")->fetchAll();
        foreach ($baustellen as $b) {
            $data = json_decode($b['data'], true);
            if ($data && isset($data['kundeId']) && (int)$data['kundeId'] === $id) {
                $data['kundeId'] = null;
                $this->db->prepare("UPDATE baustellen SET data = ? WHERE id = ?")->execute([
                    json_encode($data, JSON_UNESCAPED_UNICODE), $b['id']
                ]);
            }
        }

        // Kunden-Datensatz löschen
        $this->db->prepare("DELETE FROM kunden WHERE id = ?")->execute([$id]);

        // Audit-Log
        AuditService::log('dsgvo_delete_kunde', 'Kundendaten gelöscht (DSGVO Art. 17): ID=' . $id . ', Name=' . trim(($kunde['firma'] ?: '') . ' ' . ($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? '')));

        jsonOut(['ok' => true, 'message' => 'Kundendaten vollständig gelöscht (DSGVO Art. 17).']);
    }

    // logAudit → AuditService::log()

    // ══════════════════════════════════════════════════════════
    // DIENSTLEISTER
    // ══════════════════════════════════════════════════════════

    public function listDienstleister(): void
    {
        Auth::requireAuth();
        $rows = $this->db->query("SELECT * FROM dienstleister ORDER BY id")->fetchAll();
        $list = array_map(fn($r) => [
            'id' => (int)$r['id'], 'firma' => $r['firma'], 'kontakt' => $r['kontakt'],
            'telefon' => $r['telefon'], 'email' => $r['email'], 'notizen' => $r['notizen'],
        ], $rows);
        jsonOut(['ok' => true, 'dienstleister' => $list]);
    }

    public function addDienstleister(): void
    {
        Auth::requireRole('admin');
        $firma = trim($this->body['firma'] ?? '');
        if ($firma === '') jsonOut(['error' => 'Firmenname ist erforderlich.'], 400);
        $this->db->prepare("INSERT INTO dienstleister (firma, kontakt, telefon, email, notizen) VALUES (?,?,?,?,?)")
                  ->execute([$firma, trim($this->body['kontakt'] ?? ''), trim($this->body['telefon'] ?? ''), trim($this->body['email'] ?? ''), trim($this->body['notizen'] ?? '')]);
        $id = (int)$this->db->lastInsertId();
        $dl = Database::fetchOne($this->db, "SELECT * FROM dienstleister WHERE id = ?", [$id]);
        $dl['id'] = (int)$dl['id'];
        jsonOut(['ok' => true, 'dienstleister' => $dl]);
    }

    public function editDienstleister(): void
    {
        Auth::requireRole('admin');
        $dlId = (int)($this->body['id'] ?? 0);
        if ($dlId <= 0) jsonOut(['error' => 'Ungültige ID.'], 400);
        $sets = []; $params = [];
        foreach (['firma','kontakt','telefon','email','notizen'] as $f) {
            if (isset($this->body[$f])) { $sets[] = "$f = ?"; $params[] = trim($this->body[$f]); }
        }
        if (empty($sets)) jsonOut(['ok' => true]);
        $params[] = $dlId;
        $cnt = $this->db->prepare("UPDATE dienstleister SET " . implode(', ', $sets) . " WHERE id = ?");
        $cnt->execute($params);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Dienstleister nicht gefunden.'], 404);
        jsonOut(['ok' => true]);
    }

    public function deleteDienstleister(): void
    {
        Auth::requireRole('admin');
        $dlId = (int)($this->body['id'] ?? 0);
        if ($dlId <= 0) jsonOut(['error' => 'Ungültige ID.'], 400);
        $cnt = $this->db->prepare("DELETE FROM dienstleister WHERE id = ?");
        $cnt->execute([$dlId]);
        if ($cnt->rowCount() === 0) jsonOut(['error' => 'Dienstleister nicht gefunden.'], 404);
        // User-Zuordnungen entfernen
        $this->db->prepare("UPDATE users SET dienstleisterId = NULL, isSubunternehmer = 0 WHERE dienstleisterId = ?")
                  ->execute([$dlId]);
        jsonOut(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════
    // RECHNUNGEN & ANGEBOTE
    // ══════════════════════════════════════════════════════════

    public function listRechnungen(): void
    {
        Auth::requireAuth();
        $canSeeRe = Auth::canDo($this->db, 'canSeeRechnungen') || Auth::canDo($this->db, 'canManageRechnungen');
        $canSeeAn = Auth::canDo($this->db, 'canSeeAngebote') || Auth::canDo($this->db, 'canManageAngebote');
        if (!$canSeeRe && !$canSeeAn) jsonOut(['error' => 'Keine Berechtigung.'], 403);

        $kundeId     = (int)($_GET['kundeId'] ?? $this->body['kundeId'] ?? 0);
        $baustelleId = (int)($_GET['baustelleId'] ?? $this->body['baustelleId'] ?? 0);

        $sql = "SELECT r.*, k.firma AS kundeFirma, k.vorname AS kundeVorname, k.nachname AS kundeNachname, k.kundennummer AS kundeNummer, b.name AS baustelleNameRaw, b.data AS baustelleData
                FROM rechnungen r
                LEFT JOIN kunden k ON k.id = r.kundeId
                LEFT JOIN baustellen b ON b.id = r.baustelleId
                WHERE 1=1";
        $params = [];
        $allowedTyps = [];
        if ($canSeeRe) $allowedTyps[] = 'rechnung';
        if ($canSeeAn) $allowedTyps[] = 'angebot';
        if (count($allowedTyps) === 1) {
            $sql .= " AND r.typ = ?";
            $params[] = $allowedTyps[0];
        } else {
            $sql .= " AND r.typ IN (?,?)";
            $params[] = $allowedTyps[0];
            $params[] = $allowedTyps[1];
        }
        if ($kundeId)     { $sql .= " AND kundeId = ?";     $params[] = $kundeId; }
        if ($baustelleId) { $sql .= " AND baustelleId = ?"; $params[] = $baustelleId; }
        $sql .= " ORDER BY r.createdAt DESC";

        $rows = Database::fetchAll($this->db, $sql, $params);
        $all = array_map(fn($r) => $this->castRechnung($r), $rows);
        jsonOut(['ok' => true, 'rechnungen' => $all]);
    }

    public function saveRechnung(): void
    {
        Auth::requireAuth();
        $rechnung = $this->body['rechnung'] ?? [];
        if (empty($rechnung['typ']) || !in_array($rechnung['typ'], ['rechnung', 'angebot'])) {
            jsonOut(['error' => 'Ungültiger Typ.'], 400);
        }
        if ($rechnung['typ'] === 'rechnung' && !Auth::canDo($this->db, 'canManageRechnungen')) {
            jsonOut(['error' => 'Keine Berechtigung für Rechnungen.'], 403);
        }
        if ($rechnung['typ'] === 'angebot' && !Auth::canDo($this->db, 'canManageAngebote')) {
            jsonOut(['error' => 'Keine Berechtigung für Angebote.'], 403);
        }

        $settings = Auth::loadSettings($this->db);

        if (isset($rechnung['id']) && $rechnung['id']) {
            // Update
            $existing = Database::fetchOne($this->db, "SELECT * FROM rechnungen WHERE id = ?", [$rechnung['id']]);
            if (!$existing) jsonOut(['error' => 'Nicht gefunden.'], 404);
            $merged = array_merge(json_decode(json_encode($existing), true), $rechnung);
            $merged['updatedAt'] = date('c');
            $this->db->prepare(
                "UPDATE rechnungen SET typ=?, nummer=?, kundeId=?, baustelleId=?, datum=?, faelligAm=?, status=?, absender=?, positionen=?, notizen=?, beschreibung=?, zahlungsziel=?, updatedAt=? WHERE id=?"
            )->execute([
                $merged['typ'], $merged['nummer'], $merged['kundeId'] ?? null, $merged['baustelleId'] ?? null,
                $merged['datum'] ?? '', $merged['faelligAm'] ?? '', $merged['status'] ?? 'offen',
                is_string($merged['absender']) ? $merged['absender'] : json_encode($merged['absender'] ?? [], JSON_UNESCAPED_UNICODE),
                is_string($merged['positionen']) ? $merged['positionen'] : json_encode($merged['positionen'] ?? [], JSON_UNESCAPED_UNICODE),
                $merged['notizen'] ?? '', $merged['beschreibung'] ?? '', $merged['zahlungsziel'] ?? '', $merged['updatedAt'], $rechnung['id'],
            ]);
            $rechnung = $merged;
        } else {
            // Neu
            $prefix = $rechnung['typ'] === 'rechnung' ? 'RE' : 'AN';
            $year   = date('Y');
            $rows = Database::fetchAll($this->db, "SELECT nummer FROM rechnungen WHERE nummer LIKE ?", [$prefix . '-' . $year . '-%']);
            $maxNum = 0;
            foreach ($rows as $row) {
                if (preg_match('/^' . $prefix . '-' . $year . '-(\d+)$/', $row['nummer'], $m)) {
                    $maxNum = max($maxNum, (int)$m[1]);
                }
            }

            $rechnung['id']        = time() . rand(100, 999);
            $rechnung['nummer']    = $prefix . '-' . $year . '-' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
            $rechnung['createdAt'] = date('c');
            $rechnung['createdBy'] = $_SESSION['username'] ?? '';
            $rechnung['beschreibung'] = trim((string)($rechnung['beschreibung'] ?? ''));
            $rechnung['absender']  = [
                'firma'    => $settings['firma_name'] ?? '',
                'strasse'  => $settings['firma_strasse'] ?? '',
                'plz'      => $settings['firma_plz'] ?? '',
                'ort'      => $settings['firma_ort'] ?? '',
                'telefon'  => $settings['firma_telefon'] ?? '',
                'email'    => $settings['firma_email'] ?? '',
                'steuernr' => $settings['firma_steuernr'] ?? '',
                'ustid'    => $settings['firma_ustid'] ?? '',
                'bankname' => $settings['firma_bankname'] ?? '',
                'iban'     => $settings['firma_iban'] ?? '',
                'bic'      => $settings['firma_bic'] ?? '',
            ];
            $this->db->prepare(
                "INSERT INTO rechnungen (id, typ, nummer, kundeId, baustelleId, datum, faelligAm, status, absender, positionen, notizen, beschreibung, zahlungsziel, createdAt, createdBy) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $rechnung['id'], $rechnung['typ'], $rechnung['nummer'],
                $rechnung['kundeId'] ?? null, $rechnung['baustelleId'] ?? null,
                $rechnung['datum'] ?? '', $rechnung['faelligAm'] ?? '', $rechnung['status'] ?? 'offen',
                json_encode($rechnung['absender'], JSON_UNESCAPED_UNICODE),
                is_string($rechnung['positionen'] ?? null) ? $rechnung['positionen'] : json_encode($rechnung['positionen'] ?? [], JSON_UNESCAPED_UNICODE),
                $rechnung['notizen'] ?? '', $rechnung['beschreibung'] ?? '', $rechnung['zahlungsziel'] ?? '',
                $rechnung['createdAt'], $rechnung['createdBy'],
            ]);
        }
        AuditService::log(isset($existing) ? 'rechnung_update' : 'rechnung_create', 'Nummer=' . ($rechnung['nummer'] ?? '') . ', Typ=' . ($rechnung['typ'] ?? ''));
        jsonOut(['ok' => true, 'rechnung' => $rechnung]);
    }

    public function deleteRechnung(): void
    {
        Auth::requireAuth();
        $delId = $this->body['id'] ?? '';
        $existing = Database::fetchOne($this->db, "SELECT typ FROM rechnungen WHERE id = ?", [$delId]);
        if (!$existing) jsonOut(['error' => 'Nicht gefunden.'], 404);
        if ($existing['typ'] === 'rechnung' && !Auth::canDo($this->db, 'canManageRechnungen')) {
            jsonOut(['error' => 'Keine Berechtigung für Rechnungen.'], 403);
        }
        if ($existing['typ'] === 'angebot' && !Auth::canDo($this->db, 'canManageAngebote')) {
            jsonOut(['error' => 'Keine Berechtigung für Angebote.'], 403);
        }
        $this->db->prepare("DELETE FROM rechnungen WHERE id = ?")->execute([$delId]);
        AuditService::log('rechnung_delete', 'ID=' . $delId);
        jsonOut(['ok' => true]);
    }

    // ── Hilfsfunktion ────────────────────────────────────────
    private function castRechnung(array $r): array
    {
        $projektNr = '';
        if (!empty($r['baustelleData'])) {
            $bd = json_decode((string)$r['baustelleData'], true) ?: [];
            $projektNr = trim((string)($bd['projektNr'] ?? ''));
        }
        $kundeName = trim((string)($r['kundeFirma'] ?? ''));
        if ($kundeName === '') {
            $kundeName = trim(trim((string)($r['kundeVorname'] ?? '')) . ' ' . trim((string)($r['kundeNachname'] ?? '')));
        }
        return [
            'id'           => $r['id'],
            'typ'          => $r['typ'],
            'nummer'       => $r['nummer'],
            'kundeId'      => $r['kundeId'] !== null ? (int)$r['kundeId'] : null,
            'baustelleId'  => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
            'kundeName'    => $kundeName,
            'kundeNummer'  => trim((string)($r['kundeNummer'] ?? '')),
            'baustelleName'=> trim((string)($r['baustelleNameRaw'] ?? '')),
            'projektNr'    => $projektNr,
            'datum'        => $r['datum'],
            'faelligAm'    => $r['faelligAm'],
            'status'       => $r['status'],
            'absender'     => json_decode($r['absender'] ?? '{}', true) ?: [],
            'positionen'   => json_decode($r['positionen'] ?? '[]', true) ?: [],
            'notizen'      => $r['notizen'] ?? '',
            'beschreibung' => $r['beschreibung'] ?? '',
            'zahlungsziel' => $r['zahlungsziel'] ?? '',
            'createdAt'    => $r['createdAt'],
            'createdBy'    => $r['createdBy'],
            'updatedAt'    => $r['updatedAt'],
        ];
    }

    private function nextFormattedNumber(string $table, string $column, int $length): string
    {
        $rows = Database::fetchAll($this->db, "SELECT {$column} AS no FROM {$table} WHERE TRIM(COALESCE({$column}, '')) <> ''");
        $max = 0;
        foreach ($rows as $r) {
            $digits = preg_replace('/\D+/', '', (string)($r['no'] ?? ''));
            if ($digits === '') continue;
            $num = (int)$digits;
            if ($num > $max) $max = $num;
        }
        return str_pad((string)($max + 1), $length, '0', STR_PAD_LEFT);
    }

    private function ensureKundennummernAutofill(): void
    {
        $settings = Auth::loadSettings($this->db);
        $auto = (bool)($settings['kundennummer_auto'] ?? true);
        if (!$auto) return;
        $length = max(1, min(12, (int)($settings['kundennummer_stellen'] ?? 6)));
        $rows = Database::fetchAll($this->db, "SELECT id FROM kunden WHERE TRIM(COALESCE(kundennummer, '')) = '' ORDER BY id");
        if (!$rows) return;
        foreach ($rows as $row) {
            $next = $this->nextFormattedNumber('kunden', 'kundennummer', $length);
            $this->db->prepare("UPDATE kunden SET kundennummer = ? WHERE id = ?")->execute([$next, (int)$row['id']]);
        }
    }
}
