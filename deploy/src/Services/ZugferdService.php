<?php
namespace App\Services;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;

class ZugferdService
{
    /** Einheiten-Mapping: Anzeige → UNECE-Code */
    private const UNIT_MAP = [
        'Stk'   => 'C62', 'Stk.'  => 'C62', 'Stück' => 'C62',
        'Std'   => 'HUR', 'Std.'  => 'HUR', 'h'     => 'HUR',
        'm'     => 'MTR', 'lfm'   => 'MTR', 'm²'    => 'MTK', 'm³'    => 'MTQ',
        'kg'    => 'KGM', 't'     => 'TNE', 'l'     => 'LTR',
        'VE'    => 'C62', 'Paar'  => 'C62', 'Set'   => 'C62',
        'psch'  => 'C62', 'Rolle' => 'C62',
    ];

    /**
     * ZUGFeRD-PDF erzeugen (EN16931 / Comfort Profil).
     *
     * @return string  PDF-Daten (binary)
     */
    public function generate(array $rechnung, array $absender, array $positionen, ?array $kunde, array $settings): string
    {
        // ── 1) ZUGFeRD-XML aufbauen ─────────────────────────
        $doc = ZugferdDocumentBuilder::CreateNew(ZugferdProfiles::PROFILE_EN16931);

        $nummer     = $rechnung['nummer'] ?? 'RE-0000';
        $datum      = $rechnung['datum'] ?? date('Y-m-d');
        $faelligAm  = $rechnung['faelligAm'] ?? '';
        $docDate    = \DateTime::createFromFormat('Y-m-d', $datum) ?: new \DateTime();

        // Dokumenteninformation: Nummer, Typ 380=Rechnung, Datum, Währung
        $doc->setDocumentInformation($nummer, "380", $docDate, "EUR");

        // Verkäufer (Firmen­daten)
        $sellerName   = $absender['firma']   ?? $settings['firma_name'] ?? '';
        $sellerStreet = $absender['strasse'] ?? $settings['firma_strasse'] ?? '';
        $sellerPlz    = $absender['plz']     ?? $settings['firma_plz'] ?? '';
        $sellerOrt    = $absender['ort']     ?? $settings['firma_ort'] ?? '';
        $sellerUstId  = $settings['firma_ustid'] ?? '';

        $doc->setDocumentSeller($sellerName ?: 'Firma');
        $doc->setDocumentSellerAddress($sellerStreet, '', '', $sellerPlz, $sellerOrt, 'DE');
        if ($sellerUstId) {
            $doc->addDocumentSellerTaxRegistration('VA', $sellerUstId);
        }
        $steuernr = $settings['firma_steuernr'] ?? '';
        if ($steuernr) {
            $doc->addDocumentSellerTaxRegistration('FC', $steuernr);
        }
        // Mindestens eine Steuerregistrierung ist Pflicht
        if (!$sellerUstId && !$steuernr) {
            throw new \RuntimeException('E-Rechnung nicht möglich: Bitte Steuernummer oder USt-IdNr. in den Firmeneinstellungen hinterlegen.');
        }
        $sellerEmail = $absender['email'] ?? $settings['firma_email'] ?? '';
        if ($sellerEmail) {
            $doc->setDocumentSellerCommunication('EM', $sellerEmail);
        }

        // Käufer (Kunde)
        $buyerName = '';
        if ($kunde) {
            $buyerName = trim(($kunde['firma'] ?: '') ?: (($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? '')));
            $doc->setDocumentBuyer($buyerName);
            $doc->setDocumentBuyerAddress(
                $kunde['strasse'] ?? '', '', '',
                $kunde['plz'] ?? '',
                $kunde['ort'] ?? '',
                'DE'
            );
            if (!empty($kunde['email'])) {
                $doc->setDocumentBuyerCommunication('EM', $kunde['email']);
            }
        } else {
            $doc->setDocumentBuyer('Barverkauf');
        }

        // Zahlungsmethode (Pflichtfeld EN16931)
        $iban = $settings['firma_iban'] ?? '';
        $bic  = $settings['firma_bic'] ?? '';
        if ($iban) {
            $doc->addDocumentPaymentMeanToCreditTransfer($iban, null, null, $bic ?: null);
        } else {
            $doc->addDocumentPaymentMean('10');
        }

        // Zahlungsbedingungen
        if ($faelligAm) {
            $fDate = \DateTime::createFromFormat('Y-m-d', $faelligAm);
            if ($fDate) {
                $zz = $rechnung['zahlungsziel'] ?? '';
                $doc->addDocumentPaymentTerm(
                    $zz ?: 'Zahlbar bis ' . $fDate->format('d.m.Y'),
                    $fDate
                );
            }
        } else {
            $doc->addDocumentPaymentTerm('Sofort fällig');
        }

        // ── Positionen ──────────────────────────────────────
        $nettoSumme = 0.0;
        // Steuerregelung → EN16931-Kategorie, Satz und Befreiungsgrund (BG-23).
        $kleinunternehmer = !empty($settings['firma_kleinunternehmer']);
        $reverseCharge    = !empty($settings['firma_reverse_charge']);
        if ($reverseCharge) {
            $taxCategory         = 'AE'; // VAT Reverse Charge
            $taxRate             = 0.0;
            $taxExemptReason     = $settings['firma_reverse_charge_text']
                ?? 'Steuerschuldnerschaft des Leistungsempfängers gemäß § 13b UStG (Reverse Charge).';
            $taxExemptReasonCode = 'VATEX-EU-AE';
        } elseif ($kleinunternehmer) {
            $taxCategory         = 'E';  // Exempt from tax
            $taxRate             = 0.0;
            $taxExemptReason     = 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmer).';
            $taxExemptReasonCode = null;
        } else {
            $taxCategory         = 'S';  // Standard rate
            $taxRate             = (float)($settings['firma_mwst_satz'] ?? 19.0);
            if ($taxRate <= 0) $taxRate = 19.0;
            $taxExemptReason     = null;
            $taxExemptReasonCode = null;
        }

        foreach ($positionen as $idx => $pos) {
            $bez    = $pos['bezeichnung'] ?? $pos['beschreibung'] ?? 'Position';
            $menge  = (float)($pos['menge'] ?? $pos['anzahl'] ?? 1);
            $ep     = (float)($pos['einzelpreis'] ?? $pos['ep'] ?? 0);
            $typ    = $pos['typ'] ?? 'normal';
            $einheit = $pos['einheit'] ?? 'Stk';

            // Freitext-Positionen haben GP=0, EP=0
            if ($typ === 'freitext') continue;

            $gp = round($menge * $ep, 2);
            $nettoSumme += $gp;
            $uom = self::UNIT_MAP[$einheit] ?? 'C62';

            $doc->addNewPosition(strval($idx + 1));
            $doc->setDocumentPositionProductDetails($bez);
            $doc->setDocumentPositionGrossPrice($ep);
            $doc->setDocumentPositionNetPrice($ep);
            $doc->setDocumentPositionQuantity($menge, $uom);
            $doc->setDocumentPositionLineSummation($gp);
            $doc->addDocumentPositionTax($taxCategory, 'VAT', $taxRate);
        }

        // ── Summen ──────────────────────────────────────────
        $mwstBetrag  = round($nettoSumme * $taxRate / 100, 2);
        $bruttoSumme = round($nettoSumme + $mwstBetrag, 2);

        $doc->setDocumentSummation(
            $bruttoSumme,   // grandTotalAmount
            $bruttoSumme,   // duePayableAmount
            $nettoSumme,    // lineTotalAmount
            0.0,            // chargeTotalAmount
            0.0,            // allowanceTotalAmount
            $nettoSumme,    // taxBasisTotalAmount
            $mwstBetrag,    // taxTotalAmount
            null,           // roundingAmount
            0.0             // totalPrepaidAmount
        );
        $doc->addDocumentTax($taxCategory, 'VAT', $nettoSumme, $mwstBetrag, $taxRate, $taxExemptReason, $taxExemptReasonCode);

        // ── 2) Rechnungs-PDF mit DOMPDF erzeugen ────────────
        $html = $this->buildInvoiceHtml($rechnung, $absender, $positionen, $kunde, $settings, $nettoSumme, $mwstBetrag, $bruttoSumme, $taxRate, $taxExemptReason ?? '');

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        // ── 3) ZUGFeRD-XML in PDF einbetten ─────────────────
        $tmpPdf = tempnam(sys_get_temp_dir(), 'zf_pdf_');
        file_put_contents($tmpPdf, $pdfContent);

        $pdfBuilder = new ZugferdDocumentPdfBuilder($doc, $tmpPdf);
        $pdfBuilder->generateDocument();

        $tmpOut = tempnam(sys_get_temp_dir(), 'zf_out_');
        $pdfBuilder->saveDocument($tmpOut);
        $result = file_get_contents($tmpOut);

        @unlink($tmpPdf);
        @unlink($tmpOut);

        return $result;
    }

    /**
     * Rechnungs-HTML für die PDF-Erzeugung.
     */
    private function buildInvoiceHtml(array $rechnung, array $absender, array $positionen, ?array $kunde, array $settings, float $netto, float $mwst, float $brutto, float $mwstSatz = 19.0, string $steuerHinweis = ''): string
    {
        $nr     = htmlspecialchars($rechnung['nummer'] ?? '', ENT_QUOTES, 'UTF-8');
        $datum  = htmlspecialchars($rechnung['datum'] ?? '', ENT_QUOTES, 'UTF-8');
        $fDatum = htmlspecialchars($rechnung['faelligAm'] ?? '', ENT_QUOTES, 'UTF-8');

        // Absender
        $aFirma   = htmlspecialchars($absender['firma']   ?? $settings['firma_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $aStrasse = htmlspecialchars($absender['strasse'] ?? $settings['firma_strasse'] ?? '', ENT_QUOTES, 'UTF-8');
        $aPlzOrt  = htmlspecialchars(trim(($absender['plz'] ?? $settings['firma_plz'] ?? '') . ' ' . ($absender['ort'] ?? $settings['firma_ort'] ?? '')), ENT_QUOTES, 'UTF-8');
        $aTel     = htmlspecialchars($absender['telefon'] ?? $settings['firma_telefon'] ?? '', ENT_QUOTES, 'UTF-8');
        $aEmail   = htmlspecialchars($absender['email']   ?? $settings['firma_email'] ?? '', ENT_QUOTES, 'UTF-8');

        // Empfänger
        $eName = ''; $eAdresse = '';
        if ($kunde) {
            $eName = htmlspecialchars(trim(($kunde['firma'] ?: '') ?: (($kunde['anrede'] ?? '') . ' ' . ($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? ''))), ENT_QUOTES, 'UTF-8');
            $eAdresse = htmlspecialchars(($kunde['strasse'] ?? '') . "\n" . ($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? ''), ENT_QUOTES, 'UTF-8');
        }

        // Logo
        $logoHtml = '';
        $logoUrl = $settings['firma_logo_url'] ?? '';
        if ($logoUrl) {
            $logoPath = realpath(__DIR__ . '/../../' . $logoUrl);
            if ($logoPath && file_exists($logoPath)) {
                // MIME-Type ermitteln (SEC v2.9.17: finfo statt mime_content_type, Fallback falls ext-fileinfo fehlt)
                $mime = '';
                if (class_exists('finfo')) {
                    try { $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($logoPath) ?: ''; }
                    catch (\Throwable $e) { $mime = ''; }
                }
                if (!$mime) {
                    $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
                    $mime = $mimeMap[$ext] ?? 'image/png';
                }
                $b64  = base64_encode(file_get_contents($logoPath));
                $logoHtml = '<img src="data:' . $mime . ';base64,' . $b64 . '" style="max-height:70px;" />';
            }
        }

        // Positionen-Tabelle
        $posRows = '';
        $posNr = 1;
        foreach ($positionen as $pos) {
            $typ = $pos['typ'] ?? 'normal';
            if ($typ === 'freitext') {
                $posRows .= '<tr><td colspan="5" style="font-style:italic;border:none;padding:4px 8px;">'
                          . htmlspecialchars($pos['bezeichnung'] ?? $pos['beschreibung'] ?? '', ENT_QUOTES, 'UTF-8')
                          . '</td></tr>';
                continue;
            }
            $bez    = htmlspecialchars($pos['bezeichnung'] ?? $pos['beschreibung'] ?? '', ENT_QUOTES, 'UTF-8');
            $menge  = (float)($pos['menge'] ?? $pos['anzahl'] ?? 1);
            $einh   = htmlspecialchars($pos['einheit'] ?? 'Stk', ENT_QUOTES, 'UTF-8');
            $ep     = (float)($pos['einzelpreis'] ?? $pos['ep'] ?? 0);
            $gp     = round($menge * $ep, 2);
            $posRows .= '<tr>'
                      . '<td style="text-align:center;">' . $posNr++ . '</td>'
                      . '<td>' . $bez . '</td>'
                      . '<td style="text-align:center;">' . number_format($menge, 2, ',', '.') . ' ' . $einh . '</td>'
                      . '<td style="text-align:right;">' . number_format($ep, 2, ',', '.') . ' €</td>'
                      . '<td style="text-align:right;">' . number_format($gp, 2, ',', '.') . ' €</td>'
                      . '</tr>';
        }

        $steuernr = htmlspecialchars($settings['firma_steuernr'] ?? '', ENT_QUOTES, 'UTF-8');
        $ustid    = htmlspecialchars($settings['firma_ustid'] ?? '', ENT_QUOTES, 'UTF-8');
        $iban     = htmlspecialchars($settings['firma_iban'] ?? '', ENT_QUOTES, 'UTF-8');
        $bic      = htmlspecialchars($settings['firma_bic'] ?? '', ENT_QUOTES, 'UTF-8');
        $bank     = htmlspecialchars($settings['firma_bankname'] ?? '', ENT_QUOTES, 'UTF-8');

        $mwstSatzFmt = number_format($mwstSatz, 0);
        // Steuerbefreit (Kleinunternehmer/Reverse-Charge) => 0%-Zeile ohne Aufschlag + Hinweis.
        if ($mwstSatz > 0) {
            $mwstRow = '<tr><td class="label">MwSt. ' . $mwstSatzFmt . '%:</td><td class="amount">{FMT_MWST} €</td></tr>';
        } else {
            $mwstRow = '<tr><td class="label">Umsatzsteuer:</td><td class="amount">0,00 €</td></tr>';
        }
        $hinweisHtml = $steuerHinweis !== ''
            ? '<p style="font-size:9pt;color:#555;font-style:italic;margin-top:8px;">' . htmlspecialchars($steuerHinweis, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8">
<style>
  @page { margin: 25mm 20mm 30mm 20mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.4; }
  /* DIN 5008 Falzmarken */
  .foldmark { position: fixed; left: 0; width: 4mm; border-top: 0.5pt solid #ccc; }
  .foldmark-top { top: 105mm; }
  .foldmark-bottom { top: 210mm; }
  .punchmark { position: fixed; left: 0; top: 148.5mm; width: 6mm; border-top: 0.5pt solid #bbb; }
  .header { display: table; width: 100%; margin-bottom: 20px; }
  .header-left { display: table-cell; width: 60%; vertical-align: top; }
  .header-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
  .sender-line { font-size: 7pt; color: #888; border-bottom: 1px solid #888; padding-bottom: 2px; margin-bottom: 8px; }
  .recipient { font-size: 10pt; line-height: 1.5; }
  .meta-table { font-size: 9pt; margin-top: 10px; }
  .meta-table td { padding: 2px 0; }
  .meta-table td:first-child { color: #888; padding-right: 12px; }
  h1 { font-size: 16pt; color: #00B4D8; margin: 30px 0 15px; }
  table.positions { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  table.positions th { background: #00B4D8; color: #fff; padding: 6px 8px; font-size: 9pt; text-align: left; }
  table.positions td { padding: 5px 8px; border-bottom: 1px solid #ddd; font-size: 9pt; }
  table.positions tr:nth-child(even) td { background: #fafafa; }
  .totals { width: 50%; margin-left: auto; margin-bottom: 20px; }
  .totals td { padding: 3px 8px; font-size: 10pt; }
  .totals .label { text-align: right; color: #555; }
  .totals .amount { text-align: right; font-weight: bold; }
  .totals .grand { border-top: 2px solid #00B4D8; font-size: 12pt; color: #00B4D8; }
  .footer { font-size: 8pt; color: #888; border-top: 1px solid #ccc; padding-top: 8px; margin-top: 30px; text-align: center; }
  .zugferd-badge { display: inline-block; background: #e8f5e9; color: #2e7d32; padding: 2px 8px; border-radius: 3px; font-size: 8pt; margin-top: 4px; }
</style>
</head>
<body>
<!-- DIN 5008 Falz- und Lochmarken -->
<div class="foldmark foldmark-top"></div>
<div class="punchmark"></div>
<div class="foldmark foldmark-bottom"></div>

<div class="header">
  <div class="header-left">
    <div class="sender-line">{$aFirma} · {$aStrasse} · {$aPlzOrt}</div>
    <div class="recipient">
      <strong>{$eName}</strong><br>
      {$eAdresse}
    </div>
  </div>
  <div class="header-right">
    {$logoHtml}
    <table class="meta-table">
      <tr><td>Rechnungsnr.:</td><td><strong>{$nr}</strong></td></tr>
      <tr><td>Datum:</td><td>{$datum}</td></tr>
      <tr><td>Fällig bis:</td><td>{$fDatum}</td></tr>
      <tr><td>USt-IdNr.:</td><td>{$ustid}</td></tr>
    </table>
  </div>
</div>

<h1>Rechnung {$nr}</h1>

<table class="positions">
  <thead>
    <tr><th style="width:8%;">Pos.</th><th>Bezeichnung</th><th style="width:15%;">Menge</th><th style="width:15%;">Einzelpreis</th><th style="width:15%;">Gesamt</th></tr>
  </thead>
  <tbody>
    {$posRows}
  </tbody>
</table>

<table class="totals">
  <tr><td class="label">Netto:</td><td class="amount">{FMT_NETTO} €</td></tr>
  {$mwstRow}
  <tr class="grand"><td class="label">Gesamtbetrag:</td><td class="amount">{FMT_BRUTTO} €</td></tr>
</table>
{$hinweisHtml}

<p style="font-size:9pt;">Bitte überweisen Sie den Gesamtbetrag bis zum <strong>{$fDatum}</strong> auf folgendes Konto:</p>
<table style="font-size:9pt; margin-bottom:20px;">
  <tr><td style="color:#888; padding-right:12px;">Bank:</td><td>{$bank}</td></tr>
  <tr><td style="color:#888;">IBAN:</td><td>{$iban}</td></tr>
  <tr><td style="color:#888;">BIC:</td><td>{$bic}</td></tr>
</table>

<div class="footer">
  {$aFirma} · {$aStrasse} · {$aPlzOrt} · Tel: {$aTel} · E-Mail: {$aEmail}<br>
  Steuernr.: {$steuernr} · USt-IdNr.: {$ustid}<br>
  <span class="zugferd-badge">✓ ZUGFeRD 2.1 (EN 16931) – Elektronische Rechnung</span>
</div>
</body>
</html>
HTML;

        // Platzhalter ersetzen (number_format außerhalb von Heredoc)
        $html = str_replace(
            ['{FMT_NETTO}', '{FMT_MWST}', '{FMT_BRUTTO}'],
            [
                number_format($netto, 2, ',', '.'),
                number_format($mwst, 2, ',', '.'),
                number_format($brutto, 2, ',', '.'),
            ],
            $html
        );

        return $html;
    }
}
