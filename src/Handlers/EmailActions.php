<?php
namespace App\Handlers;

use App\Auth;
use App\Services\AuditService;
use App\Services\MailService;
use App\Services\ZugferdService;
use Dompdf\Dompdf;
use Dompdf\Options;

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

        $absender   = json_decode($rechnung['absender']   ?? '{}', true) ?: [];
        $positionen = json_decode($rechnung['positionen'] ?? '[]', true) ?: [];
        $kunde      = $this->_loadKunde($rechnung['kundeId'] ?? null);
        $settings   = Auth::loadSettings($this->db);

        if (!$subject) {
            $subject = 'Rechnung ' . ($rechnung['nummer'] ?? '') . ' von ' . ($settings['firma_name'] ?? '');
        }

        $prevErr = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $svc     = new ZugferdService();
            $pdfData = $svc->generate($rechnung, $absender, $positionen, $kunde, $settings);
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
        $rechnungId = (int)($this->body['rechnungId'] ?? 0);
        if ($rechnungId <= 0) \jsonOut(['error' => 'rechnungId fehlt.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE id = ? AND typ = 'angebot'");
        $stmt->execute([$rechnungId]);
        $angebot = $stmt->fetch();
        if (!$angebot) \jsonOut(['error' => 'Angebot nicht gefunden.'], 404);

        $absender   = json_decode($angebot['absender']   ?? '{}', true) ?: [];
        $positionen = json_decode($angebot['positionen'] ?? '[]', true) ?: [];
        $kunde      = $this->_loadKunde($angebot['kundeId'] ?? null);
        $settings   = Auth::loadSettings($this->db);

        if (!$subject) {
            $subject = 'Angebot ' . ($angebot['nummer'] ?? '') . ' von ' . ($settings['firma_name'] ?? '');
        }

        $prevErr = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $pdfData = $this->_generateAngebotPdf($angebot, $absender, $positionen, $kunde, $settings);
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

    // ── Interne PDF-Erzeugung (Angebot) ───────────────────────────────────

    private function _generateAngebotPdf(array $angebot, array $absender, array $positionen, ?array $kunde, array $s): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('Dompdf nicht verfügbar.');
        }

        $h   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = fn($v): string => $v !== null ? number_format((float)$v, 2, ',', '.') : '–';

        $firmaName    = $h($absender['firma']   ?? $s['firma_name']    ?? '');
        $firmaStrasse = $h($absender['strasse'] ?? $s['firma_strasse'] ?? '');
        $firmaPlzOrt  = $h(trim(($absender['plz'] ?? $s['firma_plz'] ?? '') . ' ' . ($absender['ort'] ?? $s['firma_ort'] ?? '')));
        $firmaTel     = $h($s['firma_telefon'] ?? '');
        $firmaEmail   = $h($s['firma_email']   ?? '');
        $firmaUstId   = $h($s['firma_ustid']   ?? '');

        $kundeName    = '';
        $kundeAdr     = '';
        if ($kunde) {
            $kundeName = $h(trim(($kunde['firma'] ?: '') ?: (($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? ''))));
            $kundeAdr  = $h(trim(($kunde['strasse'] ?? '') . ', ' . ($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? '')));
        }

        $nummer     = $h($angebot['nummer']      ?? '');
        $datum      = $angebot['datum']           ?? date('Y-m-d');
        $datumFmt   = date('d.m.Y', strtotime($datum));
        $beschr     = $h($angebot['beschreibung'] ?? '');
        $notizen    = $h($angebot['notizen']      ?? '');

        // Positionen-Tabelle – mit Hierarchie (posTyp='gruppe' → Oberpositionen)
        $posHtml  = '';
        $sumNetto = 0.0;
        $groupIds = [];
        foreach ($positionen as $pos) {
            if (($pos['posTyp'] ?? '') === 'gruppe' && !empty($pos['id'])) {
                $groupIds[$pos['id']] = true;
            }
        }

        // Top-Level-Elemente (keine parentId oder parentId nicht in groupIds)
        $topLevel = array_values(array_filter($positionen, function ($p) use ($groupIds) {
            $pid = $p['parentId'] ?? null;
            return !$pid || !isset($groupIds[$pid]);
        }));

        $topNr = 0;
        foreach ($topLevel as $pos) {
            $topNr++;
            $posTyp = $pos['posTyp'] ?? 'normal';

            if ($posTyp === 'gruppe') {
                // Unterpositionen sammeln
                $posId = $pos['id'] ?? '';
                $subs  = array_values(array_filter($positionen, fn($p) => ($p['parentId'] ?? '') === $posId));
                $grpSum = 0.0;
                foreach ($subs as $sub) {
                    if (($sub['posTyp'] ?? 'normal') === 'normal') {
                        $grpSum += (float)($sub['menge'] ?? 0) * (float)($sub['einzelpreis'] ?? 0);
                    }
                }
                $sumNetto += $grpSum;
                // Gruppen-Kopfzeile
                $posHtml .= '<tr style="background:#dbeeff;">'
                    . '<td style="padding:4px 6px;font-weight:bold;color:#1565C0;">' . $topNr . '.</td>'
                    . '<td colspan="3" style="padding:4px 6px;font-weight:bold;color:#1565C0;">' . $h($pos['bezeichnung'] ?? '') . '</td>'
                    . '<td style="text-align:right;padding:4px 6px;font-weight:bold;color:#1565C0;">' . $fmt($grpSum) . ' €</td>'
                    . '</tr>';
                // Unterpositionen
                $subNr = 0;
                foreach ($subs as $sub) {
                    $subNr++;
                    $nr  = $topNr . '.' . $subNr;
                    $st  = $sub['posTyp'] ?? 'normal';
                    if ($st === 'freitext') {
                        $posHtml .= '<tr>'
                            . '<td style="padding:3px 6px 3px 16px;font-size:10px;color:#666;">' . $h($nr) . '</td>'
                            . '<td colspan="3" style="padding:3px 6px;font-style:italic;color:#555;font-size:10px;">' . $h($sub['bezeichnung'] ?? '') . '</td>'
                            . '<td></td></tr>';
                    } else {
                        $sm = (float)($sub['menge'] ?? 0);
                        $se = (float)($sub['einzelpreis'] ?? 0);
                        $sg = $sm * $se;
                        $posHtml .= '<tr>'
                            . '<td style="padding:3px 6px 3px 16px;font-size:10px;">' . $h($nr) . '</td>'
                            . '<td style="padding:3px 6px;">' . $h($sub['bezeichnung'] ?? '') . '</td>'
                            . '<td style="text-align:right;padding:3px 6px;">' . str_replace('.', ',', (string)$sm) . ' ' . $h($sub['einheit'] ?? '') . '</td>'
                            . '<td style="text-align:right;padding:3px 6px;">' . $fmt($se) . ' €</td>'
                            . '<td style="text-align:right;padding:3px 6px;">' . $fmt($sg) . ' €</td>'
                            . '</tr>';
                    }
                }
                continue;
            }

            // Standalone Positionen (normale Typen)
            if ($posTyp === 'trennlinie') {
                $posHtml .= '<tr><td colspan="5" style="border-top:2px solid #333;padding:2px 0;"></td></tr>';
                continue;
            }
            if ($posTyp === 'zwischensumme') {
                $posHtml .= '<tr><td colspan="4" style="text-align:right;font-weight:bold;padding:4px 6px;">Zwischensumme</td>'
                    . '<td style="text-align:right;font-weight:bold;padding:4px 6px;">' . $fmt($pos['gesamt'] ?? null) . ' €</td></tr>';
                continue;
            }
            if ($posTyp === 'freitext') {
                $posHtml .= '<tr>'
                    . '<td style="padding:4px 6px;">' . $topNr . '.</td>'
                    . '<td colspan="3" style="padding:4px 6px;font-style:italic;color:#555;">' . $h($pos['bezeichnung'] ?? '') . '</td>'
                    . '<td></td></tr>';
                continue;
            }
            $menge  = (float)($pos['menge']       ?? 0);
            $ep     = (float)($pos['einzelpreis']  ?? 0);
            $gesamt = $menge * $ep;
            if ($posTyp !== 'eventual') $sumNetto += $gesamt;
            $posHtml .= '<tr>'
                . '<td style="padding:4px 6px;">' . $topNr . '.</td>'
                . '<td style="padding:4px 6px;">' . $h($pos['bezeichnung'] ?? '') . ($posTyp === 'eventual' ? ' <em>(EP)</em>' : '') . '</td>'
                . '<td style="text-align:right;padding:4px 6px;">' . str_replace('.', ',', (string)$menge) . ' ' . $h($pos['einheit'] ?? '') . '</td>'
                . '<td style="text-align:right;padding:4px 6px;">' . $fmt($ep) . ' €</td>'
                . '<td style="text-align:right;padding:4px 6px;">' . $fmt($gesamt) . ' €</td>'
                . '</tr>';
        }

        $kleinunternehmer = !empty($s['firma_kleinunternehmer']);
        $reverseCharge    = !empty($s['firma_reverse_charge']);
        $mwstSatz  = ($kleinunternehmer || $reverseCharge) ? 0.0 : (float)($s['firma_mwst_satz'] ?? 19);
        $mwstBetrag = $sumNetto * $mwstSatz / 100;
        $sumBrutto  = $sumNetto + $mwstBetrag;

        $ustInfo = $firmaUstId ? '<p style="font-size:10px;color:#555;">USt-IdNr.: ' . $firmaUstId . '</p>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;font-size:11px;color:#222;margin:0;padding:20px;}
  table{width:100%;border-collapse:collapse;}
  th{background:#4A90D9;color:#fff;padding:6px 6px;text-align:left;}
  tr:nth-child(even){background:#f5f7fa;}
  .total-row td{font-weight:bold;border-top:2px solid #333;}
  h2{color:#4A90D9;}
</style>
</head>
<body>
<table style="margin-bottom:20px;">
  <tr>
    <td style="width:60%;vertical-align:top;">
      <strong style="font-size:16px;color:#4A90D9;">ANGEBOT</strong><br>
      Angebots-Nr.: <strong>{$nummer}</strong><br>
      Datum: {$datumFmt}
    </td>
    <td style="text-align:right;vertical-align:top;">
      <strong>{$firmaName}</strong><br>
      {$firmaStrasse}<br>{$firmaPlzOrt}<br>
      {$firmaTel}<br>{$firmaEmail}
    </td>
  </tr>
</table>

HTML;
        if ($kundeName) {
            $html .= "<p><strong>An:</strong><br>{$kundeName}<br>{$kundeAdr}</p>";
        }
        if ($beschr) {
            $html .= "<p><strong>Betreff:</strong> {$beschr}</p>";
        }
        $html .= <<<HTML
<table>
  <thead>
    <tr>
      <th style="width:8%;">Pos.</th>
      <th style="width:50%;">Bezeichnung</th>
      <th style="width:12%;text-align:right;">Menge</th>
      <th style="width:15%;text-align:right;">Einzelpreis</th>
      <th style="width:15%;text-align:right;">Gesamt</th>
    </tr>
  </thead>
  <tbody>
    {$posHtml}
  </tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="4" style="text-align:right;padding:6px;">Netto-Summe</td>
      <td style="text-align:right;padding:6px;">{$fmt($sumNetto)} €</td>
    </tr>
    <tr>
      <td colspan="4" style="text-align:right;padding:4px 6px;">MwSt. {$mwstSatz}%</td>
      <td style="text-align:right;padding:4px 6px;">{$fmt($mwstBetrag)} €</td>
    </tr>
    <tr class="total-row">
      <td colspan="4" style="text-align:right;padding:6px;font-size:13px;">Gesamt brutto</td>
      <td style="text-align:right;padding:6px;font-size:13px;">{$fmt($sumBrutto)} €</td>
    </tr>
  </tfoot>
</table>
HTML;
        if ($notizen) {
            $html .= "<p style='margin-top:16px;'><strong>Anmerkungen:</strong><br>{$notizen}</p>";
        }
        $html .= $ustInfo . '</body></html>';

        $opts = new Options();
        $opts->setIsRemoteEnabled(false);
        $opts->setIsHtml5ParserEnabled(true);
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return $pdf->output();
    }
}
