<?php
namespace App\Handlers;

use App\Auth;
use App\Database;

class TerminActions
{
    private \PDO $db;
    private array $body;

    public function __construct(\PDO $db, array $body)
    {
        $this->db   = $db;
        $this->body = $body;
    }

    // ── Termine laden ────────────────────────────────────────
    public function loadTermine(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'] ?? '';
        $role     = $_SESSION['role'] ?? 'normal';
        $perms    = Auth::getCurrentUserPerms($this->db);

        $seeAll = ($role === 'admin') || !empty($perms['canSeeAllTermine']);
        $seeOwn = ($role === 'admin') || !empty($perms['canSeeOwnTermine']) || !empty($perms['canSeeAllTermine']);

        if (!$seeOwn) {
            jsonOut(['ok' => true, 'termine' => []]);
            return;
        }

        $rows = $this->db->query("SELECT * FROM termine ORDER BY datum, zeitVon")->fetchAll();
        $termine = [];
        foreach ($rows as $r) {
            $zugewiesen = json_decode($r['zugewiesen'] ?: '[]', true) ?: [];
            // Filter: nur eigene oder alle
            if (!$seeAll) {
                if ($r['ersteller'] !== $username && !in_array($username, $zugewiesen)) {
                    continue;
                }
            }
            $termine[] = [
                'id'            => (int)$r['id'],
                'titel'         => $r['titel'],
                'beschreibung'  => $r['beschreibung'],
                'datum'         => $r['datum'],
                'zeitVon'       => $r['zeitVon'],
                'zeitBis'       => $r['zeitBis'],
                'ganztags'      => (bool)$r['ganztags'],
                'ort'           => $r['ort'],
                'baustelleId'   => $r['baustelleId'] !== null ? (int)$r['baustelleId'] : null,
                'zugewiesen'    => $zugewiesen,
                'ersteller'     => $r['ersteller'],
                'erstelltAm'    => $r['erstelltAm'],
                'farbe'         => $r['farbe'],
                'wiederholung'  => $r['wiederholung'],
            ];
        }
        jsonOut(['ok' => true, 'termine' => $termine]);
    }

    // ── Termin speichern (neu oder bearbeiten) ───────────────
    public function saveTermin(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'] ?? '';
        $role     = $_SESSION['role'] ?? 'normal';
        $perms    = Auth::getCurrentUserPerms($this->db);
        $canManage = ($role === 'admin') || !empty($perms['canManageTermine']);

        if (!$canManage) {
            jsonOut(['error' => 'Keine Berechtigung zum Verwalten von Terminen.'], 403);
            return;
        }

        $id    = $this->body['id'] ?? null;
        $titel = trim($this->body['titel'] ?? '');
        $datum = trim($this->body['datum'] ?? '');

        if ($titel === '' || $datum === '') {
            jsonOut(['error' => 'Titel und Datum sind Pflichtfelder.'], 400);
            return;
        }

        $zugewiesen = $this->body['zugewiesen'] ?? [];
        if (!is_array($zugewiesen)) $zugewiesen = [];
        // Sanitize usernames
        $zugewiesen = array_values(array_filter(array_map('trim', $zugewiesen)));

        $beschreibung = trim($this->body['beschreibung'] ?? '');
        $zeitVon      = trim($this->body['zeitVon'] ?? '');
        $zeitBis      = trim($this->body['zeitBis'] ?? '');
        $ganztags     = !empty($this->body['ganztags']) ? 1 : 0;
        $ort          = trim($this->body['ort'] ?? '');
        $baustelleId  = $this->body['baustelleId'] ?? null;
        $farbe        = trim($this->body['farbe'] ?? '#00B4D8');
        $wiederholung = trim($this->body['wiederholung'] ?? '');
        $zugJson      = json_encode($zugewiesen, JSON_UNESCAPED_UNICODE);

        if ($id) {
            $this->db->prepare(
                "UPDATE termine SET titel=?, beschreibung=?, datum=?, zeitVon=?, zeitBis=?, ganztags=?, ort=?, baustelleId=?, zugewiesen=?, farbe=?, wiederholung=? WHERE id=?"
            )->execute([$titel, $beschreibung, $datum, $zeitVon, $zeitBis, $ganztags, $ort, $baustelleId, $zugJson, $farbe, $wiederholung, (int)$id]);
            jsonOut(['ok' => true, 'id' => (int)$id]);
        } else {
            $this->db->prepare(
                "INSERT INTO termine (titel, beschreibung, datum, zeitVon, zeitBis, ganztags, ort, baustelleId, zugewiesen, ersteller, erstelltAm, farbe, wiederholung) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$titel, $beschreibung, $datum, $zeitVon, $zeitBis, $ganztags, $ort, $baustelleId, $zugJson, $username, date('Y-m-d H:i:s'), $farbe, $wiederholung]);
            jsonOut(['ok' => true, 'id' => (int)$this->db->lastInsertId()]);
        }
    }

    // ── Termin löschen ───────────────────────────────────────
    public function deleteTermin(): void
    {
        Auth::requireAuth();
        $role  = $_SESSION['role'] ?? 'normal';
        $perms = Auth::getCurrentUserPerms($this->db);
        $canManage = ($role === 'admin') || !empty($perms['canManageTermine']);

        if (!$canManage) {
            jsonOut(['error' => 'Keine Berechtigung.'], 403);
            return;
        }

        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) {
            jsonOut(['error' => 'Ungültige Termin-ID.'], 400);
            return;
        }

        $this->db->prepare("DELETE FROM termine WHERE id = ?")->execute([$id]);
        jsonOut(['ok' => true]);
    }
}
