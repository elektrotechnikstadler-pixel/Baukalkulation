<?php
// ============================================================
// CRON: Monatliche Stundenauswertung per E-Mail (PDF)
// ============================================================
// Erzeugt eine PDF-Übersicht der Mitarbeiterstunden des VORMONATS
// (alle Mitarbeiter gesammelt) und sendet sie an die konfigurierte
// Admin-E-Mail. Einstellung erfolgt wie beim Backup unter
// Einstellungen -> Erinnerungen.
//
// Cron (im Docker-Container automatisch alle 15 Min):
//   */15 * * * * php /var/www/html/cron_stundenauswertung_email.php >> data/cron.log 2>&1
//
// Flag --force: Fälligkeits-/Lock-Prüfung überspringen (manueller Test).

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\MailService;
use Dompdf\Dompdf;
use Dompdf\Options;

define('DATA_DIR', __DIR__ . '/data/');

$force = in_array('--force', $argv ?? [], true);

function saLog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
    @file_put_contents(DATA_DIR . 'erinnerung.log', "[$ts] $msg\n", FILE_APPEND);
}

saLog('=== Stundenauswertung-E-Mail gestartet' . ($force ? ' [FORCE]' : '') . ' ===');

$dbPath = DATA_DIR . 'database.sqlite';
if (!file_exists($dbPath)) { saLog('FEHLER: Datenbank nicht gefunden.'); exit(1); }
try {
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) { saLog('FEHLER: DB-Verbindung: ' . $e->getMessage()); exit(1); }

// -- Einstellungen --
$settingsRow = $db->query("SELECT data FROM settings WHERE id = 1")->fetch();
$settings    = $settingsRow ? (json_decode($settingsRow['data'], true) ?? []) : [];
$smtpCfg = [
    'smtp_host'       => $settings['smtp_host']       ?? '',
    'smtp_port'       => $settings['smtp_port']        ?? '587',
    'smtp_user'       => $settings['smtp_user']        ?? '',
    'smtp_pass'       => $settings['smtp_pass']        ?? '',
    'smtp_from_email' => $settings['smtp_from_email']  ?? $settings['firma_email'] ?? '',
    'smtp_from_name'  => $settings['smtp_from_name']   ?? $settings['firma_name']  ?? '',
    'smtp_security'   => $settings['smtp_security']    ?? 'tls',
];
$firmaName = $settings['firma_name'] ?? 'Baukalkulation';

$erRow  = $db->query("SELECT data FROM erinnerung_settings WHERE id = 1")->fetch();
$erData = $erRow ? (json_decode($erRow['data'], true) ?? []) : [];

$aktiv      = (bool)($erData['stundenauswertung_email_aktiv'] ?? false);
$empfaenger = trim($erData['stundenauswertung_email_empfaenger'] ?? '');
$tag        = max(1, min(28, (int)($erData['stundenauswertung_email_tag'] ?? 1)));
$uhrzeit    = $erData['stundenauswertung_email_uhrzeit'] ?? '07:00';
if ($empfaenger === '') $empfaenger = $smtpCfg['smtp_from_email'];

if (!$aktiv && !$force) { saLog('Stundenauswertung-E-Mail ist deaktiviert.'); exit(0); }
if (!$empfaenger || !filter_var($empfaenger, FILTER_VALIDATE_EMAIL)) {
    saLog("FEHLER: Keine gültige Empfänger-E-Mail ($empfaenger)."); exit(1);
}

// -- Fälligkeit (monatlich am Tag X ab Uhrzeit), Lock je Monat --
$lockFile = DATA_DIR . 'stundenauswertung_email_sent.txt';
$zyklusKey = date('Y-m');
if (!$force) {
    if (file_exists($lockFile) && trim(@file_get_contents($lockFile)) === $zyklusKey) {
        saLog("Für diesen Monat ($zyklusKey) bereits gesendet."); exit(0);
    }
    if ((int)date('j') < $tag) { saLog('Tag ' . date('j') . " < $tag -> noch nicht fällig."); exit(0); }
    if (date('H:i') < $uhrzeit) { saLog('Uhrzeit ' . date('H:i') . " < $uhrzeit -> noch nicht fällig."); exit(0); }
}

// -- Zeitraum: Vormonat --
$monatStart = date('Y-m-01', strtotime('first day of last month'));
$monatEnde  = date('Y-m-t', strtotime('first day of last month'));
$jahr       = (int)date('Y', strtotime($monatStart));
$monatLabel = strftime_de($monatStart);

// -- Feiertage (Bayern) für das Jahr --
$feiertage = bayerischeFeiertage($jahr);
$feiertagSet = array_flip($feiertage);

// -- Nutzer laden --
$users = $db->query("SELECT username, kuerzel, role, sollstundenTag, sollTageWoche, arbeitstage, showInZeitverwaltung FROM users ORDER BY username COLLATE NOCASE")->fetchAll();
$users = array_filter($users, fn($u) => ($u['role'] ?? '') !== 'admin' && (int)($u['showInZeitverwaltung'] ?? 1) !== 0);

// -- Zeiterfassung des Monats --
$stmt = $db->prepare("SELECT username, datum, typ, stunden FROM zeiterfassung WHERE datum >= ? AND datum <= ?");
$stmt->execute([$monatStart, $monatEnde]);
$zeit = $stmt->fetchAll();
$byUser = [];
foreach ($zeit as $z) { $byUser[$z['username']][] = $z; }

$rows = [];
$sumSoll = 0; $sumIst = 0;
foreach ($users as $u) {
    $uname   = $u['username'];
    $sollTag = (float)($u['sollstundenTag'] ?? 8) ?: 8;
    $at      = arbeitstageSet($u['arbeitstage'] ?? '', (int)($u['sollTageWoche'] ?? 5));

    // Soll-Arbeitstage im Monat (Arbeitstag & kein Feiertag)
    $sollTage = 0;
    for ($t = strtotime($monatStart); $t <= strtotime($monatEnde); $t = strtotime('+1 day', $t)) {
        $dow = (int)date('N', $t); // 1=Mo .. 7=So
        $key = date('Y-m-d', $t);
        if (in_array($dow, $at, true) && !isset($feiertagSet[$key])) $sollTage++;
    }
    $soll = $sollTage * $sollTag;

    $list = $byUser[$uname] ?? [];
    $arbeitStd  = 0.0;
    $urlaubTage = [];
    $krankTage  = [];
    foreach ($list as $e) {
        $typ = $e['typ'] ?? '';
        if ($typ === 'arbeit')      $arbeitStd += (float)($e['stunden'] ?? 0);
        elseif ($typ === 'urlaub')  $urlaubTage[$e['datum']] = true;
        elseif ($typ === 'krank')   $krankTage[$e['datum']]  = true;
    }
    $uCount = count($urlaubTage);
    $kCount = count($krankTage);
    // Ist gutgeschrieben: Arbeit + (Urlaub+Krank) × Soll/Tag
    $ist  = $arbeitStd + ($uCount + $kCount) * $sollTag;
    $diff = $ist - $soll;

    $sumSoll += $soll; $sumIst += $ist;
    $rows[] = [
        'name'   => $uname . ($u['kuerzel'] ? ' (' . $u['kuerzel'] . ')' : ''),
        'soll'   => $soll, 'ist' => $ist, 'diff' => $diff,
        'arbeit' => $arbeitStd, 'urlaub' => $uCount, 'krank' => $kCount,
    ];
}

if (empty($rows)) { saLog('Keine Mitarbeiter/Buchungen für den Monat.'); }

// -- HTML für PDF --
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn($n) => number_format((float)$n, 2, ',', '.');
$rowsHtml = '';
foreach ($rows as $r) {
    $diffColor = $r['diff'] >= 0 ? '#2E7D32' : '#C62828';
    $rowsHtml .= '<tr>'
        . '<td>' . $h($r['name']) . '</td>'
        . '<td class="num">' . $fmt($r['soll']) . '</td>'
        . '<td class="num">' . $fmt($r['ist']) . '</td>'
        . '<td class="num" style="color:' . $diffColor . ';font-weight:700">' . ($r['diff'] >= 0 ? '+' : '') . $fmt($r['diff']) . '</td>'
        . '<td class="num">' . $fmt($r['arbeit']) . '</td>'
        . '<td class="num">' . (int)$r['urlaub'] . '</td>'
        . '<td class="num">' . (int)$r['krank'] . '</td>'
        . '</tr>';
}
$sumDiff = $sumIst - $sumSoll;
$html = '<html><head><meta charset="utf-8"><style>'
    . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#1a1a2e}'
    . 'h1{font-size:16px;margin:0 0 2px}h2{font-size:12px;color:#555;margin:0 0 14px;font-weight:400}'
    . 'table{width:100%;border-collapse:collapse}'
    . 'th,td{border:1px solid #ccc;padding:5px 7px;text-align:left}'
    . 'th{background:#00B4D8;color:#fff;font-size:10px}'
    . 'td.num,th.num{text-align:right}'
    . 'tr:nth-child(even) td{background:#f5f7fa}'
    . 'tfoot td{font-weight:700;background:#eef2f5}'
    . '.foot{margin-top:14px;font-size:9px;color:#888}'
    . '</style></head><body>'
    . '<h1>Stundenauswertung – ' . $h($monatLabel) . '</h1>'
    . '<h2>' . $h($firmaName) . ' · Mitarbeiterstunden (Soll/Ist/Differenz), erstellt am ' . date('d.m.Y') . '</h2>'
    . '<table><thead><tr>'
    . '<th>Mitarbeiter</th><th class="num">Soll (h)</th><th class="num">Ist (h)</th>'
    . '<th class="num">Diff (h)</th><th class="num">Arbeit (h)</th><th class="num">Urlaub (T)</th><th class="num">Krank (T)</th>'
    . '</tr></thead><tbody>' . $rowsHtml . '</tbody>'
    . '<tfoot><tr><td>Gesamt</td><td class="num">' . $fmt($sumSoll) . '</td><td class="num">' . $fmt($sumIst) . '</td>'
    . '<td class="num" style="color:' . ($sumDiff >= 0 ? '#2E7D32' : '#C62828') . '">' . ($sumDiff >= 0 ? '+' : '') . $fmt($sumDiff) . '</td>'
    . '<td class="num"></td><td class="num"></td><td class="num"></td></tr></tfoot>'
    . '</table>'
    . '<p class="foot">Ist gutgeschrieben = Arbeitsstunden + (Urlaub + Krank) × Soll/Tag. Soll = Arbeitstage (ohne Feiertage) × Soll/Tag. '
    . 'Automatisch erzeugte Monatsübersicht.</p>'
    . '</body></html>';

// -- PDF via Dompdf --
try {
    $opts = new Options();
    $opts->set('isRemoteEnabled', false);
    $opts->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($opts);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $pdfBytes = (string)$dompdf->output();
} catch (Throwable $e) {
    saLog('FEHLER: PDF-Erzeugung: ' . $e->getMessage()); exit(1);
}

$pdfName = 'Stundenauswertung_' . date('Y-m', strtotime($monatStart)) . '.pdf';
$subject = 'Stundenauswertung ' . $monatLabel . ' – ' . $firmaName;
$bodyHtml = '<p>Anbei die monatliche Stundenauswertung (' . $h($monatLabel) . ') für alle Mitarbeiter als PDF.</p>'
    . '<p>Gesamt Soll: ' . $fmt($sumSoll) . ' h · Ist: ' . $fmt($sumIst) . ' h · Differenz: ' . ($sumDiff >= 0 ? '+' : '') . $fmt($sumDiff) . ' h</p>';

try {
    MailService::send($smtpCfg, $empfaenger, '', $subject, $bodyHtml, $pdfBytes, $pdfName, 'application/pdf');
    saLog("Stundenauswertung-E-Mail an $empfaenger gesendet ($monatLabel, " . count($rows) . ' Mitarbeiter).');
    if (!$force) @file_put_contents($lockFile, $zyklusKey);
} catch (Throwable $e) {
    saLog('FEHLER: Versand: ' . $e->getMessage()); exit(1);
}

saLog('=== Stundenauswertung-E-Mail fertig ===');

// ── Hilfsfunktionen ──────────────────────────────────────────
function arbeitstageSet(string $raw, int $sollTageWoche): array {
    $days = array_values(array_filter(array_map('intval', explode(',', $raw)), fn($n) => $n >= 1 && $n <= 6));
    if ($days) return $days;
    $n = max(1, min(6, $sollTageWoche ?: 5));
    return range(1, $n);
}

function bayerischeFeiertage(int $year): array {
    // Ostersonntag per Gauß-Algorithmus (ohne ext-calendar).
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $hh = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $hh - $k) % 7;
    $m = intdiv($a + 11 * $hh + 22 * $l, 451);
    $month = intdiv($hh + $l - 7 * $m + 114, 31);
    $day = (($hh + $l - 7 * $m + 114) % 31) + 1;
    $easter = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $rel = fn($days) => date('Y-m-d', strtotime("$days days", $easter));
    return [
        "$year-01-01", "$year-01-06",
        $rel(-2), $rel(1),
        "$year-05-01",
        $rel(39), $rel(50), $rel(60),
        "$year-08-15",
        "$year-10-03", "$year-11-01",
        "$year-12-25", "$year-12-26",
    ];
}

function strftime_de(string $ymd): string {
    $mn = ['', 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    $t = strtotime($ymd);
    return $mn[(int)date('n', $t)] . ' ' . date('Y', $t);
}
