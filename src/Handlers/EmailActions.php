<?php
namespace App\Handlers;

use App\Auth;
use App\Services\AuditService;
use App\Services\BelegPdfService;
use App\Services\MailService;
use App\Services\ZugferdService;

/**
 * E-Mail-Versand für Dokumente (VDE-Protokolle, Rechnungen, Angebote, DIN 1090).
 * SMTP-Konfiguration kommt aus den App-Einstellungen (Admin-Bereich).
 */
class EmailActions
{
    public function __construct(private \PDO $db, private array $body) {}

    // ── Hilfsmethoden ──────────────────────────────────────────────────────

    /** Liest SMTP-Konfiguration aus App-Einstellungen. */
    private function _cfg(): array
    {
        $s = Auth::loadSettings($this->db);
        return [
            'smtp_host'       => $s['smtp_host']       ?? '',
            'smtp_port'       => $s['smtp_port']        ?? '587',
            'smtp_user'       => $s['smtp_user']        ?? '',
            'smtp_pass'       => $s['smtp_pass']        ?? '',
            'smtp_from_email' => $s['smtp_from_email']  ?? $s['firma_email'] ?? '',
            'smtp_from_name'  => $s['smtp_from_name']   ?? $s['firma_name']  ?? '',
            'smtp_security'   => $s['smtp_security']    ?? 'tls',
            'firma_email'     => $s['firma_email']      ?? '',
            'firma_name'      => $s['firma_name']       ?? '',
        ];
    }

    /** Validiert Empfänger-Adresse und gibt [$to, $subject] zurück. */
    private function _params(): array
    {
        $to      = trim($this->body['to'] ?? '');
        $subject = trim($this->body['subject'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            \jsonOut(['error' => 'Keine gültige Empfänger-E-Mail-Adresse angegeben.'], 400);
        }
        return [$to, $subject];
    }

    /** Kunden-Datensatz laden (oder null). */
    private function _loadKunde(?int $kundeId): ?array
    {
        if (!$kundeId) return null;
        $stmt = $this->db->prepare("SELECT * FROM kunden WHERE id = ?");
        $stmt->execute([$kundeId]);
        return $stmt->fetch() ?: null;
    }

    // ── VDE 0100 ───────────────────────────────────────────────────────────

    /** Prüfprotokoll als PDF per E-Mail senden. */
    public function sendVde(): void
    {
        Auth::requireAuth();
        [$to, $subject] = $this->_params();
        $protokollId = (int)($this->body['protokollId'] ?? 0);
        if ($protokollId <= 0) \jsonOut(['error' => 'protokollId fehlt.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM vde0100_protokolle WHERE id = ?");
        $stmt->execute([$protokollId]);
        $protokoll = $stmt->fetch();
        if (!$protokoll) \jsonOut(['error' => 'Prüfprotokoll nicht gefunden.'], 404);

        if (!$subject) {
            $subject = 'VDE 0100 Prüfprotokoll – ' . ($protokoll['titel'] ?? 'Protokoll');
        }

        // PDF-Daten über das VDE-Modul erzeugen
        $manifestPath = MODULES_DIR . 'vde0100/module.json';
        $manifest     = file_exists($manifestPath)
            ? (json_decode(file_get_contents($manifestPath), true) ?? [])
            : [];
        $mod = new \App\Modules\Vde0100\Module($this->db, $manifest, ['id' => $protokollId]);
        try {
            $prevErr = error_reporting(E_ALL & ~E_DEPRECATED);
            $result  = $mod->getPdfData($protokollId);
            error_reporting($prevErr);
        } catch (\RuntimeException $e) {
            \jsonOut(['error' => 'PDF konnte nicht erzeugt werden: ' . $e->getMessage()], 500);
        }

        $kunde = $this->_loadKunde($protokoll['kundeId'] ?? null);
        $cfg   = $this->_cfg();

        $htmlBody = '<p>Sehr geehrte Damen und Herren,</p>'
            . '<p>im Anhang erhalten Sie das VDE 0100 Prüfprotokoll <strong>'
            . htmlspecialchars($protokoll['titel'] ?? '', ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>'
            . '<p>Bei Fragen stehen wir Ihnen gerne zur Verfügung.</p>'
            . '<p>Mit freundlichen Grüßen<br>' . htmlspecialchars($cfg['firma_name'], ENT_QUOTES, 'UTF-8') . '</p>';

        try {
            MailService::send($cfg, $to, $kunde['firma'] ?? '', $subject, $htmlBody, $result['pdfData'], $result['fileName']);
        } catch (\RuntimeException $e) {
            \jsonOut(['error' => $e->getMessage()], 500);
        }

        AuditService::log('email_vde', 'VDE-Protokoll ' . $protokollId . ' per E-Mail gesendet an ' . $to);
        \jsonOut(['ok' => true]);
    }

    // ── Rechnungen ─────────────────────────────────────────────────────────

    /** ZUGFeRD-Rechnung per E-Mail senden. */
    public function sendRechnung(): void
    {
        Auth::requireAuth();
        [$to, $subject] = $this->_params();
        $rechnungId = (int)($this->body['rechnungId'] ?? 0);
        if ($rechnungId <= 0) \jsonOut(['error' => 'rechnungId fehlt.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE id = ? AND typ = 'rechnung'");
        $stmt->execute([$rechnungId]);
        $rechnung = $stmt->fetch();
        if (!$rechnung) \jsonOut(['error' => 'Rechnung nicht gefunden.'], 404);

        $daten    = BelegPdfService::belegDaten($this->db, $rechnung);
        $kunde    = $daten['kunde'];
        $settings = Auth::loadSettings($this->db);

        if (!$subject) {
            $subject = 'Rechnung ' . ($rechnung['nummer'] ?? '') . ' von ' . ($settings['firma_name'] ?? '');
        }

        $prevErr = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $svc     = new ZugferdService();
            $pdfData = $svc->generate(
                $rechnung,
                $daten['beleg']['absender'],
                $daten['positionen'],
                $kunde,
                $settings,
                $daten['beleg']['baustelleName']
            );
        } catch (\RuntimeException $e) {
            error_reporting($prevErr);
            \jsonOut(['error' => 'PDF konnte nicht erzeugt werden: ' . $e->getMessage()], 500);
        } finally {
            error_reporting($prevErr);
        }

        $cfg      = $this->_cfg();
        $fileName = ($rechnung['nummer'] ?? 'Rechnung') . '.pdf';

        $htmlBody = '<p>Sehr geehrte Damen und Herren,</p>'
            . '<p>im Anhang erhalten Sie Ihre Rechnung <strong>'
            . htmlspecialchars($rechnung['nummer'] ?? '', ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>'
            . '<p>Bei Fragen stehen wir Ihnen gerne zur Verfügung.</p>'
            . '<p>Mit freundlichen Grüßen<br>' . htmlspecialchars($cfg['firma_name'], ENT_QUOTES, 'UTF-8') . '</p>';

        try {
            MailService::send($cfg, $to, $kunde['firma'] ?? '', $subject, $htmlBody, $pdfData, $fileName);
        } catch (\RuntimeException $e) {
            \jsonOut(['error' => $e->getMessage()], 500);
        }

        AuditService::log('email_rechnung', 'Rechnung ' . $rechnungId . ' per E-Mail gesendet an ' . $to);
        \jsonOut(['ok' => true]);
    }

    // ── Angebote ───────────────────────────────────────────────────────────

    /** Angebot als einfaches PDF per E-Mail senden. */
    public function sendAngebot(): void
    {
        Auth::requireAuth();
        [$to, $subject] = $this->_params();
        // Der Client sendet `angebotId`; `rechnungId` bleibt für ältere Aufrufer gültig.
        $rechnungId = (int)($this->body['angebotId'] ?? $this->body['rechnungId'] ?? 0);
        if ($rechnungId <= 0) \jsonOut(['error' => 'angebotId fehlt.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE id = ? AND typ = 'angebot'");
        $stmt->execute([$rechnungId]);
        $angebot = $stmt->fetch();
        if (!$angebot) \jsonOut(['error' => 'Angebot nicht gefunden.'], 404);

        $kunde    = $this->_loadKunde(isset($angebot['kundeId']) ? (int)$angebot['kundeId'] : null);
        $settings = Auth::loadSettings($this->db);

        if (!$subject) {
            $subject = 'Angebot ' . ($angebot['nummer'] ?? '') . ' von ' . ($settings['firma_name'] ?? '');
        }

        $prevErr = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $pdfData = (new BelegPdfService())->pdfFuerBeleg($this->db, $angebot, $settings);
        } catch (\RuntimeException $e) {
            error_reporting($prevErr);
            \jsonOut(['error' => 'PDF konnte nicht erzeugt werden: ' . $e->getMessage()], 500);
        } finally {
            error_reporting($prevErr);
        }

        $cfg      = $this->_cfg();
        $fileName = ($angebot['nummer'] ?? 'Angebot') . '.pdf';

        $htmlBody = '<p>Sehr geehrte Damen und Herren,</p>'
            . '<p>im Anhang erhalten Sie unser Angebot <strong>'
            . htmlspecialchars($angebot['nummer'] ?? '', ENT_QUOTES, 'UTF-8')
            . '</strong>.</p>'
            . '<p>Bei Fragen stehen wir Ihnen gerne zur Verfügung.</p>'
            . '<p>Mit freundlichen Grüßen<br>' . htmlspecialchars($cfg['firma_name'], ENT_QUOTES, 'UTF-8') . '</p>';

        try {
            MailService::send($cfg, $to, $kunde['firma'] ?? '', $subject, $htmlBody, $pdfData, $fileName);
        } catch (\RuntimeException $e) {
            \jsonOut(['error' => $e->getMessage()], 500);
        }

        AuditService::log('email_angebot', 'Angebot ' . $rechnungId . ' per E-Mail gesendet an ' . $to);
        \jsonOut(['ok' => true]);
    }

    // ── DIN EN 1090 ────────────────────────────────────────────────────────

    /** DIN 1090-Protokoll-PDF (client-seitig erzeugt) per E-Mail senden. */
    public function sendDin1090(): void
    {
        Auth::requireAuth();
        [$to, $subject] = $this->_params();
        $pdfBase64 = trim($this->body['pdf_base64'] ?? '');
        if (!$pdfBase64) \jsonOut(['error' => 'pdf_base64 fehlt.'], 400);

        $pdfData = base64_decode($pdfBase64, true);
        if ($pdfData === false || strlen($pdfData) < 100) {
            \jsonOut(['error' => 'pdf_base64 ist ungültig oder beschädigt.'], 400);
        }

        if (!$subject) $subject = 'DIN EN 1090 Protokoll';

        $cfg = $this->_cfg();
        $htmlBody = '<p>Sehr geehrte Damen und Herren,</p>'
            . '<p>im Anhang erhalten Sie das DIN EN 1090 Protokoll.</p>'
            . '<p>Mit freundlichen Grüßen<br>' . htmlspecialchars($cfg['firma_name'], ENT_QUOTES, 'UTF-8') . '</p>';

        try {
            MailService::send($cfg, $to, '', $subject, $htmlBody, $pdfData, 'DIN1090_Protokoll.pdf');
        } catch (\RuntimeException $e) {
            \jsonOut(['error' => $e->getMessage()], 500);
        }

        AuditService::log('email_din1090', 'DIN 1090-Protokoll per E-Mail gesendet an ' . $to);
        \jsonOut(['ok' => true]);
    }

    // ── SMTP-Test ──────────────────────────────────────────────────────────

    /** Sendet eine Test-E-Mail an die Firma-E-Mail-Adresse. */
    public function testSmtp(): void
    {
        Auth::requireRole('admin', 'master');
        $cfg = $this->_cfg();
        $to  = $this->body['to'] ?? $cfg['firma_email'];
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            \jsonOut(['error' => 'Keine gültige Empfänger-E-Mail-Adresse gefunden. Bitte Firma-E-Mail in den Einstellungen prüfen.'], 400);
        }

        $subject  = 'SMTP-Verbindungstest – ' . ($cfg['firma_name'] ?: 'Baukalkulation');
        $htmlBody = '<p>Dies ist eine automatische Testnachricht zur Überprüfung der SMTP-Konfiguration.</p>'
            . '<p>Wenn Sie diese E-Mail erhalten, ist der E-Mail-Versand korrekt konfiguriert.</p>'
            . '<p><em>Baukalkulation_ES – ' . date('d.m.Y H:i') . '</em></p>';

        try {
            MailService::send($cfg, $to, '', $subject, $htmlBody);
        } catch (\RuntimeException $e) {
            // Fehlgeschlagener SMTP-Verbindungstest ist ein Upstream-/Konfigurations-
            // problem des Mailservers, kein interner App-Fehler → 502 statt 500,
            // damit Monitoring/Tests es nicht als Server-Crash werten.
            \jsonOut(['error' => 'SMTP-Test fehlgeschlagen: ' . $e->getMessage()], 502);
        }

        \jsonOut(['ok' => true, 'sentTo' => $to]);
    }
}
