<?php
namespace App\Handlers;

use App\Auth;
use App\Services\AuditService;
use App\Services\Jobs\JobProtokoll;
use App\Services\Jobs\SystemEinstellungen;

/**
 * Serversicherung/Planer-Verwaltung (T2 von AP-20261012-datensicherung-nas).
 * Alle Aktionen nur Rolle admin; zustandsändernde Aktionen nur POST (E-015).
 */
final class SystemJobActions
{
    /** Positivliste der per UI anforderbaren Jobs. */
    private const ANFORDERBARE_JOBS = ['sicherung'];
    private const AUFTRAG_GUELTIGKEIT_SEKUNDEN = 1800; // 30 min
    /** Muss zu Planer::HAENGENDER_LAUF_STUNDEN passen – ab hier gilt ein "laufender" Auftrag als hängend. */
    private const AUFTRAG_LAUFEND_MAX_STUNDEN = 3;

    public function __construct(private \PDO $db, private array $body) {}

    /** system_jobs_status (GET) */
    public function status(): void
    {
        Auth::requireRole('admin');
        $protokoll = new JobProtokoll($this->db);

        $planerStatus = $protokoll->status('planer');
        $herzschlag = (string)($planerStatus['letzterLaufAm'] ?? '');
        $planerAktiv = $herzschlag !== '' && (time() - strtotime($herzschlag)) < 900;

        $einstellungen = SystemEinstellungen::laden($this->db);

        jsonOut([
            'ok' => true,
            'einstellungen' => ['sicherung' => $einstellungen['sicherung']],
            'status' => [
                'sicherung'  => $protokoll->status('sicherung'),
                'backupmail' => $protokoll->status('backupmail'),
                'planer'     => ['letzterLaufAm' => $herzschlag, 'aktiv' => $planerAktiv],
            ],
            'laeufe' => $protokoll->letzteLaeufe(20),
        ]);
    }

    /** system_einstellungen_speichern (POST, {sicherung:{uhrzeit, aufbewahrungTage}}) */
    public function einstellungenSpeichern(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Speichern nur per POST.'], 405);
        }
        $eingabe = $this->body['sicherung'] ?? [];
        if (!is_array($eingabe)) {
            jsonOut(['error' => 'Ungültige Eingabe.'], 400);
        }
        try {
            $alle = SystemEinstellungen::speichernSicherung($this->db, $eingabe);
        } catch (\InvalidArgumentException $e) {
            jsonOut(['error' => $e->getMessage()], 400);
        }
        jsonOut(['ok' => true, 'sicherung' => $alle['sicherung']]);
    }

    /** job_anfordern (POST, {job:'sicherung'}) */
    public function auftragAnfordern(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Anfordern nur per POST.'], 405);
        }
        $job = (string)($this->body['job'] ?? '');
        if (!in_array($job, self::ANFORDERBARE_JOBS, true)) {
            jsonOut(['error' => 'Unbekannter Job.'], 400);
        }

        $jetzt = date('Y-m-d H:i:s');
        $grenzeLaufend = date('Y-m-d H:i:s', time() - self::AUFTRAG_LAUFEND_MAX_STUNDEN * 3600);
        // Nur ein noch gültiger ('wartend' nicht abgelaufen) oder noch nicht hängender
        // ('laufend' nicht älter als 3 h) Auftrag wird wiederverwendet (Befund Runde 2).
        $stmt = $this->db->prepare(
            "SELECT id FROM job_auftraege
              WHERE job = ?
                AND (
                    (status = 'wartend' AND (ablaufAm = '' OR ablaufAm >= ?))
                 OR (status = 'laufend' AND aktualisiertAm >= ?)
                )
              ORDER BY erstelltAm ASC LIMIT 1"
        );
        $stmt->execute([$job, $jetzt, $grenzeLaufend]);
        $bestehendeId = $stmt->fetchColumn();
        if ($bestehendeId !== false) {
            jsonOut(['ok' => true, 'id' => (string)$bestehendeId]);
        }

        $id = bin2hex(random_bytes(16));
        $ablaufAm = date('Y-m-d H:i:s', time() + self::AUFTRAG_GUELTIGKEIT_SEKUNDEN);
        $this->db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES (?, ?, 'wartend', ?, ?, ?, ?, '', '{}')"
        )->execute([$id, $job, $jetzt, $ablaufAm, $jetzt, (string)($_SESSION['username'] ?? '')]);
        AuditService::log('job_angefordert', 'Job=' . $job . ' Auftrag=' . $id);
        jsonOut(['ok' => true, 'id' => $id]);
    }

    /** job_auftrag_status (GET id) – liefert nie payload. */
    public function auftragStatus(): void
    {
        Auth::requireRole('admin');
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            jsonOut(['error' => 'id erforderlich.'], 400);
        }
        $stmt = $this->db->prepare('SELECT status, ergebnis FROM job_auftraege WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonOut(['error' => 'Auftrag nicht gefunden.'], 404);
        }
        jsonOut([
            'ok'       => true,
            'status'   => $row['status'],
            'ergebnis' => json_decode((string)$row['ergebnis'], true) ?? [],
        ]);
    }
}
