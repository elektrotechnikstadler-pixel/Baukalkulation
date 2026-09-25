<?php
namespace App\Handlers;

use App\Auth;
use App\Database;

class GruppenActions
{
    private \PDO   $db;
    private array  $body;

    public function __construct(\PDO $db, array $body)
    {
        $this->db   = $db;
        $this->body = $body;
    }

    // ── Alle Gruppen laden (Admin/Master) ────────────────────
    public function load(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');

        $gruppen    = $this->db->query(
            "SELECT * FROM gruppen ORDER BY LOWER(name)"
        )->fetchAll();
        $mitglieder = $this->db->query(
            "SELECT * FROM gruppen_mitglieder ORDER BY LOWER(username)"
        )->fetchAll();

        $result = array_map(function ($g) use ($mitglieder) {
            return [
                'id'         => (int)$g['id'],
                'name'       => $g['name'],
                'farbe'      => $g['farbe'],
                'erstelltAm' => $g['erstelltAm'],
                'mitglieder' => array_values(array_map(
                    fn($m) => $m['username'],
                    array_filter($mitglieder, fn($m) => (int)$m['gruppen_id'] === (int)$g['id'])
                )),
            ];
        }, $gruppen);

        jsonOut(['ok' => true, 'gruppen' => $result]);
    }

    // ── Gruppen für normalen User (nur eigene Gruppen) ───────
    public function loadForUser(): void
    {
        Auth::requireAuth();
        $username = $_SESSION['username'] ?? '';
        $role     = $_SESSION['role'] ?? 'normal';

        if (in_array($role, ['admin', 'master'], true)) {
            $gruppen = $this->db->query(
                "SELECT id, name, farbe FROM gruppen ORDER BY LOWER(name)"
            )->fetchAll();
        } else {
            $stmt = $this->db->prepare(
                "SELECT g.id, g.name, g.farbe
                   FROM gruppen g
                   JOIN gruppen_mitglieder gm ON gm.gruppen_id = g.id
                  WHERE gm.username = ?
                  ORDER BY LOWER(g.name)"
            );
            $stmt->execute([$username]);
            $gruppen = $stmt->fetchAll();
        }

        $result = array_map(fn($g) => [
            'id'    => (int)$g['id'],
            'name'  => $g['name'],
            'farbe' => $g['farbe'],
        ], $gruppen);

        jsonOut(['ok' => true, 'gruppen' => $result]);
    }

    // ── Gruppe anlegen oder bearbeiten (Admin/Master) ────────
    public function save(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');

        $id         = isset($this->body['id']) ? (int)$this->body['id'] : null;
        $name       = trim($this->body['name'] ?? '');
        $farbe      = trim($this->body['farbe'] ?? '#6366f1');
        $mitglieder = $this->body['mitglieder'] ?? [];
        if (!is_array($mitglieder)) $mitglieder = [];

        if ($name === '') {
            jsonOut(['error' => 'Gruppenname ist Pflichtfeld.'], 400);
            return;
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $farbe)) {
            $farbe = '#6366f1';
        }

        if ($id) {
            $this->db->prepare("UPDATE gruppen SET name = ?, farbe = ? WHERE id = ?")
                     ->execute([$name, $farbe, $id]);
        } else {
            $this->db->prepare(
                "INSERT INTO gruppen (name, farbe, erstelltAm, erstelltVon) VALUES (?, ?, ?, ?)"
            )->execute([$name, $farbe, date('Y-m-d H:i:s'), $_SESSION['username'] ?? '']);
            $id = (int)$this->db->lastInsertId();
        }

        // Mitglieder komplett ersetzen
        $this->db->prepare("DELETE FROM gruppen_mitglieder WHERE gruppen_id = ?")->execute([$id]);
        $stmt = $this->db->prepare(
            "INSERT INTO gruppen_mitglieder (gruppen_id, username) VALUES (?, ?) ON CONFLICT DO NOTHING"
        );
        foreach ($mitglieder as $u) {
            $u = trim((string)$u);
            if ($u !== '') $stmt->execute([$id, $u]);
        }

        jsonOut(['ok' => true, 'id' => $id]);
    }

    // ── Gruppe löschen (Admin/Master) ────────────────────────
    public function delete(): void
    {
        Auth::requireAuth();
        Auth::requireRole('admin', 'master');

        $id = (int)($this->body['id'] ?? 0);
        if (!$id) {
            jsonOut(['error' => 'Keine ID angegeben.'], 400);
            return;
        }

        $this->db->prepare("DELETE FROM gruppen_mitglieder WHERE gruppen_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM gruppen WHERE id = ?")->execute([$id]);
        jsonOut(['ok' => true]);
    }
}
