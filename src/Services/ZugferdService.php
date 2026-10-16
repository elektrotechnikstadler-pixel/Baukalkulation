<?php
namespace App\Services;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;

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
     * Der sichtbare Teil kommt aus BelegPdfService – gleiches Layout wie „📄 PDF“.
     *
     * @return string  PDF-Daten (binary)
     */
    public function generate(array $rechnung, array $absender, array $positionen, ?array $kunde, array $settings, string $baustelleName = ''): string
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

        // Nur berechnete Positionen (inkl. Unterpositionen von Gruppen) gehen in die
        // E-Rechnung – identisch zur Summe im sichtbaren PDF.
        foreach (BelegPdfService::nummerierePositionen($positionen) as $eintrag) {
            $pos = $eintrag['pos'];
            if (BelegPdfService::posTyp($pos) !== 'normal') continue;

            $bez     = (string)($pos['bezeichnung'] ?? $pos['beschreibung'] ?? '') ?: 'Position';
            $menge   = (float)($pos['menge'] ?? $pos['anzahl'] ?? 1);
            $ep      = (float)($pos['einzelpreis'] ?? $pos['ep'] ?? 0);
            $rabatt  = min(100.0, max(0.0, (float)($pos['rabatt'] ?? 0)));
            $netPrice = $ep * (1.0 - $rabatt / 100.0);
            $einheit = (string)($pos['einheit'] ?? 'Stk');

            $gp = BelegPdfService::positionsNetto($pos);
            $nettoSumme += $gp;
            $uom = self::UNIT_MAP[$einheit] ?? 'C62';

            $doc->addNewPosition(rtrim($eintrag['nr'], '.'));
            $doc->setDocumentPositionProductDetails($bez);
            $doc->setDocumentPositionGrossPrice($ep);
            if ($rabatt > 0) {
                $doc->addDocumentPositionGrossPriceAllowanceCharge($ep - $netPrice, false);
            }
            $doc->setDocumentPositionNetPrice($netPrice);
            $doc->setDocumentPositionQuantity($menge, $uom);
            $doc->setDocumentPositionLineSummation($gp);
            $doc->addDocumentPositionTax($taxCategory, 'VAT', $taxRate);
        }

        $nettoSumme = round($nettoSumme, 2);

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

        // ── 2) Sichtbares Rechnungs-PDF (gleiches Layout wie „📄 PDF“) ──
        $belegSvc = new BelegPdfService();
        $beleg = [
            'nummer'        => (string)($rechnung['nummer'] ?? ''),
            'datum'         => (string)($rechnung['datum'] ?? ''),
            'faelligAm'     => (string)($rechnung['faelligAm'] ?? ''),
            'beschreibung'  => (string)($rechnung['beschreibung'] ?? ''),
            'notizen'       => (string)($rechnung['notizen'] ?? ''),
            'baustelleName' => $baustelleName,
            'absender'      => $absender,
        ];
        $pdfContent = $belegSvc->generatePdf(
            $belegSvc->buildHtml($beleg, $positionen, $kunde, $settings, 'rechnung', true)
        );

        // ── 3) ZUGFeRD-XML in PDF einbetten ─────────────────
        $tmpPdf = tempnam(sys_get_temp_dir(), 'zf_pdf_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'zf_out_');
        try {
            file_put_contents($tmpPdf, $pdfContent);
            $pdfBuilder = new ZugferdDocumentPdfBuilder($doc, $tmpPdf);
            $pdfBuilder->generateDocument();
            $pdfBuilder->saveDocument($tmpOut);
            $result = (string)file_get_contents($tmpOut);
        } finally {
            foreach ([$tmpPdf, $tmpOut] as $tmp) {
                if (is_string($tmp) && is_file($tmp)) unlink($tmp);
            }
        }

        return $result;
    }
}
