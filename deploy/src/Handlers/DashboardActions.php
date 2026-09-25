<?php
namespace App\Handlers;

use App\Auth;

class DashboardActions
{
    private \PDO $db;
    private array $body;

    public function __construct(\PDO $db, array $body)
    {
        $this->db   = $db;
        $this->body = $body;
    }

    // ── Dashboard-Einträge laden ─────────────────────────────
    public function load(): void
    {
        Auth::requireAuth();
        $username  = $_SESSION['username'] ?? '';
        $role      = $_SESSION['role'] ?? 'normal';
        $perms     = Auth::getCurrentUserPerms($this->db);
        $canRead   = ($role === 'admin') || !empty($perms['canReadDashboard']);
        $canManage = ($role === 'admin') || ($role === 'master') || !empty($perms['canManageDashboard']);

        if (!$canRead) {
            jsonOut(['error' => 'Keine Berechtigung für das Dashboard.'], 403);
            return;
        }

        // Filter-Parameter (optional, vom Frontend mitgesendet)
        $filterUser = trim($this->body['filterUser'] ?? '');

        if ($canManage) {
            if ($filterUser !== '') {
                $stmt = $this->db->prepare(
                    "SELECT * FROM dashboard_items WHERE ersteller = ? OR zugewiesen_an = ?
                     ORDER BY sortPos ASC, erstelltAm DESC"
                );
                $stmt->execute([$filterUser, $filterUser]);
            } else {
                $stmt = $this->db->query(
                    "SELECT * FROM dashboard_items ORDER BY sortPos ASC, erstelltAm DESC"
                );
            }
        } else {
            // Normaler User: eigene + direkt zugewiesene + alle + Gruppeneinträge
            $gruppenStmt = $this->db->prepare(
                "SELECT gruppen_id FROM gruppen_mitglieder WHERE username = ?"
            );
            $gruppenStmt->execute([$username]);
            $gruppenIds = array_column($gruppenStmt->fetchAll(), 'gruppen_id');

            $placeholders = [];
            $params       = [$username, $username, 'alle'];
            foreach ($gruppenIds as $gid) {
                $placeholders[] = '?';
                $params[]       = 'gruppe:' . $gid;
            }
            $extraIn = count($placeholders) > 0
                ? ' OR zugewiesen_an IN (' . implode(',', $placeholders) . ')'
                : '';

            $stmt = $this->db->prepare(
                "SELECT * FROM dashboard_items
                  WHERE ersteller = ? OR zugewiesen_an = ? OR zugewiesen_an = ?
                  {$extraIn}
                  ORDER BY sortPos ASC, erstelltAm DESC"
            );
            $stmt->execute($params);
        }

        $rows  = $stmt->fetchAll();
        $items = array_map([$this, 'rowToItem'], $rows);
        jsonOut(['ok' => true, 'items' => $items, 'canManage' => $canManage]);
    }

    // ── Dashboard-Eintrag speichern (neu oder bearbeiten) ────
    public function saveItem(): void
    {
        Auth::requireAuth();
        $username  = $_SESSION['username'] ?? '';
        $role      = $_SESSION['role'] ?? 'normal';
        $perms     = Auth::getCurrentUserPerms($this->db);
        $canWrite  = ($role === 'admin') || !empty($perms['canWriteDashboard']);
        $canManage = ($role === 'admin') || !empty($perms['canManageDashboard']);

        if (!$canWrite) {
            jsonOut(['error' => 'Keine Berechtigung zum Schreiben von Dashboard-Einträgen.'], 403);
            return;
        }

        $id           = isset($this->body['id']) ? (int)$this->body['id'] : null;
        $typ          = in_array($this->body['typ'] ?? '', ['aufgabe','notiz','erinnerung'], true)
                          ? $this->body['typ'] : 'aufgabe';
        $titel        = trim($this->body['titel'] ?? '');
        $beschreibung = trim($this->body['beschreibung'] ?? '');
        $faelligAm    = trim($this->body['faelligAm'] ?? '');
        $status       = in_array($this->body['status'] ?? '', ['offen','erledigt'], true)
                          ? $this->body['status'] : 'offen';
        $prioritaet   = in_array($this->body['prioritaet'] ?? '', ['niedrig','normal','hoch'], true)
                          ? $this->body['prioritaet'] : 'normal';
        $linkTyp      = in_array($this->body['linkTyp'] ?? '', ['baustelle','kunde','termin',''], true)
                          ? $this->body['linkTyp'] : '';
        $linkId       = isset($this->body['linkId']) && $this->body['linkId'] !== null
                          ? (int)$this->body['linkId'] : null;
        $farbe        = trim($this->body['farbe'] ?? '#00B4D8');
        $sortPos      = isset($this->body['sortPos']) ? (int)$this->body['sortPos'] : 0;
        $zugewiesenAn = trim($this->body['zugewiesen_an'] ?? '');

        if ($titel === '') {
            jsonOut(['error' => 'Titel darf nicht leer sein.'], 400);
            return;
        }

        // Farbe-Validierung: nur hexadezimale Farben erlaubt
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $farbe)) {
            $farbe = '#00B4D8';
        }

        if ($id) {
            // Bestehenden Eintrag prüfen
            $existing = $this->db->prepare("SELECT ersteller FROM dashboard_items WHERE id = ?");
            $existing->execute([$id]);
            $row = $existing->fetch();
            if (!$row) {
                jsonOut(['error' => 'Eintrag nicht gefunden.'], 404);
                return;
            }
            // Nur eigene Einträge oder canManageDashboard
            if ($row['ersteller'] !== $username && !$canManage) {
                jsonOut(['error' => 'Keine Berechtigung zum Bearbeiten dieses Eintrags.'], 403);
                return;
            }
            $stmt = $this->db->prepare(
                "UPDATE dashboard_items SET typ=?, titel=?, beschreibung=?, faelligAm=?, status=?,
                 prioritaet=?, linkTyp=?, linkId=?, farbe=?, sortPos=?, zugewiesen_an=? WHERE id=?"
            );
            $stmt->execute([$typ, $titel, $beschreibung, $faelligAm, $status,
                            $prioritaet, $linkTyp, $linkId, $farbe, $sortPos, $zugewiesenAn, $id]);
            jsonOut(['ok' => true, 'id' => $id]);
        } else {
            $now  = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare(
                "INSERT INTO dashboard_items (typ, titel, beschreibung, faelligAm, status, prioritaet,
                 linkTyp, linkId, ersteller, erstelltAm, farbe, sortPos, zugewiesen_an)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$typ, $titel, $beschreibung, $faelligAm, $status,
                            $prioritaet, $linkTyp, $linkId, $username, $now, $farbe, $sortPos, $zugewiesenAn]);
            jsonOut(['ok' => true, 'id' => (int)$this->db->lastInsertId()]);
        }
    }

    // ── Dashboard-Eintrag löschen ────────────────────────────
    public function deleteItem(): void
    {
        Auth::requireAuth();
        $username  = $_SESSION['username'] ?? '';
        $role      = $_SESSION['role'] ?? 'normal';
        $perms     = Auth::getCurrentUserPerms($this->db);
        $canWrite  = ($role === 'admin') || !empty($perms['canWriteDashboard']);
        $canManage = ($role === 'admin') || !empty($perms['canManageDashboard']);

        if (!$canWrite) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
            return;
        }

        $id = (int)($this->body['id'] ?? 0);
        if (!$id) {
            jsonOut(['error' => 'Keine ID angegeben.'], 400);
            return;
        }

        $stmt = $this->db->prepare("SELECT ersteller FROM dashboard_items WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonOut(['error' => 'Eintrag nicht gefunden.'], 404);
            return;
        }
        if ($row['ersteller'] !== $username && !$canManage) {
            jsonOut(['error' => 'Keine Berechtigung zum Löschen dieses Eintrags.'], 403);
            return;
        }

        $this->db->prepare("DELETE FROM dashboard_items WHERE id = ?")->execute([$id]);
        jsonOut(['ok' => true]);
    }

    // ── Status umschalten ────────────────────────────────────
    public function updateStatus(): void
    {
        Auth::requireAuth();
        $username  = $_SESSION['username'] ?? '';
        $role      = $_SESSION['role'] ?? 'normal';
        $perms     = Auth::getCurrentUserPerms($this->db);
        $canWrite  = ($role === 'admin') || !empty($perms['canWriteDashboard']);
        $canManage = ($role === 'admin') || !empty($perms['canManageDashboard']);

        if (!$canWrite) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
            return;
        }

        $id     = (int)($this->body['id'] ?? 0);
        $status = in_array($this->body['status'] ?? '', ['offen','erledigt'], true)
                    ? $this->body['status'] : 'offen';

        if (!$id) {
            jsonOut(['error' => 'Keine ID angegeben.'], 400);
            return;
        }

        $stmt = $this->db->prepare("SELECT ersteller FROM dashboard_items WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonOut(['error' => 'Eintrag nicht gefunden.'], 404);
            return;
        }
        if ($row['ersteller'] !== $username && !$canManage) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
            return;
        }

        $this->db->prepare("UPDATE dashboard_items SET status = ? WHERE id = ?")->execute([$status, $id]);
        jsonOut(['ok' => true]);
    }

    // ── Sortierreihenfolge speichern ─────────────────────────
    public function updateSort(): void
    {
        Auth::requireAuth();
        $username  = $_SESSION['username'] ?? '';
        $role      = $_SESSION['role'] ?? 'normal';
        $perms     = Auth::getCurrentUserPerms($this->db);
        $canWrite  = ($role === 'admin') || !empty($perms['canWriteDashboard']);
        $canManage = ($role === 'admin') || ($role === 'master') || !empty($perms['canManageDashboard']);

        if (!$canWrite) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
            return;
        }

        $items = $this->body['items'] ?? [];
        if (!is_array($items)) {
            jsonOut(['error' => 'Ungültiges Format.'], 400);
            return;
        }

        $stmt = $this->db->prepare("SELECT ersteller FROM dashboard_items WHERE id = ?");
        $upd  = $this->db->prepare("UPDATE dashboard_items SET sortPos = ? WHERE id = ?");

        foreach ($items as $entry) {
            $id      = (int)($entry['id'] ?? 0);
            $sortPos = (int)($entry['sortPos'] ?? 0);
            if (!$id) continue;
            // Sicherheitscheck: nur eigene oder canManage
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) continue;
            if ($row['ersteller'] !== $username && !$canManage) continue;
            $upd->execute([$sortPos, $id]);
        }
        jsonOut(['ok' => true]);
    }

    // ── Hilfsfunktion: Datenbankzeile → Array ────────────────
    private function rowToItem(array $r): array
    {
        return [
            'id'            => (int)$r['id'],
            'typ'           => $r['typ'],
            'titel'         => $r['titel'],
            'beschreibung'  => $r['beschreibung'],
            'faelligAm'     => $r['faelligAm'],
            'status'        => $r['status'],
            'prioritaet'    => $r['prioritaet'],
            'linkTyp'       => $r['linkTyp'],
            'linkId'        => $r['linkId'] !== null ? (int)$r['linkId'] : null,
            'ersteller'     => $r['ersteller'],
            'erstelltAm'    => $r['erstelltAm'],
            'farbe'         => $r['farbe'],
            'sortPos'       => (int)$r['sortPos'],
            'zugewiesen_an' => $r['zugewiesen_an'] ?? '',
        ];
    }
}
