<?php
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Einheitlicher Beleg-PDF-Renderer (Rechnung + Angebot).
 *
 * Erzeugt Dompdf-sicheres HTML (keine Flexbox/Grid, nur Tabellen-Layout).
 * Alle Beleg-PDFs laufen hierüber, damit sie überall gleich aussehen:
 *  – ExportActions::renderBelegPdf()  → „📄 PDF“, Vorschau und „In Dateien speichern“
 *  – ZugferdService::generate()       → sichtbarer Teil der ZUGFeRD-Rechnung (auch E-Mail)
 *  – EmailActions::sendAngebot()      → Angebot per E-Mail
 *
 * @since v2.10.54
 */
class BelegPdfService
{
    /** Positionstypen, die keine Menge/Preis tragen. */
    private const OHNE_BETRAG = ['gruppe', 'freitext', 'trennlinie', 'zwischensumme'];

    /**
     * Lädt alles, was für ein Beleg-PDF nötig ist, aus einem Rechnungs-Datensatz
     * (DB-Zeile oder ungespeicherte Formulardaten).
     *
     * @param  array<string,mixed> $rechnung
     * @return array{typ:string, beleg:array<string,mixed>, positionen:array<int,array<string,mixed>>, kunde:?array<string,mixed>}
     */
    public static function belegDaten(\PDO $db, array $rechnung): array
    {
        $positionen = $rechnung['positionen'] ?? [];
        if (is_string($positionen)) {
            $positionen = json_decode($positionen, true);
        }
        $positionen = is_array($positionen)
            ? array_values(array_filter($positionen, 'is_array'))
            : [];

        $absender = $rechnung['absender'] ?? [];
        if (is_string($absender)) {
            $absender = json_decode($absender, true);
        }
        $absender = is_array($absender) ? $absender : [];

        $kunde   = null;
        $kundeId = (int)($rechnung['kundeId'] ?? 0);
        if ($kundeId > 0) {
            $stmt = $db->prepare('SELECT * FROM kunden WHERE id = ?');
            $stmt->execute([$kundeId]);
            $kunde = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $baustelleName = '';
        $baustelleId   = (int)($rechnung['baustelleId'] ?? 0);
        if ($baustelleId > 0) {
            $stmt = $db->prepare('SELECT name FROM baustellen WHERE id = ?');
            $stmt->execute([$baustelleId]);
            $baustelleName = (string)($stmt->fetchColumn() ?: '');
        }

        return [
            'typ'        => ($rechnung['typ'] ?? 'rechnung') === 'angebot' ? 'angebot' : 'rechnung',
            'beleg'      => [
                'nummer'        => (string)($rechnung['nummer'] ?? ''),
                'datum'         => (string)($rechnung['datum'] ?? '') ?: date('Y-m-d'),
                'faelligAm'     => (string)($rechnung['faelligAm'] ?? ''),
                'beschreibung'  => (string)($rechnung['beschreibung'] ?? ''),
                'notizen'       => (string)($rechnung['notizen'] ?? ''),
                'baustelleName' => $baustelleName,
                'absender'      => $absender,
            ],
            'positionen' => $positionen,
            'kunde'      => $kunde,
        ];
    }

    /** Positionstyp; Altdaten kennen teils nur das Feld `typ`. Unbekannte Typen gelten als normal. */
    public static function posTyp(array $p): string
    {
        $bekannt = ['normal', 'eventual', 'gruppe', 'freitext', 'trennlinie', 'zwischensumme'];
        foreach ([$p['posTyp'] ?? null, $p['typ'] ?? null] as $typ) {
            if (is_string($typ) && in_array($typ, $bekannt, true)) {
                return $typ;
            }
        }
        return 'normal';
    }

    /** Netto-Gesamtpreis einer Position inkl. Rabatt, kaufmännisch auf Cent gerundet. */
    public static function positionsNetto(array $p): float
    {
        if (in_array(self::posTyp($p), self::OHNE_BETRAG, true)) {
            return 0.0;
        }
        $m = (float)($p['menge'] ?? $p['anzahl'] ?? 0);
        $e = (float)($p['einzelpreis'] ?? $p['ep'] ?? 0);
        $r = min(100.0, max(0.0, (float)($p['rabatt'] ?? 0)));
        return round($m * $e * (1.0 - $r / 100.0), 2);
    }

    /**
     * Positionen in Belegreihenfolge mit Positionsnummer („3.“ bzw. „3.2“).
     * Unterpositionen folgen direkt auf ihre Gruppe.
     *
     * @param  array<int,array<string,mixed>> $positionen
     * @return array<int,array{nr:string, pos:array<string,mixed>, ebene:int}>
     */
    public static function nummerierePositionen(array $positionen): array
    {
        $groupIds = [];
        foreach ($positionen as $pos) {
            if (self::posTyp($pos) === 'gruppe' && !empty($pos['id'])) {
                $groupIds[(string)$pos['id']] = true;
            }
        }

        $liste = [];
        $topNr = 0;
        foreach ($positionen as $pos) {
            if (!empty($pos['parentId']) && isset($groupIds[(string)$pos['parentId']])) {
                continue;
            }
            $typ = self::posTyp($pos);
            if ($typ === 'trennlinie' || $typ === 'zwischensumme') {
                $liste[] = ['nr' => '', 'pos' => $pos, 'ebene' => 0];
                continue;
            }
            $topNr++;
            $liste[] = ['nr' => $topNr . '.', 'pos' => $pos, 'ebene' => 0];
            if ($typ !== 'gruppe') {
                continue;
            }
            $subNr = 0;
            foreach ($positionen as $sub) {
                if ((string)($sub['parentId'] ?? '') === (string)($pos['id'] ?? '') && !empty($pos['id'])) {
                    $subNr++;
                    $liste[] = ['nr' => $topNr . '.' . $subNr, 'pos' => $sub, 'ebene' => 1];
                }
            }
        }
        return $liste;
    }

    /** Netto-Summe aller berechneten Positionen (ohne Eventualpositionen). */
    public static function nettoSumme(array $positionen): float
    {
        $summe = 0.0;
        foreach (self::nummerierePositionen($positionen) as $eintrag) {
            if (self::posTyp($eintrag['pos']) === 'normal') {
                $summe += self::positionsNetto($eintrag['pos']);
            }
        }
        return round($summe, 2);
    }

    private static function datumDe(string $datum): string
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', substr($datum, 0, 10));
        return $dt ? $dt->format('d.m.Y') : $datum;
    }

    /**
     * Einheitliches HTML für Rechnung oder Angebot.
     *
     * @param  array       $beleg       Belegdaten: nummer, datum, faelligAm,
     *                                  beschreibung, notizen, baustelleName,
     *                                  absender[] (Firmen-Override, leer = Settings)
     * @param  array       $positionen  Positions-Array aus dem Frontend
     * @param  array|null  $kunde       Kundenstammsatz oder null
     * @param  array       $settings    App-Settings (firma_*, firma_kleinunternehmer, …)
     * @param  string      $typ         'rechnung' | 'angebot'
     * @param  bool        $zugferd     Hinweis „ZUGFeRD“ nur, wenn das PDF wirklich eine E-Rechnung ist
     * @return string                   Vollständiges HTML-Dokument (UTF-8)
     */
    public function buildHtml(
        array  $beleg,
        array  $positionen,
        ?array $kunde,
        array  $settings,
        string $typ,
        bool   $zugferd = false
    ): string {
        $h   = fn($v): string => htmlspecialchars(trim((string)$v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        $effMwstSatz      = 0.0;
        if (!$kleinunternehmer && !$reverseCharge) {
            // Gleiche Regel wie ZugferdService und Formular: leer/0 → 19 %.
            $effMwstSatz = (float)($settings['firma_mwst_satz'] ?? 19.0);
            if ($effMwstSatz <= 0) $effMwstSatz = 19.0;
        }
        $rcText = $settings['firma_reverse_charge_text']
            ?? 'Steuerschuldnerschaft des Leistungsempfängers gemäß § 13b UStG (Reverse Charge).';

        // ── Empfänger ─────────────────────────────────────────────────────────
        $kundeAdrHtml = '';
        if ($kunde) {
            $parts = [
                (string)($kunde['firma'] ?? ''),
                trim(($kunde['anrede'] ?? '') . ' ' . ($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? '')),
                (string)($kunde['strasse'] ?? ''),
                trim(($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? '')),
            ];
            $kundeAdrHtml = implode(
                '<br>',
                array_map($h, array_filter($parts, fn($p) => trim((string)$p) !== ''))
            );
        }
        $kundeNr = $kunde ? $h($kunde['kundennummer'] ?? '') : '';

        // ── Belegdaten ────────────────────────────────────────────────────────
        $nummer   = $h($beleg['nummer'] ?? '');
        $datumFmt = self::datumDe((string)($beleg['datum'] ?? '') ?: date('Y-m-d'));
        $fDatum   = !empty($beleg['faelligAm']) ? self::datumDe((string)$beleg['faelligAm']) : '';
        $beschr   = nl2br($h($beleg['beschreibung'] ?? ''));
        $notizen  = (string)($beleg['notizen'] ?? '');
        $bsName   = $h($beleg['baustelleName'] ?? '');
        $titel    = $typ === 'rechnung' ? 'Rechnung' : 'Angebot';

        // ── Logo als Base64-Data-URI ──────────────────────────────────────────
        $logoHtml = '';
        $logoUri  = FirmenLogo::dataUri($settings);
        if ($logoUri !== '') {
            $logoHtml = '<img src="' . $logoUri . '" style="max-height:22mm;max-width:60mm;" /><br>';
        }

        // ── Positionen ────────────────────────────────────────────────────────
        $rabNote = function (array $p): string {
            $r = (float)($p['rabatt'] ?? 0);
            return $r > 0
                ? '<br><span class="rabatt">abzgl. '
                  . number_format($r, 0, ',', '') . '&#37; Rabatt</span>'
                : '';
        };
        $betragsZeile = function (string $nr, array $p, string $zusatz, string $klasse) use ($h, $fmt, $rabNote): string {
            return '<tr' . ($klasse !== '' ? ' class="' . $klasse . '"' : '') . '>'
                . '<td class="c-nr">' . $h($nr) . '</td>'
                . '<td class="c-bez">' . $h($p['bezeichnung'] ?? $p['beschreibung'] ?? '') . $zusatz . $rabNote($p) . '</td>'
                . '<td class="c-num">' . $fmt($p['menge'] ?? $p['anzahl'] ?? 0) . '</td>'
                . '<td class="c-ein">' . $h($p['einheit'] ?? '') . '</td>'
                . '<td class="c-num">' . $fmt($p['einzelpreis'] ?? $p['ep'] ?? 0) . '</td>'
                . '<td class="c-num">' . $fmt(self::positionsNetto(['posTyp' => 'normal'] + $p)) . '</td>'
                . '</tr>';
        };

        $posRows    = '';
        $eventNetto = 0.0;
        $sumNetto   = self::nettoSumme($positionen);
        $nummeriert = self::nummerierePositionen($positionen);

        foreach ($nummeriert as $i => $eintrag) {
            $pos    = $eintrag['pos'];
            $nr     = $eintrag['nr'];
            $posTyp = self::posTyp($pos);
            $sub    = $eintrag['ebene'] === 1 ? 'sub' : '';

            if ($posTyp === 'trennlinie') {
                $posRows .= '<tr><td colspan="6" class="trenn"></td></tr>';
            } elseif ($posTyp === 'zwischensumme') {
                $posRows .= '<tr class="zws"><td colspan="5" class="c-num">Zwischensumme</td>'
                    . '<td class="c-num">' . $fmt($pos['gesamt'] ?? 0) . '</td></tr>';
            } elseif ($posTyp === 'freitext') {
                $posRows .= '<tr class="' . trim('frei ' . $sub) . '"><td class="c-nr">' . $h($nr) . '</td>'
                    . '<td colspan="5" class="c-bez">' . nl2br($h($pos['bezeichnung'] ?? '')) . '</td></tr>';
            } elseif ($posTyp === 'gruppe') {
                $grpSum = 0.0;
                for ($j = $i + 1; $j < count($nummeriert) && $nummeriert[$j]['ebene'] === 1; $j++) {
                    if (self::posTyp($nummeriert[$j]['pos']) === 'normal') {
                        $grpSum += self::positionsNetto($nummeriert[$j]['pos']);
                    }
                }
                $posRows .= '<tr class="grp"><td class="c-nr">' . $h($nr) . '</td>'
                    . '<td colspan="4" class="c-bez">' . $h($pos['bezeichnung'] ?? '') . '</td>'
                    . '<td class="c-num">' . $fmt($grpSum) . '</td></tr>';
            } elseif ($posTyp === 'eventual') {
                $eventNetto += self::positionsNetto(['posTyp' => 'normal'] + $pos);
                $zusatz = $sub ? ' <em>(EP)</em>' : ' <em>(Eventualposition)</em>';
                $posRows .= $betragsZeile($nr, $pos, $zusatz, trim('evt ' . $sub));
            } else {
                $posRows .= $betragsZeile($nr, $pos, '', $sub);
            }
        }

        // ── Summen ────────────────────────────────────────────────────────────
        $mwstBetrag  = round($sumNetto * $effMwstSatz / 100, 2);
        $bruttoSumme = round($sumNetto + $mwstBetrag, 2);

        if ($kleinunternehmer) {
            $mwstZeile     = '';
            $steuerHinweis = '<p class="hinweis">Gem&auml;&szlig; &sect; 19 UStG wird keine Umsatzsteuer berechnet.</p>';
        } elseif ($reverseCharge) {
            $mwstZeile     = '<tr><td class="lbl">Umsatzsteuer (Reverse Charge)</td><td class="amt">0,00</td></tr>';
            $steuerHinweis = '<p class="hinweis">' . $h($rcText) . '</p>';
        } else {
            $mwstZeile = '<tr><td class="lbl">Umsatzsteuer ' . number_format($effMwstSatz, 0, ',', '')
                . '&nbsp;%</td><td class="amt">' . $fmt($mwstBetrag) . '</td></tr>';
            $steuerHinweis = '';
        }

        $eventualRow = $eventNetto > 0
            ? '<tr class="evt"><td class="lbl">davon Eventualpositionen (netto, nicht in Summe enthalten)</td>'
              . '<td class="amt">' . $fmt($eventNetto) . '</td></tr>'
            : '';

        // ── Infoblock (rechts neben Anschriftfeld, DIN 5008) ─────────────────
        $infoRows = '<tr><td class="lbl">' . $titel . '-Nr.:</td><td class="val">' . ($nummer !== '' ? $nummer : '&mdash;') . '</td></tr>'
            . '<tr><td class="lbl">Datum:</td><td class="val">' . $datumFmt . '</td></tr>';
        if ($kundeNr !== '') {
            $infoRows .= '<tr><td class="lbl">Kunden-Nr.:</td><td class="val">' . $kundeNr . '</td></tr>';
        }
        if ($bsName !== '') {
            $infoRows .= '<tr><td class="lbl">Projekt:</td><td class="val">' . $bsName . '</td></tr>';
        }
        if ($fDatum !== '') {
            $infoRows .= '<tr><td class="lbl">F&auml;llig:</td><td class="val">' . $fDatum . '</td></tr>';
        }
        $betreff = $titel . ($nummer !== '' ? ' Nr. ' . $nummer : '') . ' vom ' . $datumFmt;

        // ── Fußzeile (position:fixed im unteren Seitenrand → auf jeder Seite) ──
        $footerCol1 = $firmaName . '<br>' . $firmaStr . '<br>' . $firmaPlzOrt;
        $footerCol2 = ($firmaStNr ? 'Steuernummer: ' . $firmaStNr : '')
            . ($firmaUstId ? ($firmaStNr ? '<br>' : '') . 'USt-IdNr: ' . $firmaUstId : '');
        $footerCol3 = ($firmaBank ? $firmaBank . '<br>' : '')
            . ($firmaIban ? 'IBAN: ' . $firmaIban . '<br>' : '')
            . ($firmaBic  ? 'BIC: '  . $firmaBic  : '');

        $notizenHtml = trim($notizen) !== ''
            ? '<div class="notizen">' . nl2br($h($notizen)) . '</div>'
            : '';

        $zugferdBadge = $zugferd
            ? '<br><span class="badge">ZUGFeRD EN&#160;16931</span>'
            : '';

        // Seitenränder: oben 20, rechts 15, unten 30, links 20 mm. Feste Elemente beziehen
        // sich in Dompdf auf den Inhaltsbereich – Falz-/Lochmarken und Fußzeile werden
        // deshalb mit negativen Abständen in den Seitenrand geschoben.
        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><style>
  @page { margin: 20mm 15mm 30mm 20mm; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5pt;
         color: #1a1a1a; line-height: 1.35; margin: 0; padding: 0; }
  .foldmark  { position: fixed; left: -20mm; width: 5mm; height: 0; border-top: 0.5pt solid #bbb; }
  .fold-top  { top: 85mm; }
  .fold-bot  { top: 190mm; }
  .punchmark { position: fixed; left: -20mm; top: 128.5mm; width: 7mm; height: 0; border-top: 0.5pt solid #999; }
  .page-footer { position: fixed; left: 0; right: 0; bottom: -22mm; height: 15mm;
                 border-top: 0.5pt solid #ccc; padding-top: 1.5mm; }
  .page-footer table { width: 100%; border-collapse: collapse; }
  .page-footer td { width: 33%; padding: 0 2mm 0 0; font-size: 7pt; line-height: 1.3;
                    color: #666; vertical-align: top; }
  .letterhead { text-align: right; margin-bottom: 6mm; }
  .letterhead .firm-name { font-size: 12pt; font-weight: bold; }
  .letterhead .lh-sub { font-size: 9pt; color: #444; }
  .badge { background: #e8f5e9; color: #2e7d32; padding: 0.5mm 1.5mm; font-size: 7pt; }
  .addr-info { width: 100%; border-collapse: collapse; margin-bottom: 9mm; }
  .addr-info td { border: none; padding: 0; vertical-align: top; }
  .addr-cell { width: 55%; padding-right: 8mm; }
  .info-cell { width: 45%; }
  .sender-line { font-size: 7pt; color: #666; border-bottom: 0.5pt solid #999;
                 padding-bottom: 1mm; margin-bottom: 4mm; }
  .recipient { font-size: 10.5pt; line-height: 1.5; }
  .info-block { width: 100%; border-collapse: collapse; }
  .info-block td { font-size: 9pt; padding: 0.6mm 0; vertical-align: top; }
  .info-block td.lbl { color: #555; width: 28mm; }
  .info-block td.val { font-weight: bold; text-align: right; }
  h1.doc-title { font-size: 15pt; margin: 0 0 1.5mm 0; }
  .subject   { font-weight: bold; font-size: 10.5pt; margin-bottom: 4mm; }
  .doc-beschr { font-size: 9.5pt; color: #333; margin-bottom: 5mm; }
  /* Kein table-layout:fixed – Dompdf verteilt die Spalten dann gleichmäßig. */
  table.pos { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }
  table.pos th { background: #f5f5f5; border-top: 1.2pt solid #333; border-bottom: 1.2pt solid #333;
                 padding: 1.5mm 1.5mm; font-size: 8.5pt; font-weight: bold; text-align: left; }
  table.pos td { padding: 1.5mm 1.5mm; border-bottom: 0.5pt solid #ddd; font-size: 9pt; vertical-align: top; }
  table.pos tr { page-break-inside: avoid; }
  table.pos th.c-num, table.pos td.c-num { text-align: right; }
  table.pos th.c-ein, table.pos td.c-ein { text-align: center; }
  table.pos td.c-nr  { white-space: nowrap; }
  table.pos tr.sub td.c-nr, table.pos tr.sub td.c-bez { padding-left: 4mm; }
  table.pos tr.grp td { background: #dbeeff; color: #1565C0; font-weight: bold; }
  table.pos tr.evt td { color: #888; font-style: italic; }
  table.pos tr.frei td.c-bez { color: #555; font-style: italic; }
  table.pos tr.zws td { font-weight: bold; border-bottom: none; }
  table.pos td.trenn { border-top: 1.2pt solid #333; border-bottom: none; padding: 0; height: 1mm; }
  .rabatt { font-size: 7.5pt; color: #888; }
  .totals { width: 62%; margin-left: 38%; border-collapse: collapse; margin-bottom: 3mm; page-break-inside: avoid; }
  .totals td { padding: 1mm 1.5mm; font-size: 9pt; }
  .totals td.lbl { text-align: right; }
  .totals td.amt { text-align: right; width: 30mm; }
  .totals tr.netto td { border-top: 1pt solid #333; }
  .totals tr.brutto td { border-top: 1.5pt solid #333; font-weight: bold; font-size: 10pt; }
  .totals tr.evt td { color: #888; font-style: italic; font-size: 8pt; }
  .hinweis { font-size: 8.5pt; color: #555; margin-top: 3mm; font-style: italic; }
  .notizen { margin-top: 5mm; font-size: 9pt; color: #333; page-break-inside: avoid; }
</style></head>
<body>
<div class="foldmark fold-top"></div>
<div class="punchmark"></div>
<div class="foldmark fold-bot"></div>

<div class="page-footer">
  <table><tr>
    <td>{$footerCol1}</td>
    <td>{$footerCol2}</td>
    <td>{$footerCol3}</td>
  </tr></table>
</div>

<div class="letterhead">
  {$logoHtml}
  <span class="firm-name">{$firmaName}</span><br>
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
<div class="subject">{$betreff}</div>
HTML;

        if ($beschr !== '') {
            $html .= '<div class="doc-beschr">' . $beschr . '</div>';
        }

        $html .= '<table class="pos"><thead><tr>'
            . '<th style="width:12mm;">Pos.</th>'
            . '<th>Bezeichnung</th>'
            . '<th class="c-num" style="width:19mm;">Menge</th>'
            . '<th class="c-ein" style="width:15mm;">Einheit</th>'
            . '<th class="c-num" style="width:24mm;">Einzel&nbsp;&euro;</th>'
            . '<th class="c-num" style="width:26mm;">Gesamt&nbsp;&euro;</th>'
            . '</tr></thead><tbody>' . $posRows . '</tbody></table>';

        $html .= '<table class="totals">'
            . '<tr class="netto"><td class="lbl">Zwischensumme (netto)</td><td class="amt">' . $fmt($sumNetto) . '</td></tr>'
            . $mwstZeile
            . '<tr class="brutto"><td class="lbl">Gesamtbetrag</td><td class="amt">' . $fmt($bruttoSumme) . '&nbsp;&euro;</td></tr>'
            . $eventualRow
            . '</table>';

        $html .= $steuerHinweis;
        $html .= $notizenHtml;
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Erzeugt ein Plain-PDF aus HTML via Dompdf.
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
        $pdf = (string)$dompdf->output();
        // Dompdf-Objektgraph (Zyklen) vor der Antwort freigeben – sonst räumt PHP ihn erst
        // nach dem Senden ab und hält so lange u. a. die SQLite-Datei offen.
        unset($dompdf);
        gc_collect_cycles();
        return $pdf;
    }

    /** Komplettes PDF für einen Rechnungs-/Angebots-Datensatz. */
    public function pdfFuerBeleg(\PDO $db, array $rechnung, array $settings): string
    {
        $d = self::belegDaten($db, $rechnung);
        return $this->generatePdf(
            $this->buildHtml($d['beleg'], $d['positionen'], $d['kunde'], $settings, $d['typ'])
        );
    }
}
