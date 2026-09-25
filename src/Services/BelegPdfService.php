<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Einheitlicher Beleg-PDF-Renderer (Rechnung + Angebot).
 *
 * Erzeugt Dompdf-sicheres HTML (keine Flexbox/Grid, nur Tabellen-/Float-Layout)
 * mit voller optischer Parität zur Client-Vorschau (previewRechnung in script.js).
 *
 * Wird genutzt von:
 *  – ZugferdService::generate()       → Rechnung/ZUGFeRD-PDF
 *  – ExportActions::renderBelegPdf()  → Vorschau (inline) + "In Dateien speichern"
 *  – EmailActions::sendAngebot()      → Angebot per E-Mail
 *
 * @since v2.10.54
 */
class BelegPdfService
{
    /**
     * Einheitliches HTML für Rechnung oder Angebot.
     * Optisch identisch mit der Browser-Vorschau (previewRechnung in script.js).
     *
     * @param  array       $beleg       Belegdaten: nummer, datum, faelligAm,
     *                                  beschreibung, notizen, baustelleName,
     *                                  absender[] (Firmen-Override, leer = Settings)
     * @param  array       $positionen  Positions-Array aus dem Frontend
     * @param  array|null  $kunde       Kundenstammsatz oder null
     * @param  array       $settings    App-Settings (firma_*, firma_kleinunternehmer, …)
     * @param  string      $typ         'rechnung' | 'angebot'
     * @return string                   Vollständiges HTML-Dokument (UTF-8)
     */
    public function buildHtml(
        array  $beleg,
        array  $positionen,
        ?array $kunde,
        array  $settings,
        string $typ
    ): string {
        $h   = fn(string $v): string => htmlspecialchars(trim((string)$v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = fn($v): string => number_format((float)$v, 2, ',', '.');

        // ── Firmendaten ───────────────────────────────────────────────────────
        $absender    = is_array($beleg['absender'] ?? null) ? $beleg['absender'] : [];
        $firmaName   = $h($absender['firma']   ?? $settings['firma_name']    ?? '');
        $firmaStr    = $h($absender['strasse'] ?? $settings['firma_strasse'] ?? '');
        $firmaPlzOrt = $h(trim(
            ($absender['plz'] ?? $settings['firma_plz'] ?? '') . ' ' .
            ($absender['ort'] ?? $settings['firma_ort'] ?? '')
        ));
        $firmaUstId  = $h($settings['firma_ustid']    ?? '');
        $firmaStNr   = $h($settings['firma_steuernr'] ?? '');
        $firmaIban   = $h($settings['firma_iban']     ?? '');
        $firmaBic    = $h($settings['firma_bic']      ?? '');
        $firmaBank   = $h($settings['firma_bankname'] ?? '');

        // ── Steuermodus ───────────────────────────────────────────────────────
        $kleinunternehmer = !empty($settings['firma_kleinunternehmer']);
        $reverseCharge    = !empty($settings['firma_reverse_charge']);
        $effMwstSatz      = ($kleinunternehmer || $reverseCharge)
            ? 0.0 : (float)($settings['firma_mwst_satz'] ?? 19.0);
        $rcText = $settings['firma_reverse_charge_text']
            ?? 'Steuerschuldnerschaft des Leistungsempfängers gemäß § 13b UStG (Reverse Charge).';

        // ── Empfänger ─────────────────────────────────────────────────────────
        $kundeAdrHtml = '';
        if ($kunde) {
            $parts = array_filter([
                (string)($kunde['firma'] ?? ''),
                trim(($kunde['anrede'] ?? '') . ' ' . ($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? '')),
                (string)($kunde['strasse'] ?? ''),
                trim(($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? '')),
            ]);
            $kundeAdrHtml = implode(
                '<br>',
                array_map(fn($p) => $h((string)$p), array_filter($parts, fn($p) => trim((string)$p) !== ''))
            );
        }
        $kundeNr = $kunde ? $h((string)($kunde['kundennummer'] ?? '')) : '';

        // ── Belegdaten ────────────────────────────────────────────────────────
        $nummer   = $h($beleg['nummer'] ?? '');
        $datum    = (string)($beleg['datum'] ?? date('Y-m-d'));
        $datumFmt = (@date('d.m.Y', strtotime($datum))) ?: $datum;
        $fDatum   = '';
        if (!empty($beleg['faelligAm'])) {
            $fDatum = (@date('d.m.Y', strtotime((string)$beleg['faelligAm']))) ?: (string)$beleg['faelligAm'];
        }
        $beschr  = $h($beleg['beschreibung'] ?? '');
        $notizen = (string)($beleg['notizen'] ?? '');
        $bsName  = $h($beleg['baustelleName'] ?? '');
        $titel   = $typ === 'rechnung' ? 'Rechnung' : 'Angebot';

        // ── Logo als Base64-Data-URI ──────────────────────────────────────────
        $logoHtml = '';
        $logoUrl  = $settings['firma_logo_url'] ?? '';
        if ($logoUrl) {
            $logoPath = realpath(__DIR__ . '/../../' . ltrim((string)$logoUrl, '/'));
            if ($logoPath && file_exists($logoPath)) {
                $mime = '';
                if (class_exists('finfo')) {
                    try { $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($logoPath) ?: ''; }
                    catch (\Throwable $ignored) {}
                }
                if (!$mime) {
                    $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                                'png' => 'image/png',  'gif'  => 'image/gif',
                                'webp'=> 'image/webp'];
                    $mime = $mimeMap[$ext] ?? 'image/png';
                }
                $logoHtml = '<img src="data:' . $mime . ';base64,'
                    . base64_encode((string)file_get_contents($logoPath))
                    . '" style="max-height:22mm;max-width:60mm;" /><br>';
            }
        }

        // ── Positionen aufbereiten ────────────────────────────────────────────
        $groupIds = [];
        foreach ($positionen as $pos) {
            if (($pos['posTyp'] ?? '') === 'gruppe' && !empty($pos['id'])) {
                $groupIds[(string)$pos['id']] = true;
            }
        }
        $topLevel = array_values(array_filter(
            $positionen,
            fn($p) => empty($p['parentId']) || !isset($groupIds[(string)$p['parentId']])
        ));

        $posRows    = '';
        $sumNetto   = 0.0;
        $eventNetto = 0.0;

        /** Netto-GP unter Berücksichtigung von Rabatt */
        $posNetto = function (array $p): float {
            $m = (float)($p['menge']       ?? 0);
            $e = (float)($p['einzelpreis'] ?? 0);
            $r = min(100.0, max(0.0, (float)($p['rabatt'] ?? 0)));
            return $m * $e * (1.0 - $r / 100.0);
        };

        $rabNote = function (array $p): string {
            $r = (float)($p['rabatt'] ?? 0);
            return $r > 0
                ? '<br><span style="font-size:8pt;color:#888;">abzgl. '
                  . number_format($r, 0, ',', '') . '&#37; Rabatt</span>'
                : '';
        };

        $topNr = 0;
        foreach ($topLevel as $pos) {
            $topNr++;
            $posTyp = $pos['posTyp'] ?? 'normal';

            if ($posTyp === 'trennlinie') {
                $posRows .= '<tr><td colspan="6" style="border-top:1.5pt solid #333;'
                    . 'padding:1px 0;font-size:0;line-height:0;"></td></tr>';
                continue;
            }
            if ($posTyp === 'zwischensumme') {
                $posRows .= '<tr>'
                    . '<td colspan="5" style="text-align:right;font-weight:bold;'
                    . 'padding:3px 3mm;border-bottom:none;">Zwischensumme</td>'
                    . '<td style="text-align:right;font-weight:bold;padding:3px 3mm;'
                    . 'border-bottom:none;">' . $fmt($pos['gesamt'] ?? 0) . '</td>'
                    . '</tr>';
                continue;
            }
            if ($posTyp === 'freitext') {
                $posRows .= '<tr>'
                    . '<td style="text-align:center;padding:3px 3mm;">' . $topNr . '.</td>'
                    . '<td colspan="5" style="font-style:italic;color:#555;padding:3px 3mm;">'
                    . $h($pos['bezeichnung'] ?? '') . '</td>'
                    . '</tr>';
                continue;
            }
            if ($posTyp === 'gruppe') {
                $posId  = (string)($pos['id'] ?? '');
                $subs   = array_values(array_filter(
                    $positionen,
                    fn($p) => (string)($p['parentId'] ?? '') === $posId
                ));
                $grpSum = 0.0;
                foreach ($subs as $sub) {
                    if (($sub['posTyp'] ?? 'normal') === 'normal') {
                        $grpSum += $posNetto($sub);
                    }
                }
                $sumNetto += $grpSum;
                $posRows .= '<tr style="background:#dbeeff;">'
                    . '<td style="padding:3px 3mm;font-weight:bold;color:#1565C0;">' . $topNr . '.</td>'
                    . '<td colspan="4" style="padding:3px 3mm;font-weight:bold;color:#1565C0;">'
                    . $h($pos['bezeichnung'] ?? '') . '</td>'
                    . '<td style="text-align:right;padding:3px 3mm;font-weight:bold;color:#1565C0;">'
                    . $fmt($grpSum) . '</td>'
                    . '</tr>';
                $subNr = 0;
                foreach ($subs as $sub) {
                    $subNr++;
                    $nr = $topNr . '.' . $subNr;
                    $st = $sub['posTyp'] ?? 'normal';
                    if ($st === 'freitext') {
                        $posRows .= '<tr>'
                            . '<td style="padding:2px 3mm 2px 7mm;font-size:9pt;color:#555;">' . $h($nr) . '</td>'
                            . '<td colspan="5" style="font-style:italic;color:#555;padding:2px 3mm;font-size:9pt;">'
                            . $h($sub['bezeichnung'] ?? '') . '</td>'
                            . '</tr>';
                    } elseif ($st === 'eventual') {
                        $sg = $posNetto($sub);
                        $posRows .= '<tr style="color:#888;font-style:italic;">'
                            . '<td style="padding:2px 3mm 2px 7mm;font-size:9pt;">' . $h($nr) . '</td>'
                            . '<td style="padding:2px 3mm;font-size:9pt;">'
                            . $h($sub['bezeichnung'] ?? '') . ' <em>(EP)</em>' . $rabNote($sub) . '</td>'
                            . '<td style="text-align:center;padding:2px 3mm;font-size:9pt;">' . $fmt($sub['menge'] ?? 0) . '</td>'
                            . '<td style="text-align:center;padding:2px 3mm;font-size:9pt;">' . $h($sub['einheit'] ?? '') . '</td>'
                            . '<td style="text-align:right;padding:2px 3mm;font-size:9pt;">' . $fmt($sub['einzelpreis'] ?? 0) . '</td>'
                            . '<td style="text-align:right;padding:2px 3mm;font-size:9pt;">' . $fmt($sg) . '</td>'
                            . '</tr>';
                    } else {
                        $sg = $posNetto($sub);
                        $posRows .= '<tr>'
                            . '<td style="padding:2px 3mm 2px 7mm;font-size:9pt;">' . $h($nr) . '</td>'
                            . '<td style="padding:2px 3mm;">' . $h($sub['bezeichnung'] ?? '') . $rabNote($sub) . '</td>'
                            . '<td style="text-align:center;padding:2px 3mm;">' . $fmt($sub['menge'] ?? 0) . '</td>'
                            . '<td style="text-align:center;padding:2px 3mm;">' . $h($sub['einheit'] ?? '') . '</td>'
                            . '<td style="text-align:right;padding:2px 3mm;">' . $fmt($sub['einzelpreis'] ?? 0) . '</td>'
                            . '<td style="text-align:right;padding:2px 3mm;">' . $fmt($sg) . '</td>'
                            . '</tr>';
                    }
                }
                continue;
            }
            if ($posTyp === 'eventual') {
                $gp = $posNetto($pos);
                $eventNetto += $gp;
                $posRows .= '<tr style="color:#888;font-style:italic;">'
                    . '<td style="text-align:center;padding:3px 3mm;">' . $topNr . '.</td>'
                    . '<td style="padding:3px 3mm;">' . $h($pos['bezeichnung'] ?? '')
                    . ' <em>(Eventualposition)</em>' . $rabNote($pos) . '</td>'
                    . '<td style="text-align:center;padding:3px 3mm;">' . $fmt($pos['menge'] ?? 0) . '</td>'
                    . '<td style="text-align:center;padding:3px 3mm;">' . $h($pos['einheit'] ?? '') . '</td>'
                    . '<td style="text-align:right;padding:3px 3mm;">' . $fmt($pos['einzelpreis'] ?? 0) . '</td>'
                    . '<td style="text-align:right;padding:3px 3mm;">' . $fmt($gp) . '</td>'
                    . '</tr>';
                continue;
            }
            // normal
            $gp = $posNetto($pos);
            $sumNetto += $gp;
            $posRows .= '<tr>'
                . '<td style="text-align:center;padding:3px 3mm;">' . $topNr . '.</td>'
                . '<td style="padding:3px 3mm;">' . $h($pos['bezeichnung'] ?? '') . $rabNote($pos) . '</td>'
                . '<td style="text-align:center;padding:3px 3mm;">' . $fmt($pos['menge'] ?? 0) . '</td>'
                . '<td style="text-align:center;padding:3px 3mm;">' . $h($pos['einheit'] ?? '') . '</td>'
                . '<td style="text-align:right;padding:3px 3mm;">' . $fmt($pos['einzelpreis'] ?? 0) . '</td>'
                . '<td style="text-align:right;padding:3px 3mm;">' . $fmt($gp) . '</td>'
                . '</tr>';
        }

        // ── Summen ────────────────────────────────────────────────────────────
        $mwstBetrag  = round($sumNetto * $effMwstSatz / 100, 2);
        $bruttoSumme = round($sumNetto + $mwstBetrag, 2);

        if ($kleinunternehmer) {
            $mwstZeile     = '';
            $steuerHinweis = '<p style="font-size:8.5pt;color:#555;margin-top:4mm;font-style:italic;">'
                . 'Gem&auml;&szlig; &sect; 19 UStG wird keine Umsatzsteuer berechnet.</p>';
        } elseif ($reverseCharge) {
            $mwstZeile = '<tr><td colspan="5" style="text-align:right;padding:2px 3mm;">'
                . 'Umsatzsteuer (Reverse Charge)</td>'
                . '<td style="text-align:right;padding:2px 3mm;">0,00</td></tr>';
            $steuerHinweis = '<p style="font-size:8.5pt;color:#555;margin-top:4mm;font-style:italic;">'
                . $h($rcText) . '</p>';
        } else {
            $mwstZeile = '<tr>'
                . '<td colspan="5" style="text-align:right;padding:2px 3mm;">'
                . 'Umsatzsteuer ' . number_format($effMwstSatz, 0, ',', '') . '&nbsp;%</td>'
                . '<td style="text-align:right;padding:2px 3mm;">' . $fmt($mwstBetrag) . '</td>'
                . '</tr>';
            $steuerHinweis = '';
        }

        $eventualRow = '';
        if ($eventNetto > 0) {
            $eventualRow = '<tr><td colspan="5" style="text-align:right;padding:2px 3mm;'
                . 'color:#888;font-style:italic;font-size:8.5pt;">'
                . 'davon Eventualpositionen (netto, nicht in Summe enthalten)</td>'
                . '<td style="text-align:right;padding:2px 3mm;color:#888;'
                . 'font-style:italic;font-size:8.5pt;">' . $fmt($eventNetto) . '</td></tr>';
        }

        // ── Infoblock (rechts neben Anschriftfeld, DIN 5008) ─────────────────
        $infoRows = '<tr><td class="lbl">' . $titel . '-Nr.:</td><td class="val">' . $nummer . '</td></tr>'
            . '<tr><td class="lbl">Datum:</td><td class="val">' . $datumFmt . '</td></tr>';
        if ($kundeNr !== '') {
            $infoRows .= '<tr><td class="lbl">Kunden-Nr.:</td><td class="val">' . $kundeNr . '</td></tr>';
        }
        if ($bsName !== '') {
            $infoRows .= '<tr><td class="lbl">Projekt:</td><td class="val">' . $bsName . '</td></tr>';
        }
        if ($fDatum) {
            $infoRows .= '<tr><td class="lbl">F&auml;llig:</td><td class="val">' . $fDatum . '</td></tr>';
        }
        $subjectDatum = $datumFmt ? ' vom ' . $datumFmt : '';

        // ── Fußzeile (position:fixed → wiederholt sich auf jeder Seite in Dompdf) ──
        $footerCol1 = $firmaName . '<br>' . $firmaStr . '<br>' . $firmaPlzOrt;
        $footerCol2 = ($firmaStNr ? 'Steuernummer: ' . $firmaStNr : '')
            . ($firmaUstId ? ($firmaStNr ? '<br>' : '') . 'USt-IdNr: ' . $firmaUstId : '');
        $footerCol3 = ($firmaBank ? $firmaBank . '<br>' : '')
            . ($firmaIban ? 'IBAN: ' . $firmaIban . '<br>' : '')
            . ($firmaBic  ? 'BIC: '  . $firmaBic  : '');

        // ── Notizen ───────────────────────────────────────────────────────────
        $notizenHtml = $notizen
            ? '<div style="margin-top:6mm;font-size:9pt;color:#333;">'
              . nl2br($h($notizen)) . '</div>'
            : '';

        // ── ZUGFeRD-Badge (nur für Rechnungen, kleiner Hinweis) ──────────────
        $zugferdBadge = ($typ === 'rechnung')
            ? '<span style="display:inline-block;background:#e8f5e9;color:#2e7d32;'
              . 'padding:1px 5px;border-radius:3px;font-size:7pt;margin-top:2mm;">'
              . 'ZUGFeRD EN&#160;16931</span>'
            : '';

        // ════════════════════════════════════════════════════════════════════
        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><style>
  @page { margin: 20mm 15mm 25mm 20mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt;
         color: #1a1a1a; line-height: 1.4; margin: 0; padding: 0; }
  /* DIN 5008 Falz- und Lochmarken (position:fixed → auf jeder Seite) */
  .foldmark { position: fixed; left: 0; width: 4mm; border-top: 0.5pt solid #ccc; }
  .foldmark-top    { top: 105mm; }
  .foldmark-bottom { top: 210mm; }
  .punchmark { position: fixed; left: 0; top: 148.5mm; width: 6mm;
               border-top: 0.5pt solid #bbb; }
  /* Fußzeile – position:fixed → auf jeder Seite */
  .page-footer { position: fixed; bottom: 0; left: 0; right: 0;
                 border-top: 0.5pt solid #ccc; padding: 2mm 0; }
  .page-footer table { width: 100%; border-collapse: collapse; }
  .page-footer td { padding: 0 3mm; font-size: 7.5pt; color: #666; vertical-align: top; }
  /* Dokument-Header / Briefkopf (DIN 5008, Tabelle statt flex) */
  .letterhead   { text-align: right; margin-bottom: 6mm; }
  .letterhead .firm-name { font-size: 12pt; font-weight: bold; display: block; margin-bottom: 1mm; }
  .letterhead .lh-sub { font-size: 9pt; color: #444; }
  .addr-info    { width: 100%; border-collapse: collapse; margin-bottom: 10mm; }
  .addr-info td { border: none; padding: 0; vertical-align: top; }
  .addr-cell    { width: 55%; }
  .info-cell    { width: 45%; }
  .firm-name    { font-size: 12pt; font-weight: bold; }
  .sender-line  { font-size: 7pt; color: #666; border-bottom: 0.5pt solid #999;
                  padding-bottom: 1mm; margin-bottom: 5mm; display: block; }
  .recipient    { font-size: 11pt; line-height: 1.55; }
  .info-block   { width: 100%; border-collapse: collapse; }
  .info-block td { font-size: 9pt; padding: 0.6mm 0; }
  .info-block td.lbl { color: #555; white-space: nowrap; }
  .info-block td.val { font-weight: bold; text-align: right; }
  h1.doc-title  { font-size: 15pt; margin: 0 0 2mm 0; border: none; }
  .subject      { font-weight: bold; font-size: 10.5pt; margin-bottom: 5mm; }
  .doc-beschr   { font-size: 9.5pt; color: #333; margin-bottom: 6mm; }
  /* Positions-Tabelle */
  table.pos     { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
  table.pos th  { background: #f5f5f5; border-top: 1.5pt solid #333;
                  border-bottom: 1.5pt solid #333; padding: 2mm 3mm;
                  font-size: 8.5pt; font-weight: bold; text-align: left; }
  table.pos td  { padding: 2mm 3mm; border-bottom: 0.5pt solid #ddd; font-size: 9pt; }
  table.pos tr  { page-break-inside: avoid; }
  table.pos th:first-child, table.pos td:first-child { text-align: center; width: 10mm; }
  table.pos th:nth-child(3), table.pos td:nth-child(3) { text-align: center; width: 18mm; }
  table.pos th:nth-child(4), table.pos td:nth-child(4) { text-align: center; width: 18mm; }
  table.pos th:nth-child(5), table.pos td:nth-child(5) { text-align: right;  width: 22mm; }
  table.pos th:nth-child(6), table.pos td:nth-child(6) { text-align: right;  width: 22mm; }
  /* Summen */
  .totals       { width: 50%; border-collapse: collapse; margin-left: auto; margin-bottom: 4mm; }
  .totals td    { padding: 1mm 3mm; font-size: 9pt; }
  .netto-row td { border-top: 1pt solid #333; }
  .brutto-row td{ border-top: 1.5pt solid #333; font-weight: bold; font-size: 10pt; }
  /* Platzhalter für Fußzeile */
  .footer-space { height: 20mm; }
</style></head>
<body>
<div class="foldmark foldmark-top"></div>
<div class="punchmark"></div>
<div class="foldmark foldmark-bottom"></div>

<div class="page-footer">
  <table><tr>
    <td>{$footerCol1}</td>
    <td>{$footerCol2}</td>
    <td>{$footerCol3}</td>
  </tr></table>
</div>

<div class="letterhead">
  {$logoHtml}
  <span class="firm-name">{$firmaName}</span>
  <span class="lh-sub">{$firmaStr}<br>{$firmaPlzOrt}</span>
  {$zugferdBadge}
</div>

<table class="addr-info"><tr>
  <td class="addr-cell">
    <div class="sender-line">{$firmaName}, {$firmaStr}, {$firmaPlzOrt}</div>
    <div class="recipient">{$kundeAdrHtml}</div>
  </td>
  <td class="info-cell">
    <table class="info-block">{$infoRows}</table>
  </td>
</tr></table>

<h1 class="doc-title">{$titel}</h1>
<div class="subject">{$titel} Nr. {$nummer}{$subjectDatum}</div>
HTML;

        if ($beschr) {
            $html .= '<div class="doc-beschr">' . $beschr . '</div>';
        }

        $html .= '<table class="pos"><thead><tr>'
            . '<th>Pos.</th><th>Bezeichnung</th><th>Menge</th>'
            . '<th>Einheit</th><th>Einzel&nbsp;&euro;</th><th>Gesamt&nbsp;&euro;</th>'
            . '</tr></thead><tbody>' . $posRows . '</tbody></table>';

        $html .= '<table class="totals">'
            . '<tr class="netto-row">'
            . '<td colspan="5" style="text-align:right;">Zwischensumme (netto)</td>'
            . '<td style="text-align:right;">' . $fmt($sumNetto) . '</td>'
            . '</tr>'
            . $mwstZeile
            . '<tr class="brutto-row">'
            . '<td colspan="5" style="text-align:right;">Gesamtbetrag</td>'
            . '<td style="text-align:right;">' . $fmt($bruttoSumme) . '</td>'
            . '</tr>'
            . $eventualRow
            . '</table>';

        $html .= $steuerHinweis;
        $html .= $notizenHtml;
        $html .= '<div class="footer-space"></div>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Erzeugt ein Plain-PDF aus HTML via Dompdf.
     * Für Angebote und alle Belege, in die kein ZUGFeRD-XML eingebettet werden soll.
     *
     * @return string PDF-Binärdaten
     * @throws \RuntimeException wenn Dompdf nicht verfügbar ist
     */
    public function generatePdf(string $html): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('Dompdf nicht verfügbar (composer install ausführen).');
        }
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string)$dompdf->output();
    }
}
