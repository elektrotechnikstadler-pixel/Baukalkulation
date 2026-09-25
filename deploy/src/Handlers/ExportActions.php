<?php
namespace App\Handlers;

use App\Auth;
use App\DataService;

class ExportActions
{
    public function __construct(private \PDO $db, private array $body) {}

    // ══════════════════════════════════════════════════════════
    // IN-FORM CSV Export
    // ══════════════════════════════════════════════════════════
    public function exportInformCsv(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canExportInform')) {
            jsonOut(['error' => 'Keine Berechtigung für IN-FORM Export.'], 403);
        }
        // IN-FORM-CSV enthält EP/GP – ohne Preisrecht nicht herausgeben.
        if (!Auth::canDo($this->db, 'canSeePrices')) {
            jsonOut(['error' => 'Keine Berechtigung für Preisexport.'], 403);
        }
        $baustelleId = (int)($this->body['baustelleId'] ?? 0);
        // Sichtbarkeits-Cascade prüfen (Ober-/Unterprojekt)
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }
        $row = $this->db->prepare("SELECT id, name, data FROM baustellen WHERE id = ?");
        $row->execute([$baustelleId]);
        $bRow = $row->fetch();
        if (!$bRow) jsonOut(['error' => 'Baustelle nicht gefunden.'], 404);

        $bData = json_decode($bRow['data'], true) ?: [];
        $bName = $bRow['name'];

        // Globale Pauschalen laden (v2.9.20: Cents -> Euro)
        $globalPauschalen = $this->db->query("SELECT id, name, preis FROM pauschalen ORDER BY id")->fetchAll();
        $gpMap = [];
        foreach ($globalPauschalen as $gp) {
            $gp['preis'] = money_from_cents($gp['preis']);
            $gpMap[(int)$gp['id']] = $gp;
        }

        $rows = [];
        $posNr = 1;

        // Material
        foreach ($bData['material'] ?? [] as $m) {
            $ek = (float)($m['ek'] ?? 0);
            $anzahl = (float)($m['anzahl'] ?? 0);
            $rabattF = 1 - ((float)($m['rabatt'] ?? 0)) / 100;
            $ga = (float)($bData['materialAufschlagGlobal'] ?? 0);
            if (!empty($m['vkDirekt'])) {
                $ep = $ek * $rabattF;
            } else {
                $aufschlag = (float)($m['aufschlag'] ?? 0);
                $ep = $ek * (1 + $aufschlag / 100) * (1 + $ga / 100) * $rabattF;
            }
            $gp = $ep * $anzahl;
            $rows[] = [
                'PosNr'       => $posNr++,
                'Artikelnr'   => $m['artikelNr'] ?? '',
                'Bezeichnung' => $m['bezeichnung'] ?? '',
                'Menge'       => $anzahl,
                'Einheit'     => $m['einheit'] ?? 'Stk',
                'EP_Netto'    => round($ep, 2),
                'GP_Netto'    => round($gp, 2),
                'Kategorie'   => 'Material',
            ];
        }

        // Arbeitszeiten
        foreach ($bData['arbeitszeit'] ?? [] as $a) {
            $stunden = (float)($a['stunden'] ?? 0);
            $stundenpreis = (float)($a['stundenpreis'] ?? 0);
            $gp = $stunden * $stundenpreis;
            $rows[] = [
                'PosNr'       => $posNr++,
                'Artikelnr'   => '',
                'Bezeichnung' => $a['beschreibung'] ?? 'Arbeitszeit',
                'Menge'       => $stunden,
                'Einheit'     => 'Std',
                'EP_Netto'    => round($stundenpreis, 2),
                'GP_Netto'    => round($gp, 2),
                'Kategorie'   => 'Arbeitszeit',
            ];
        }

        // Pauschalen
        foreach ($bData['pauschalen'] ?? [] as $pe) {
            $peId = $pe['id'] ?? (is_int($pe) ? $pe : 0);
            $peAnzahl = (float)($pe['anzahl'] ?? 1);
            $pDef = $gpMap[$peId] ?? null;
            $name  = $pDef['name'] ?? 'Pauschale';
            $preis = (float)($pDef['preis'] ?? 0);
            $gesamt = $preis * $peAnzahl;
            $rows[] = [
                'PosNr'       => $posNr++,
                'Artikelnr'   => '',
                'Bezeichnung' => $name,
                'Menge'       => $peAnzahl,
                'Einheit'     => 'psch',
                'EP_Netto'    => round($preis, 2),
                'GP_Netto'    => round($gesamt, 2),
                'Kategorie'   => 'Pauschale',
            ];
        }

        // CSV generieren
        $csv = "PosNr;Artikelnr;Bezeichnung;Menge;Einheit;EP_Netto;GP_Netto;Kategorie\r\n";
        foreach ($rows as $r) {
            $csv .= implode(';', [
                $r['PosNr'],
                '"' . str_replace('"', '""', $r['Artikelnr']) . '"',
                '"' . str_replace('"', '""', $r['Bezeichnung']) . '"',
                str_replace('.', ',', (string)$r['Menge']),
                $r['Einheit'],
                str_replace('.', ',', (string)$r['EP_Netto']),
                str_replace('.', ',', (string)$r['GP_Netto']),
                $r['Kategorie'],
            ]) . "\r\n";
        }

        $fileName = 'INFORM_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $bName) . '_' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        echo "\xEF\xBB\xBF";
        echo $csv;
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // Subunternehmer-Bericht
    // ══════════════════════════════════════════════════════════
    public function subunternehmerReport(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeeSubunternehmerReport')) {
            jsonOut(['error' => 'Keine Berechtigung für den Subunternehmer-Bericht.'], 403);
        }
        $baustelleId = (int)($this->body['baustelleId'] ?? $_GET['baustelleId'] ?? 0);
        if (!Auth::canSeeBaustelle($this->db, $baustelleId)) {
            jsonOut(['error' => 'Keine Berechtigung für diese Baustelle.'], 403);
        }

        // Subunternehmer-Kürzel sammeln
        $users = $this->db->query("SELECT username, kuerzel, isSubunternehmer, dienstleisterId FROM users")->fetchAll();
        $subKuerzel = [];
        $subUserMap = [];
        foreach ($users as $u) {
            if ((int)$u['isSubunternehmer'] && $u['kuerzel'] !== '') {
                $subKuerzel[] = $u['kuerzel'];
                $subUserMap[$u['kuerzel']] = $u['dienstleisterId'] ? (int)$u['dienstleisterId'] : null;
            }
        }

        // Dienstleister laden
        $dlRows = $this->db->query("SELECT id, firma, kontakt, telefon, email FROM dienstleister")->fetchAll();
        $dlMap = [];
        foreach ($dlRows as $dl) $dlMap[(int)$dl['id']] = $dl;

        // Baustelle laden
        $bRow = $this->db->prepare("SELECT id, name, data FROM baustellen WHERE id = ?");
        $bRow->execute([$baustelleId]);
        $bR = $bRow->fetch();
        if (!$bR) jsonOut(['error' => 'Baustelle nicht gefunden.'], 404);

        $bData = json_decode($bR['data'], true) ?: [];

        // Material und Arbeitszeit nach Dienstleister gruppieren
        $report = [];
        foreach ($subKuerzel as $kz) {
            $dlId   = $subUserMap[$kz] ?? null;
            $dlName = ($dlId && isset($dlMap[$dlId])) ? $dlMap[$dlId]['firma'] : 'Ohne Zuordnung';
            if (!isset($report[$dlName])) {
                $report[$dlName] = ['firma' => $dlName, 'dienstleisterId' => $dlId, 'material' => [], 'arbeitszeit' => []];
            }
            foreach ($bData['material'] ?? [] as $m) {
                if (($m['erstelltVon'] ?? '') === $kz) {
                    $report[$dlName]['material'][] = $m;
                }
            }
            foreach ($bData['arbeitszeit'] ?? [] as $az) {
                if (($az['erstelltVon'] ?? '') === $kz) {
                    $report[$dlName]['arbeitszeit'][] = $az;
                }
            }
        }

        $report = array_values(array_filter($report, fn($r) => count($r['material']) > 0 || count($r['arbeitszeit']) > 0));
        // Preisfelder strippen, wenn User keine Preise sehen darf
        $report = \App\Services\PriceFilter::apply($this->db, $report);
        jsonOut(['ok' => true, 'report' => $report, 'baustelleName' => $bR['name']]);
    }

    // ══════════════════════════════════════════════════════════
    // ZUGFeRD E-Rechnung generieren
    // ══════════════════════════════════════════════════════════
    public function generateZugferd(): void
    {
        Auth::requireAuth();
        // ZUGFeRD-Rechnungen enthalten naturgemäß sämtliche Preise.
        if (!Auth::canDo($this->db, 'canSeePrices')) {
            jsonOut(['error' => 'Keine Berechtigung für Preisexport.'], 403);
        }
        if (!Auth::canDo($this->db, 'canManageRechnungen') && !Auth::canDo($this->db, 'canSeeRechnungen')) {
            jsonOut(['error' => 'Keine Berechtigung für Rechnungen.'], 403);
        }
        $rechnungId = trim($this->body['rechnungId'] ?? '');
        if (!$rechnungId) jsonOut(['error' => 'Keine Rechnungs-ID.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE id = ?");
        $stmt->execute([$rechnungId]);
        $rechnung = $stmt->fetch();
        if (!$rechnung) jsonOut(['error' => 'Rechnung nicht gefunden.'], 404);

        // Nur Rechnungen, keine Angebote
        if (($rechnung['typ'] ?? '') !== 'rechnung') {
            jsonOut(['error' => 'ZUGFeRD ist nur für Rechnungen verfügbar, nicht für Angebote.'], 400);
        }

        $absender    = json_decode($rechnung['absender'] ?? '{}', true) ?: [];
        $positionen  = json_decode($rechnung['positionen'] ?? '[]', true) ?: [];

        // Kunde laden
        $kunde = null;
        if ($rechnung['kundeId']) {
            $kStmt = $this->db->prepare("SELECT * FROM kunden WHERE id = ?");
            $kStmt->execute([$rechnung['kundeId']]);
            $kunde = $kStmt->fetch();
        }

        // Settings für Firmendaten
        $settings = Auth::loadSettings($this->db);

        // Deprecation-Warnungen von Dompdf (PHP 8.4) unterdrücken
        $prevErrorLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $service = new \App\Services\ZugferdService();
            $pdfData = $service->generate($rechnung, $absender, $positionen, $kunde, $settings);
        } finally {
            error_reporting($prevErrorLevel);
        }

        $fileName = ($rechnung['nummer'] ?? 'RE') . '_ZUGFeRD.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($pdfData));
        echo $pdfData;
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // Plain-PDF (Rechnung ODER Angebot) – serverseitig via Dompdf.
    // Liefert ein sauberes PDF ohne Browser-Kopf-/Fußzeile.
    // ══════════════════════════════════════════════════════════
    public function renderBelegPdf(): void
    {
        Auth::requireAuth();
        if (!Auth::canDo($this->db, 'canSeePrices')) {
            jsonOut(['error' => 'Keine Berechtigung für Preisexport.'], 403);
        }
        $rechnungId = trim($this->body['rechnungId'] ?? ($_GET['rechnungId'] ?? ''));
        if (!$rechnungId) jsonOut(['error' => 'Keine Beleg-ID.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM rechnungen WHERE id = ?");
        $stmt->execute([$rechnungId]);
        $rechnung = $stmt->fetch();
        if (!$rechnung) jsonOut(['error' => 'Beleg nicht gefunden.'], 404);

        $typ = ($rechnung['typ'] ?? 'rechnung') === 'angebot' ? 'angebot' : 'rechnung';
        // Berechtigung je Belegtyp prüfen.
        if ($typ === 'rechnung' && !Auth::canDo($this->db, 'canManageRechnungen') && !Auth::canDo($this->db, 'canSeeRechnungen')) {
            jsonOut(['error' => 'Keine Berechtigung für Rechnungen.'], 403);
        }
        if ($typ === 'angebot' && !Auth::canDo($this->db, 'canManageAngebote') && !Auth::canDo($this->db, 'canSeeAngebote')) {
            jsonOut(['error' => 'Keine Berechtigung für Angebote.'], 403);
        }

        $absender   = json_decode($rechnung['absender'] ?? '{}', true) ?: [];
        $positionen = json_decode($rechnung['positionen'] ?? '[]', true) ?: [];

        $kunde = null;
        if (!empty($rechnung['kundeId'])) {
            $kStmt = $this->db->prepare("SELECT * FROM kunden WHERE id = ?");
            $kStmt->execute([$rechnung['kundeId']]);
            $kunde = $kStmt->fetch() ?: null;
        }

        $baustelleName = '';
        if (!empty($rechnung['baustelleId'])) {
            $bStmt = $this->db->prepare("SELECT name FROM baustellen WHERE id = ?");
            $bStmt->execute([$rechnung['baustelleId']]);
            $baustelleName = (string)($bStmt->fetchColumn() ?: '');
        }

        $settings = Auth::loadSettings($this->db);

        $beleg = [
            'nummer'        => $rechnung['nummer']       ?? '',
            'datum'         => $rechnung['datum']        ?? date('Y-m-d'),
            'faelligAm'     => $rechnung['faelligAm']    ?? '',
            'beschreibung'  => $rechnung['beschreibung'] ?? '',
            'notizen'       => $rechnung['notizen']      ?? '',
            'baustelleName' => $baustelleName,
            'absender'      => $absender,
        ];

        $prevErrorLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $svc  = new \App\Services\BelegPdfService();
            $html = $svc->buildHtml($beleg, $positionen, $kunde, $settings, $typ);
            $pdf  = $svc->generatePdf($html);
        } finally {
            error_reporting($prevErrorLevel);
        }

        $safeNr   = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($rechnung['nummer'] ?? ''));
        $fileName = ($typ === 'rechnung' ? 'Rechnung' : 'Angebot') . ($safeNr ? '_' . $safeNr : '') . '.pdf';
        $download = !empty($this->body['download']) || !empty($_GET['download']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
