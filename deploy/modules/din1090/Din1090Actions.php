<?php
// ============================================================
// DIN EN 1090 – Modul: Business-Logic / API-Handler
// ============================================================

require_once __DIR__ . '/Din1090Database.php';

class Din1090Actions
{
    private \PDO   $db;
    private array  $body;
    private string $user;

    public function __construct(\PDO $db, array $body)
    {
        $this->db   = $db;
        $this->body = $body;
        $this->user = $_SESSION['username'] ?? '';
    }

    /* -------------------------------------------------------
     *  Dispatcher – wird von din1090_api.php aufgerufen
     * ----------------------------------------------------- */
    public function dispatch(): void
    {
        Din1090Database::init($this->db);

        $sub = $this->body['sub'] ?? '';

        // ── Berechtigungsprüfung (v2.8.8) ─────────────────────
        if (!\App\Auth::canDo($this->db, 'canReadDin1090')) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung: canReadDin1090']);
            return;
        }
        $writeActions = [
            'save_project','delete_project','save_material','delete_material',
            'save_welder','delete_welder','save_wps','delete_wps',
            'save_weld_log','delete_weld_log','save_inspection','delete_inspection',
            'save_ncr','delete_ncr','save_surface','delete_surface',
            'generate_checklist','update_checklist','copy_from_project',
        ];
        if (in_array($sub, $writeActions, true) && !\App\Auth::canDo($this->db, 'canWriteDin1090')) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung: canWriteDin1090']);
            return;
        }
        // ──────────────────────────────────────────────────────

        $map = [
            // Projekte
            'list_projects'   => 'listProjects',
            'get_project'     => 'getProject',
            'save_project'    => 'saveProject',
            'delete_project'  => 'deleteProject',
            // Material
            'list_materials'  => 'listMaterials',
            'save_material'   => 'saveMaterial',
            'delete_material' => 'deleteMaterial',
            // Schweißer
            'list_welders'    => 'listWelders',
            'save_welder'     => 'saveWelder',
            'delete_welder'   => 'deleteWelder',
            // WPS
            'list_wps'        => 'listWps',
            'save_wps'        => 'saveWps',
            'delete_wps'      => 'deleteWps',
            // Schweißprotokoll
            'list_weld_log'   => 'listWeldLog',
            'save_weld_log'   => 'saveWeldLog',
            'delete_weld_log' => 'deleteWeldLog',
            // Prüfungen
            'list_inspections'  => 'listInspections',
            'save_inspection'   => 'saveInspection',
            'delete_inspection' => 'deleteInspection',
            // NCR
            'list_ncr'        => 'listNcr',
            'save_ncr'        => 'saveNcr',
            'delete_ncr'      => 'deleteNcr',
            // Oberfläche
            'list_surface'    => 'listSurface',
            'save_surface'    => 'saveSurface',
            'delete_surface'  => 'deleteSurface',
            // Checklisten
            'get_checklists'    => 'getChecklists',
            'generate_checklist'=> 'generateChecklist',
            'update_checklist'  => 'updateChecklist',
            // Audit
            'get_audit_log'   => 'getAuditLog',
            // Dashboard
            'dashboard'       => 'dashboard',
            // Export
            'export_project'  => 'exportProject',
            // Kopieren aus anderem Projekt
            'copy_from_project' => 'copyFromProject',
        ];

        if (!isset($map[$sub])) {
            echo json_encode(['ok' => false, 'error' => "Unbekannte Sub-Aktion: $sub"]);
            return;
        }

        $method = $map[$sub];
        $this->$method();
    }

    // =========================================================
    //  PROJEKTE
    // =========================================================
    private function listProjects(): void
    {
        $rows = $this->db->query("
            SELECT p.*, b.name AS baustelle_name, b.data AS _bdata
            FROM din1090_projects p
            LEFT JOIN baustellen b ON b.id = p.baustelleId
            ORDER BY p.id DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $bd = json_decode($row['_bdata'] ?? '{}', true) ?: [];
            $row['projektNr'] = $bd['projektNr'] ?? '';
            unset($row['_bdata']);
        }
        unset($row);
        echo json_encode(['ok' => true, 'data' => $rows]);
    }

    private function getProject(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $row = $this->db->prepare("
            SELECT p.*, b.name AS baustelle_name, b.data AS _bdata
            FROM din1090_projects p
            LEFT JOIN baustellen b ON b.id = p.baustelleId
            WHERE p.id = ?
        ");
        $row->execute([$id]);
        $data = $row->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($data) {
            $bd = json_decode($data['_bdata'] ?? '{}', true) ?: [];
            $data['projektNr'] = $bd['projektNr'] ?? '';
            unset($data['_bdata']);
        }
        echo json_encode(['ok' => true, 'data' => $data]);
    }

    private function saveProject(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $fields = [
            'baustelleId', 'bezeichnung', 'ausfuehrungsklasse', 'werkstoff',
            'normen', 'verantwortlicher', 'schweissaufsicht', 'pruefstelle',
            'status', 'notizen'
        ];
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
                $sql  = "UPDATE din1090_projects SET $sets, aktualisiert_am = :now WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
                $stmt->bindValue(':now', $now);
                $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
                $stmt->execute();
                $this->audit($id, 'project_update', "Projekt #$id aktualisiert");
            } else {
                $cols = implode(', ', $fields) . ', erstellt_am, erstellt_von, aktualisiert_am';
                $vals = implode(', ', array_map(fn($f) => ":$f", $fields)) . ', :ea, :ev, :aa';
                $sql  = "INSERT INTO din1090_projects ($cols) VALUES ($vals)";
                $stmt = $this->db->prepare($sql);
                foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
                $stmt->bindValue(':ea', $now);
                $stmt->bindValue(':ev', $this->user);
                $stmt->bindValue(':aa', $now);
                $stmt->execute();
                $id = (int)$this->db->lastInsertId();
                $this->audit($id, 'project_create', "Projekt #$id erstellt");

                // Auto-Checkliste generieren
                $exc = (int)str_replace('EXC', '', $this->body['ausfuehrungsklasse'] ?? 'EXC2');
                $this->generateChecklistForProject($id, $exc);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        echo json_encode(['ok' => true, 'id' => $id]);
    }

    private function deleteProject(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $this->db->beginTransaction();
        try {
            // Alle abhängigen Daten löschen
            $tables = [
                'din1090_materials', 'din1090_wps', 'din1090_weld_log',
                'din1090_inspections', 'din1090_ncr', 'din1090_checklists',
                'din1090_surface', 'din1090_audit_log'
            ];
            foreach ($tables as $t) {
                $this->db->prepare("DELETE FROM $t WHERE projectId = ?")->execute([$id]);
            }
            $this->db->prepare("DELETE FROM din1090_projects WHERE id = ?")->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        echo json_encode(['ok' => true]);
    }

    // =========================================================
    //  MATERIAL
    // =========================================================
    private function listMaterials(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_materials WHERE projectId = ? ORDER BY id DESC");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveMaterial(): void
    {
        $this->genericSave('din1090_materials', [
            'projectId', 'bezeichnung', 'werkstoff', 'abmessung', 'charge_nr',
            'schmelz_nr', 'zeugnis_typ', 'zeugnis_nr', 'lieferant',
            'menge', 'einheit', 'pruef_status', 'bemerkung'
        ], 'Material');
    }

    private function deleteMaterial(): void
    {
        $this->genericDelete('din1090_materials', 'Material');
    }

    // =========================================================
    //  SCHWEIßER
    // =========================================================
    private function listWelders(): void
    {
        $rows = $this->db->query("SELECT * FROM din1090_welders ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'data' => $rows]);
    }

    private function saveWelder(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $fields = [
            'name', 'stempel_nr', 'qualifikation_nr', 'norm', 'verfahren',
            'position', 'werkstoff_gruppe', 'dicke_bereich', 'gueltig_bis', 'bemerkung'
        ];
        $now = date('Y-m-d H:i:s');

        if ($id > 0) {
            $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $this->db->prepare("UPDATE din1090_welders SET $sets WHERE id = :id");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $cols = implode(', ', $fields) . ', erstellt_am';
            $vals = implode(', ', array_map(fn($f) => ":$f", $fields)) . ', :ea';
            $stmt = $this->db->prepare("INSERT INTO din1090_welders ($cols) VALUES ($vals)");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':ea', $now);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    }

    private function deleteWelder(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $this->db->prepare("DELETE FROM din1090_welders WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
    }

    // =========================================================
    //  WPS (Schweißanweisungen)
    // =========================================================
    private function listWps(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_wps WHERE projectId = ? ORDER BY wps_nr");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveWps(): void
    {
        $this->genericSave('din1090_wps', [
            'projectId', 'wps_nr', 'verfahren', 'grundwerkstoff', 'zusatzwerkstoff',
            'schutzgas', 'position', 'nahtart', 'blechdicke_von', 'blechdicke_bis',
            'vorwaermung', 'wpqr_nr', 'status', 'bemerkung'
        ], 'WPS');
    }

    private function deleteWps(): void
    {
        $this->genericDelete('din1090_wps', 'WPS');
    }

    // =========================================================
    //  SCHWEIßPROTOKOLL (Weld Log)
    // =========================================================
    private function listWeldLog(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("
            SELECT wl.*, w.name AS schweisser_name, wp.wps_nr
            FROM din1090_weld_log wl
            LEFT JOIN din1090_welders w ON w.id = wl.schweisser_id
            LEFT JOIN din1090_wps wp ON wp.id = wl.wps_id
            WHERE wl.projectId = ?
            ORDER BY wl.datum DESC, wl.naht_nr
        ");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveWeldLog(): void
    {
        $this->genericSave('din1090_weld_log', [
            'projectId', 'naht_nr', 'bauteil', 'zeichnung_nr', 'wps_id', 'schweisser_id',
            'datum', 'position', 'nahtart', 'a_mass', 'laenge',
            'vorwaermung', 'zwischenlagen_temp', 'pruef_status', 'vt_ergebnis', 'bemerkung'
        ], 'Schweißnaht');
    }

    private function deleteWeldLog(): void
    {
        $this->genericDelete('din1090_weld_log', 'Schweißnaht');
    }

    // =========================================================
    //  PRÜFUNGEN
    // =========================================================
    private function listInspections(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_inspections WHERE projectId = ? ORDER BY pruef_datum DESC");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveInspection(): void
    {
        $this->genericSave('din1090_inspections', [
            'projectId', 'pruef_art', 'bauteil', 'naht_nr', 'pruef_datum',
            'pruefer', 'pruef_norm', 'ergebnis', 'report_nr', 'umfang', 'bemerkung'
        ], 'Prüfung');
    }

    private function deleteInspection(): void
    {
        $this->genericDelete('din1090_inspections', 'Prüfung');
    }

    // =========================================================
    //  ABWEICHUNGEN (NCR)
    // =========================================================
    private function listNcr(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_ncr WHERE projectId = ? ORDER BY datum DESC");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveNcr(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $fields = [
            'projectId', 'ncr_nr', 'datum', 'bauteil', 'beschreibung',
            'ursache', 'massnahme', 'verantwortlicher', 'frist', 'status'
        ];
        $now = date('Y-m-d H:i:s');

        if ($id > 0) {
            $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            // Status → geschlossen? abgeschlossen_am setzen
            $extra = '';
            if (($this->body['status'] ?? '') === 'geschlossen') {
                $extra = ", abgeschlossen_am = '$now'";
            }
            $stmt = $this->db->prepare("UPDATE din1090_ncr SET $sets $extra WHERE id = :id");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $pid = (int)($this->body['projectId'] ?? 0);
            $this->audit($pid, 'ncr_update', "NCR #$id aktualisiert");
        } else {
            $cols = implode(', ', $fields) . ', erstellt_am, erstellt_von';
            $vals = implode(', ', array_map(fn($f) => ":$f", $fields)) . ', :ea, :ev';
            $stmt = $this->db->prepare("INSERT INTO din1090_ncr ($cols) VALUES ($vals)");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':ea', $now);
            $stmt->bindValue(':ev', $this->user);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();
            $pid = (int)($this->body['projectId'] ?? 0);
            $this->audit($pid, 'ncr_create', "NCR #$id erstellt");
        }

        echo json_encode(['ok' => true, 'id' => $id]);
    }

    private function deleteNcr(): void
    {
        $this->genericDelete('din1090_ncr', 'NCR');
    }

    // =========================================================
    //  OBERFLÄCHENBEHANDLUNG
    // =========================================================
    private function listSurface(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_surface WHERE projectId = ? ORDER BY id DESC");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function saveSurface(): void
    {
        $this->genericSave('din1090_surface', [
            'projectId', 'bauteil', 'system', 'vorbehandlung', 'grundierung',
            'schicht_1', 'schicht_2', 'soll_dicke', 'ist_dicke',
            'pruef_datum', 'pruefer', 'ergebnis', 'bemerkung'
        ], 'Oberfläche');
    }

    private function deleteSurface(): void
    {
        $this->genericDelete('din1090_surface', 'Oberfläche');
    }

    // =========================================================
    //  CHECKLISTEN
    // =========================================================
    private function getChecklists(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_checklists WHERE projectId = ? ORDER BY kategorie, id");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    private function generateChecklist(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $exc = (int)($this->body['excLevel'] ?? 2);

        // Bestehende löschen und neu generieren
        $this->db->prepare("DELETE FROM din1090_checklists WHERE projectId = ?")->execute([$pid]);
        $this->generateChecklistForProject($pid, $exc);
        $this->audit($pid, 'checklist_regenerate', "Checkliste für EXC$exc neu generiert");

        $this->getChecklists();
    }

    private function updateChecklist(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        $erledigt = (int)($this->body['erledigt'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $bemerkung = $this->body['bemerkung'] ?? '';

        if ($erledigt === -1) {
            // Nur Bemerkung aktualisieren
            $stmt = $this->db->prepare("UPDATE din1090_checklists SET bemerkung = :b WHERE id = :id");
            $stmt->execute([':b' => $bemerkung, ':id' => $id]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE din1090_checklists
                SET erledigt = :e, erledigt_am = :ea, erledigt_von = :ev, bemerkung = :b
                WHERE id = :id
            ");
            $stmt->execute([
                ':e'  => $erledigt,
                ':ea' => $erledigt ? $now : '',
                ':ev' => $erledigt ? $this->user : '',
                ':b'  => $bemerkung,
                ':id' => $id
            ]);
        }

        echo json_encode(['ok' => true]);
    }

    // =========================================================
    //  AUDIT LOG
    // =========================================================
    private function getAuditLog(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);
        $stmt = $this->db->prepare("SELECT * FROM din1090_audit_log WHERE projectId = ? ORDER BY zeitpunkt DESC LIMIT 200");
        $stmt->execute([$pid]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // =========================================================
    //  DASHBOARD
    // =========================================================
    private function dashboard(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);

        $counts = [];
        $tables = [
            'materials'   => 'din1090_materials',
            'welders'     => 'din1090_welders',
            'wps'         => 'din1090_wps',
            'welds'       => 'din1090_weld_log',
            'inspections' => 'din1090_inspections',
            'ncr'         => 'din1090_ncr',
            'surface'     => 'din1090_surface',
        ];

        foreach ($tables as $key => $tbl) {
            if ($key === 'welders') {
                $counts[$key] = (int)$this->db->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
            } else {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM $tbl WHERE projectId = ?");
                $stmt->execute([$pid]);
                $counts[$key] = (int)$stmt->fetchColumn();
            }
        }

        // Offene NCR
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM din1090_ncr WHERE projectId = ? AND status != 'geschlossen'");
        $stmt->execute([$pid]);
        $counts['ncr_offen'] = (int)$stmt->fetchColumn();

        // Checkliste Fortschritt
        $stmt = $this->db->prepare("SELECT COUNT(*) as total, SUM(erledigt) as done FROM din1090_checklists WHERE projectId = ?");
        $stmt->execute([$pid]);
        $chk = $stmt->fetch(\PDO::FETCH_ASSOC);
        $counts['checklist_total'] = (int)($chk['total'] ?? 0);
        $counts['checklist_done']  = (int)($chk['done'] ?? 0);

        // Schweißer mit ablaufender Qualifikation (< 3 Monate)
        $threeMonths = date('Y-m-d', strtotime('+3 months'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM din1090_welders WHERE gueltig_bis != '' AND gueltig_bis <= ?");
        $stmt->execute([$threeMonths]);
        $counts['welders_expiring'] = (int)$stmt->fetchColumn();

        echo json_encode(['ok' => true, 'data' => $counts]);
    }

    // =========================================================
    //  EXPORT – Projekt-Protokoll als JSON (Frontend erzeugt PDF)
    // =========================================================
    private function exportProject(): void
    {
        $pid = (int)($this->body['projectId'] ?? 0);

        // Projekt-Stammdaten
        $stmt = $this->db->prepare("
            SELECT p.*, b.name AS baustelle_name
            FROM din1090_projects p
            LEFT JOIN baustellen b ON b.id = p.baustelleId
            WHERE p.id = ?
        ");
        $stmt->execute([$pid]);
        $project = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$project) {
            echo json_encode(['ok' => false, 'error' => 'Projekt nicht gefunden.']);
            return;
        }

        // Material
        $stmt = $this->db->prepare("SELECT * FROM din1090_materials WHERE projectId = ? ORDER BY id");
        $stmt->execute([$pid]);
        $materials = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Schweißer (global)
        $welders = $this->db->query("SELECT * FROM din1090_welders ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);

        // WPS
        $stmt = $this->db->prepare("SELECT * FROM din1090_wps WHERE projectId = ? ORDER BY wps_nr");
        $stmt->execute([$pid]);
        $wps = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Schweißprotokoll
        $stmt = $this->db->prepare("
            SELECT wl.*, w.name AS schweisser_name, wp.wps_nr
            FROM din1090_weld_log wl
            LEFT JOIN din1090_welders w ON w.id = wl.schweisser_id
            LEFT JOIN din1090_wps wp ON wp.id = wl.wps_id
            WHERE wl.projectId = ?
            ORDER BY wl.datum, wl.naht_nr
        ");
        $stmt->execute([$pid]);
        $weldLog = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Prüfungen
        $stmt = $this->db->prepare("SELECT * FROM din1090_inspections WHERE projectId = ? ORDER BY pruef_datum");
        $stmt->execute([$pid]);
        $inspections = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // NCR
        $stmt = $this->db->prepare("SELECT * FROM din1090_ncr WHERE projectId = ? ORDER BY datum");
        $stmt->execute([$pid]);
        $ncr = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Oberfläche
        $stmt = $this->db->prepare("SELECT * FROM din1090_surface WHERE projectId = ? ORDER BY id");
        $stmt->execute([$pid]);
        $surface = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Checkliste
        $stmt = $this->db->prepare("SELECT * FROM din1090_checklists WHERE projectId = ? ORDER BY kategorie, id");
        $stmt->execute([$pid]);
        $checklists = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'          => true,
            'project'     => $project,
            'materials'   => $materials,
            'welders'     => $welders,
            'wps'         => $wps,
            'weldLog'     => $weldLog,
            'inspections' => $inspections,
            'ncr'         => $ncr,
            'surface'     => $surface,
            'checklists'  => $checklists,
        ], JSON_UNESCAPED_UNICODE);
    }

    // =========================================================
    //  KOPIEREN AUS ANDEREM PROJEKT
    // =========================================================
    private function copyFromProject(): void
    {
        $targetPid = (int)($this->body['targetProjectId'] ?? 0);
        $sourcePid = (int)($this->body['sourceProjectId'] ?? 0);
        $section   = $this->body['section'] ?? '';

        if (!$targetPid || !$sourcePid || $targetPid === $sourcePid) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Projekt-IDs.']);
            return;
        }

        $allowed = ['wps', 'weld_log', 'inspections', 'surface'];
        if (!in_array($section, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Bereich.']);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;

        $this->db->beginTransaction();
        try {
            switch ($section) {
                case 'wps':
                    $stmt = $this->db->prepare("SELECT * FROM din1090_wps WHERE projectId = ?");
                    $stmt->execute([$sourcePid]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $ins = $this->db->prepare("INSERT INTO din1090_wps
                        (projectId, wps_nr, verfahren, grundwerkstoff, zusatzwerkstoff, schutzgas, position, nahtart, blechdicke_von, blechdicke_bis, vorwaermung, wpqr_nr, status, bemerkung, erstellt_am, erstellt_von)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($rows as $r) {
                        $ins->execute([
                            $targetPid, $r['wps_nr'], $r['verfahren'], $r['grundwerkstoff'], $r['zusatzwerkstoff'],
                            $r['schutzgas'], $r['position'], $r['nahtart'], $r['blechdicke_von'], $r['blechdicke_bis'],
                            $r['vorwaermung'], $r['wpqr_nr'], $r['status'], $r['bemerkung'], $now, $this->user
                        ]);
                        $count++;
                    }
                    break;

                case 'weld_log':
                    $stmt = $this->db->prepare("SELECT * FROM din1090_weld_log WHERE projectId = ?");
                    $stmt->execute([$sourcePid]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $ins = $this->db->prepare("INSERT INTO din1090_weld_log
                        (projectId, naht_nr, bauteil, zeichnung_nr, wps_id, schweisser_id, datum, position, nahtart, a_mass, laenge, vorwaermung, zwischenlagen_temp, pruef_status, vt_ergebnis, bemerkung, erstellt_am, erstellt_von)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($rows as $r) {
                        $ins->execute([
                            $targetPid, $r['naht_nr'], $r['bauteil'], $r['zeichnung_nr'], $r['wps_id'], $r['schweisser_id'],
                            $r['datum'], $r['position'], $r['nahtart'], $r['a_mass'], $r['laenge'],
                            $r['vorwaermung'], $r['zwischenlagen_temp'], $r['pruef_status'], $r['vt_ergebnis'],
                            $r['bemerkung'], $now, $this->user
                        ]);
                        $count++;
                    }
                    break;

                case 'inspections':
                    $stmt = $this->db->prepare("SELECT * FROM din1090_inspections WHERE projectId = ?");
                    $stmt->execute([$sourcePid]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $ins = $this->db->prepare("INSERT INTO din1090_inspections
                        (projectId, pruef_art, bauteil, naht_nr, pruef_datum, pruefer, pruef_norm, ergebnis, report_nr, umfang, bemerkung, erstellt_am, erstellt_von)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($rows as $r) {
                        $ins->execute([
                            $targetPid, $r['pruef_art'], $r['bauteil'], $r['naht_nr'], $r['pruef_datum'],
                            $r['pruefer'], $r['pruef_norm'], $r['ergebnis'], $r['report_nr'],
                            $r['umfang'], $r['bemerkung'], $now, $this->user
                        ]);
                        $count++;
                    }
                    break;

                case 'surface':
                    $stmt = $this->db->prepare("SELECT * FROM din1090_surface WHERE projectId = ?");
                    $stmt->execute([$sourcePid]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    $ins = $this->db->prepare("INSERT INTO din1090_surface
                        (projectId, bauteil, system, vorbehandlung, grundierung, schicht_1, schicht_2, soll_dicke, ist_dicke, pruef_datum, pruefer, ergebnis, bemerkung, erstellt_am, erstellt_von)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($rows as $r) {
                        $ins->execute([
                            $targetPid, $r['bauteil'], $r['system'], $r['vorbehandlung'], $r['grundierung'],
                            $r['schicht_1'], $r['schicht_2'], $r['soll_dicke'], $r['ist_dicke'],
                            $r['pruef_datum'], $r['pruefer'], $r['ergebnis'], $r['bemerkung'], $now, $this->user
                        ]);
                        $count++;
                    }
                    break;
            }

            $this->db->commit();
            $labels = ['wps' => 'WPS', 'weld_log' => 'Schweißnähte', 'inspections' => 'Prüfungen', 'surface' => 'Oberflächen'];
            $this->audit($targetPid, 'copy_' . $section, "$count {$labels[$section]} aus Projekt #$sourcePid kopiert");
            echo json_encode(['ok' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Fehler beim Kopieren: ' . $e->getMessage()]);
        }
    }

    // =========================================================
    //  HILFSFUNKTIONEN
    // =========================================================

    /** Generische Save-Methode für einfache CRUD-Tabellen */
    private function genericSave(string $table, array $fields, string $label): void
    {
        $id  = (int)($this->body['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $pid = (int)($this->body['projectId'] ?? 0);

        if ($id > 0) {
            $sets = implode(', ', array_map(fn($f) => "$f = :$f", $fields));
            $stmt = $this->db->prepare("UPDATE $table SET $sets WHERE id = :id");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $this->audit($pid, strtolower($label) . '_update', "$label #$id aktualisiert");
        } else {
            $cols = implode(', ', $fields) . ', erstellt_am, erstellt_von';
            $vals = implode(', ', array_map(fn($f) => ":$f", $fields)) . ', :ea, :ev';
            $stmt = $this->db->prepare("INSERT INTO $table ($cols) VALUES ($vals)");
            foreach ($fields as $f) $stmt->bindValue(":$f", $this->body[$f] ?? '');
            $stmt->bindValue(':ea', $now);
            $stmt->bindValue(':ev', $this->user);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();
            $this->audit($pid, strtolower($label) . '_create', "$label #$id erstellt");
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    }

    /** Generische Delete-Methode */
    private function genericDelete(string $table, string $label): void
    {
        $id  = (int)($this->body['id'] ?? 0);
        $pid = (int)($this->body['projectId'] ?? 0);
        $this->db->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
        $this->audit($pid, strtolower($label) . '_delete', "$label #$id gelöscht");
        echo json_encode(['ok' => true]);
    }

    /** Audit-Eintrag schreiben */
    private function audit(int $projectId, string $aktion, string $details): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO din1090_audit_log (projectId, aktion, details, benutzer, zeitpunkt)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $aktion, $details, $this->user, date('Y-m-d H:i:s')]);
    }

    /** Checkliste für ein Projekt generieren */
    private function generateChecklistForProject(int $projectId, int $excLevel): void
    {
        $items = Din1090Database::getChecklistTemplate($excLevel);
        $stmt  = $this->db->prepare("
            INSERT INTO din1090_checklists (projectId, kategorie, punkt, erforderlich_ab)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $stmt->execute([$projectId, $item[0], $item[1], $item[2]]);
        }
    }
}
