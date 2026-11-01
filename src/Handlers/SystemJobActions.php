<?php
namespace App\Handlers;

use App\Auth;
use App\Services\AuditService;
use App\Services\Jobs\JobProtokoll;
use App\Services\Jobs\MeldungsZustellung;
use App\Services\Jobs\NasAbgleich;
use App\Services\Jobs\SmtpKonfig;
use App\Services\Jobs\SystemEinstellungen;
use App\Services\Jobs\Ueberwachung;

/**
 * Serversicherung/Planer-Verwaltung (T2), Überwachung/Systemmeldungen (T3) und
 * NAS-Abgleich/Schlüssel-Export (T4) von AP-20261012-datensicherung-nas. Alle Aktionen nur
 * Rolle admin; zustandsändernde Aktionen nur POST (E-015).
 */
final class SystemJobActions
{
    /** Positivliste der per UI anforderbaren Jobs. */
    private const ANFORDERBARE_JOBS = ['sicherung', 'probe_wiederherstellung', 'nas'];
    private const AUFTRAG_GUELTIGKEIT_SEKUNDEN = 1800; // 30 min
    /** Muss zu Planer::HAENGENDER_LAUF_STUNDEN passen – ab hier gilt ein "laufender" Auftrag als hängend. */
    private const AUFTRAG_LAUFEND_MAX_STUNDEN = 3;
    private const TESTMAIL_DROSSEL_SEKUNDEN = 60;
    private const TESTMAIL_BUDGET_SEKUNDEN = 20; // Gesamtbudget über alle Empfänger (Reviewbefund 6)
    /** Jobs der Status-/Hinweis-Anzeige, die inaktiv sein können (Reviewbefund 7). */
    private const UEBERWACHTE_JOBS = ['sicherung', 'probe_wiederherstellung', 'speicherplatz', 'backupmail', 'nas'];
    /** Offene, ungesendete Einträge älter als 7 Tage zählen nicht mehr als Dauerbanner-Grund (Reviewbefund 2). */
    private const HINWEIS_ALTER_GRENZE_TAGE = 7;
    private const NAS_TEST_DROSSEL_SEKUNDEN = 10;
    private const SECRET_DROSSEL_MAX = 5;
    private const SECRET_DROSSEL_SEKUNDEN = 600; // 10 min

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

        $statusJobs = [];
        foreach (self::UEBERWACHTE_JOBS as $job) {
            $zeile = $job === 'speicherplatz'
                ? $this->speicherplatzOhnePfad($protokoll->status($job))
                : $protokoll->status($job);
            // 'aktiv' je Job (z. B. Backup-E-Mail abgeschaltet) für die Ampel in der UI
            // (Reviewbefund 10: grau = inaktiv statt "noch nie beobachtet").
            $zeile['aktiv'] = Ueberwachung::istJobAktiv($this->db, $job);
            $statusJobs[$job] = $zeile;
        }
        $statusJobs['planer'] = ['letzterLaufAm' => $herzschlag, 'aktiv' => $planerAktiv];

        jsonOut([
            'ok' => true,
            'einstellungen' => [
                'sicherung'    => $einstellungen['sicherung'],
                'ueberwachung' => $einstellungen['ueberwachung'],
                'nas'          => SystemEinstellungen::nasOeffentlich(is_array($einstellungen['nas'] ?? null) ? $einstellungen['nas'] : []),
            ],
            'status'            => $statusJobs,
            'empfaengerWirksam' => MeldungsZustellung::empfaengerWirksam($this->db),
            'meldungen'         => $this->letzteMeldungen(20),
            'laeufe'            => $protokoll->letzteLaeufe(30),
        ]);
    }

    /** system_einstellungen_speichern (POST, {sicherung?:{...}, ueberwachung?:{...}, nas?:{...}}) */
    public function einstellungenSpeichern(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Speichern nur per POST.'], 405);
        }
        $hatSicherung = array_key_exists('sicherung', $this->body);
        $hatUeberwachung = array_key_exists('ueberwachung', $this->body);
        $hatNas = array_key_exists('nas', $this->body);
        if (!$hatSicherung && !$hatUeberwachung && !$hatNas) {
            jsonOut(['error' => 'Ungültige Eingabe.'], 400);
        }

        try {
            // Alle Bereiche vollständig prüfen, bevor irgendetwas geschrieben wird – sonst
            // könnte bei Kombination mehrerer Schlüssel ein Teil gespeichert werden, obwohl ein
            // anderer ungültig ist (T3-Vertrag Punkt 4).
            if ($hatUeberwachung) {
                $ueberwachungEingabe = $this->body['ueberwachung'];
                if (!is_array($ueberwachungEingabe)) {
                    throw new \InvalidArgumentException('Ungültige Eingabe.');
                }
                SystemEinstellungen::validierenUeberwachung($ueberwachungEingabe);
            }
            if ($hatNas) {
                $nasEingabe = $this->body['nas'];
                if (!is_array($nasEingabe)) {
                    throw new \InvalidArgumentException('Ungültige Eingabe.');
                }
                $bisherNas = SystemEinstellungen::laden($this->db)['nas'] ?? [];
                SystemEinstellungen::vorabPruefenNas($nasEingabe, is_array($bisherNas) ? $bisherNas : []);
            }

            $alle = null;
            if ($hatSicherung) {
                $sicherungEingabe = $this->body['sicherung'];
                if (!is_array($sicherungEingabe)) {
                    throw new \InvalidArgumentException('Ungültige Eingabe.');
                }
                $alle = SystemEinstellungen::speichernSicherung($this->db, $sicherungEingabe);
            }
            if ($hatUeberwachung) {
                $alle = SystemEinstellungen::speichernUeberwachung($this->db, $this->body['ueberwachung']);
            }
            if ($hatNas) {
                $alle = SystemEinstellungen::speichernNas($this->db, $this->body['nas']);
            }
        } catch (\InvalidArgumentException $e) {
            jsonOut(['error' => $e->getMessage()], 400);
        }

        $antwort = ['ok' => true];
        if ($hatSicherung) $antwort['sicherung'] = $alle['sicherung'];
        if ($hatUeberwachung) $antwort['ueberwachung'] = $alle['ueberwachung'];
        if ($hatNas) $antwort['nas'] = SystemEinstellungen::nasOeffentlich($alle['nas']);
        jsonOut($antwort);
    }

    /** system_hinweise (GET) – roter Admin-Hinweis, nur feste Texte (E-014). */
    public function hinweise(): void
    {
        Auth::requireRole('admin');
        $protokoll = new JobProtokoll($this->db);
        $hinweise = [];

        $planerStatus = $protokoll->status('planer');
        $herzschlag = (string)($planerStatus['letzterLaufAm'] ?? '');
        if ($herzschlag === '' || (time() - strtotime($herzschlag)) >= 900) {
            $hinweise[] = [
                'art'  => 'planer',
                'job'  => 'planer',
                'text' => 'Zeitplaner läuft nicht – automatische Sicherung/Überwachung inaktiv.',
                'seit' => $herzschlag,
            ];
        }

        foreach (self::UEBERWACHTE_JOBS as $job) {
            if (!Ueberwachung::istJobAktiv($this->db, $job)) {
                continue; // inaktive Jobs (z. B. abgeschaltete Backup-E-Mail) auslassen (Reviewbefund 7)
            }
            $status = $protokoll->status($job);
            $stoerungSeit = (string)($status['stoerungSeit'] ?? '');
            if ($stoerungSeit !== '') {
                $hinweise[] = [
                    'art'  => 'stoerung',
                    'job'  => $job,
                    'text' => 'Störung bei ' . MeldungsZustellung::JOB_NAMEN[$job] . ' – bitte Überwachung prüfen.',
                    'seit' => $stoerungSeit,
                ];
            }
        }

        // T4 R12: Ein nach einer Wiederherstellung nicht entschlüsselbares NAS-Passwort wurde
        // bereits geleert – hier nur sichtbar machen, damit ein Admin es neu einträgt.
        $nasEinstellungen = SystemEinstellungen::laden($this->db)['nas'] ?? [];
        if ((bool)(is_array($nasEinstellungen) ? ($nasEinstellungen['passwortNeuEingeben'] ?? false) : false)) {
            $hinweise[] = [
                'art'  => 'nas',
                'job'  => 'nas',
                'text' => 'NAS-Passwort konnte nach der letzten Wiederherstellung nicht entschlüsselt werden – bitte neu eingeben.',
                'seit' => '',
            ];
        }

        // Nur jüngere (< 7 Tage), tatsächlich nicht zustellbare Einträge zählen als Grund für
        // den Dauerbanner – ältere wurden bereits aufgegeben bzw. werden aufgeräumt (Reviewbefund 2).
        $grenzeHinweis = date('Y-m-d H:i:s', time() - self::HINWEIS_ALTER_GRENZE_TAGE * 86400);
        $stmtNichtZustellbar = $this->db->prepare(
            "SELECT COUNT(*) FROM meldungen_outbox WHERE gesendetAm = '' AND versuche >= 3 AND erstelltAm >= ?"
        );
        $stmtNichtZustellbar->execute([$grenzeHinweis]);
        $nichtZustellbar = (int)$stmtNichtZustellbar->fetchColumn();
        if ($nichtZustellbar > 0) {
            $hinweise[] = [
                'art'  => 'zustellung',
                'job'  => '',
                'text' => 'Eine oder mehrere Systemmeldungen konnten noch nicht zugestellt werden.',
                'seit' => '',
            ];
        }

        jsonOut(['ok' => true, 'hinweise' => $hinweise]);
    }

    /** ueberwachung_testmail (POST) – synchroner Testversand an die wirksamen Empfänger. */
    public function testmail(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Testmail nur per POST.'], 405);
        }

        $stampPfad = rtrim(DATA_DIR, '/\\') . '/run/ueberwachung_testmail.stamp';
        $runDir = dirname($stampPfad);
        if (!is_dir($runDir)) {
            // Kein @ (E-063, der globale Error-Handler wirft trotzdem) – explizite Prüfung oben
            // plus vorübergehend deaktivierter Error-Handler fürs Anlegen selbst (Reviewbefund 13).
            set_error_handler(static fn (): bool => true);
            try {
                mkdir($runDir, 0750, true);
            } finally {
                restore_error_handler();
            }
        }
        $letzter = is_file($stampPfad) ? (int)trim((string)file_get_contents($stampPfad)) : 0;
        if (time() - $letzter < self::TESTMAIL_DROSSEL_SEKUNDEN) {
            jsonOut(['error' => 'Bitte höchstens alle 60 Sekunden eine Testmail anfordern.'], 429);
        }
        file_put_contents($stampPfad, (string)time(), LOCK_EX);

        $empfaenger = MeldungsZustellung::empfaengerWirksam($this->db);
        $cfg = SmtpKonfig::laden($this->db);
        $firmaName = $this->firmaName();
        $betreff = 'Testmail Systemmeldungen – ' . $firmaName;
        $html = '<p>Dies ist eine Testmail der Überwachung/Systemmeldungen der Baukalkulation.</p>'
              . '<p>Wenn Sie diese E-Mail erhalten, ist der Versand korrekt konfiguriert.</p>';

        // Gesamtbudget über alle Empfänger (Reviewbefund 6): nach dem ersten Fehlschlag werden
        // die übrigen Empfänger nicht mehr versucht (derselbe SMTP-Server wäre ohnehin
        // betroffen) und als "nicht versucht" ausgewiesen.
        $ergebnisse = [];
        $begin = microtime(true);
        $fehlgeschlagen = false;
        foreach ($empfaenger as $an) {
            if ($fehlgeschlagen) {
                $ergebnisse[] = ['empfaenger' => $an, 'ok' => false, 'fehler' => 'Nicht versucht – vorheriger Versand fehlgeschlagen.'];
                continue;
            }
            $restSek = self::TESTMAIL_BUDGET_SEKUNDEN - (int)floor(microtime(true) - $begin);
            if ($restSek < 1) {
                // T4-Vertrag Punkt 9 (R3): eigener Text, da hier kein vorheriger Fehlschlag
                // vorliegt, sondern das Gesamtzeitbudget regulär aufgebraucht ist.
                $ergebnisse[] = ['empfaenger' => $an, 'ok' => false, 'fehler' => 'Nicht versucht – Zeitbudget aufgebraucht.'];
                continue;
            }
            $cfg['smtp_timeout'] = max(1, min(15, $restSek));
            try {
                \App\Services\MailService::send($cfg, $an, '', $betreff, $html);
                $ergebnisse[] = ['empfaenger' => $an, 'ok' => true, 'fehler' => null];
            } catch (\Throwable $e) {
                error_log('[SystemJobActions] Testmail an ' . $an . ' fehlgeschlagen: ' . $e->getMessage());
                $ergebnisse[] = ['empfaenger' => $an, 'ok' => false, 'fehler' => 'Mailversand fehlgeschlagen. Bitte SMTP-Einstellungen prüfen.'];
                $fehlgeschlagen = true;
            }
        }
        AuditService::log('ueberwachung_testmail', 'Empfänger=' . count($empfaenger));
        jsonOut(['ok' => true, 'ergebnisse' => $ergebnisse]);
    }

    /**
     * nas_verbindung_testen (POST, optionale Formularwerte – überschreiben die gespeicherten
     * NAS-Einstellungen für diesen Testaufruf; ein leeres/fehlendes Passwort nutzt das
     * gespeicherte, verschlüsselte). Synchron, höchstens 20 s (NasAbgleich::verbindungTesten()),
     * Drosselung 1 je 10 s, Audit-Log ohne Zugangsdaten.
     */
    public function nasVerbindungTesten(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Verbindungstest nur per POST.'], 405);
        }

        $stampPfad = rtrim(DATA_DIR, '/\\') . '/run/nas_verbindungstest.stamp';
        $runDir = dirname($stampPfad);
        if (!is_dir($runDir)) {
            set_error_handler(static fn (): bool => true);
            try {
                mkdir($runDir, 0750, true);
            } finally {
                restore_error_handler();
            }
        }
        // Lesen und Schreiben unter einem flock (Reviewbefund 9) – sonst könnten zwei
        // gleichzeitige Anfragen beide den (noch alten) Zeitstempel lesen und die Drosselung
        // umgehen.
        if (!$this->nasTestDrosselPruefenUndSetzen($stampPfad)) {
            jsonOut(['error' => 'Bitte höchstens alle 10 Sekunden einen Verbindungstest anfordern.'], 429);
        }

        $gespeichert = SystemEinstellungen::laden($this->db)['nas'] ?? [];
        $gespeichert = is_array($gespeichert) ? $gespeichert : [];
        $eingabe = $this->body;
        if (isset($eingabe['passwort']) && !is_string($eingabe['passwort'])) {
            // Nicht-String vor dem `(string)`-Cast ablehnen (wie SystemEinstellungen::
            // nasEingabeAlsString()) – sonst würde ein Array/Objekt eine PHP-Warnung auslösen.
            jsonOut(['error' => 'Ungültiges NAS-Passwort.'], 400);
        }
        $nas = array_merge($gespeichert, $eingabe);
        // Ein leeres/fehlendes Formular-Passwort nutzt das gespeicherte (verschlüsselte) Passwort.
        if (!isset($eingabe['passwort']) || (string)$eingabe['passwort'] === '') {
            $nas['passwort'] = (string)($gespeichert['passwort'] ?? '');
        }

        $ergebnis = (new NasAbgleich($this->db))->verbindungTesten($nas);
        AuditService::log('nas_verbindung_testen', 'Ergebnis=' . ($ergebnis['ok'] ? 'ok' : ((string)($ergebnis['fehlerCode'] ?? 'fehler'))));
        jsonOut($ergebnis);
    }

    /**
     * secret_key_anzeigen (POST {passwort}) – F9: eigener Schlüssel-Export nach erneuter
     * Passwortabfrage. Ist BK_SECRET_KEY gesetzt, kommt der Schlüssel aus der Umgebung und
     * wird nicht ausgegeben.
     */
    public function secretKeyAnzeigen(): void
    {
        Auth::requireRole('admin');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            jsonOut(['error' => 'Nur per POST.'], 405);
        }
        header('Cache-Control: no-store');

        $username = (string)($_SESSION['username'] ?? '');
        if (!$this->secretDrosselPruefen($username)) {
            jsonOut(['error' => 'Zu viele Versuche – bitte in einigen Minuten erneut versuchen.'], 429);
        }

        $passwort = $this->body['passwort'] ?? '';
        if (!is_string($passwort)) {
            // Nicht-String vor dem `(string)`-Cast ablehnen – sonst würde ein Array/Objekt eine
            // PHP-Warnung auslösen (analog SystemEinstellungen::nasEingabeAlsString()).
            jsonOut(['error' => 'Ungültiges Passwort.'], 400);
        }
        $stmt = $this->db->prepare('SELECT password FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($passwort === '' || $hash === '' || !password_verify($passwort, $hash)) {
            AuditService::log('secret_key_anzeigen_fehlgeschlagen', 'Benutzer=' . $username);
            jsonOut(['error' => 'Passwort falsch.'], 403);
        }

        $envSchluessel = getenv('BK_SECRET_KEY');
        if (is_string($envSchluessel) && $envSchluessel !== '') {
            AuditService::log('secret_key_anzeigen', 'Quelle=Umgebung');
            jsonOut(['ok' => true, 'ausUmgebung' => true]);
        }

        $datei = rtrim(DATA_DIR, '/\\') . '/secret.key';
        if (!is_file($datei)) {
            jsonOut(['error' => 'Schlüsseldatei nicht gefunden.'], 500);
        }
        $schluessel = trim((string)file_get_contents($datei));
        AuditService::log('secret_key_anzeigen', 'Quelle=Datei');
        jsonOut(['ok' => true, 'schluessel' => $schluessel]);
    }

    /**
     * Drosselung des `nas_verbindung_testen`-Zeitstempels unter einem `flock` (Reviewbefund 9):
     * Lesen, Prüfen und Schreiben des Stempels in einer einzigen Sperre, damit zwei gleichzeitige
     * Anfragen die Drosselung nicht durch eine Lese-/Schreib-Race umgehen können.
     */
    private function nasTestDrosselPruefenUndSetzen(string $stampPfad): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $handle = fopen($stampPfad, 'c+');
        } finally {
            restore_error_handler();
        }
        if ($handle === false) {
            return false;
        }
        try {
            flock($handle, LOCK_EX);
            $letzter = (int)trim((string)stream_get_contents($handle));
            $jetzt = time();
            if ($jetzt - $letzter < self::NAS_TEST_DROSSEL_SEKUNDEN) {
                return false;
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string)$jetzt);
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Drosselung je Benutzer (Hash statt Klartext im Dateinamen), max. SECRET_DROSSEL_MAX je SECRET_DROSSEL_SEKUNDEN. */
    private function secretDrosselPruefen(string $username): bool
    {
        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir)) {
            set_error_handler(static fn (): bool => true);
            try {
                mkdir($runDir, 0750, true);
            } finally {
                restore_error_handler();
            }
        }
        $datei = $runDir . '/secret_key_drossel_' . substr(hash('sha256', $username), 0, 16) . '.json';

        // Lesen und Schreiben unter einem flock (Reviewbefund 9) – sonst könnten zwei
        // gleichzeitige Versuche beide den (noch alten) Zähler lesen und die Drosselung umgehen.
        set_error_handler(static fn (): bool => true);
        try {
            $handle = fopen($datei, 'c+');
        } finally {
            restore_error_handler();
        }
        if ($handle === false) {
            return false;
        }
        try {
            flock($handle, LOCK_EX);
            $inhalt = stream_get_contents($handle);
            $jetzt = time();
            $gespeichert = $inhalt !== false && $inhalt !== '' ? json_decode($inhalt, true) : [];
            $versuche = array_values(array_filter(
                is_array($gespeichert) ? $gespeichert : [],
                static fn ($t) => is_int($t) && ($jetzt - $t) < self::SECRET_DROSSEL_SEKUNDEN,
            ));
            if (count($versuche) >= self::SECRET_DROSSEL_MAX) {
                return false;
            }
            $versuche[] = $jetzt;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($versuche));
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** job_anfordern (POST, {job:'sicherung'|'probe_wiederherstellung'|'nas'}) */
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

    /** Entfernt keine Pfade enthaltenden Felder – speicherplatz-Status hat ohnehin keine, nur zur Klarheit/Absicherung. */
    private function speicherplatzOhnePfad(array $status): array
    {
        unset($status['pfad'], $status['dir']);
        return $status;
    }

    /** @return list<array<string,mixed>> die letzten $limit Outbox-Einträge, ohne Rohdaten/Pfade. */
    private function letzteMeldungen(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $grenzeNichtZustellbar = date('Y-m-d H:i:s', time() - 7 * 86400);
        $stmt = $this->db->query(
            "SELECT typ, job, erstelltAm, gesendetAm, versuche FROM meldungen_outbox
              ORDER BY erstelltAm DESC, id DESC LIMIT {$limit}"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['zustellbar'] = $row['gesendetAm'] !== '' || $row['erstelltAm'] >= $grenzeNichtZustellbar;
        }
        unset($row);
        return $rows;
    }

    private function firmaName(): string
    {
        $row = $this->db->query('SELECT data FROM settings WHERE id = 1')->fetch();
        $settings = $row ? (json_decode((string)$row['data'], true) ?? []) : [];
        $name = trim((string)($settings['firma_name'] ?? ''));
        return $name !== '' ? $name : 'Baukalkulation';
    }
}

