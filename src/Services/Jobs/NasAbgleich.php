<?php
namespace App\Services\Jobs;

use App\Services\SecretBox;

/**
 * NAS-Abgleich per rclone (T4, AP-20261012-datensicherung-nas, Vertrag Punkt 4). Kopiert
 * nur hinzu/aktualisiert (F3, nie `sync`/`move`/`delete`), Zugang ausschließlich per
 * Umgebungsvariablen (nie in der Befehlszeile/Log), SMB-Remote „on the fly“ als ein
 * Argument (`:smb:<freigabe>/<pfad>/...`). `proc_open` immer mit Argument-Array, nie Shell.
 *
 * Der rclone-Befehl ist injizierbar (E-034): Standard `['rclone']`, überschreibbar per
 * Konstruktor-Parameter (Tests) oder Umgebungsvariable `BK_RCLONE_BIN` (Betrieb ohne
 * rclone im PATH). Die Uhr ist ebenfalls injizierbar.
 */
final class NasAbgleich
{
    /** Gesamtzeitbudget der Schreibprobe (mkdir + rcat zusammen), s. verbindungTesten(). */
    private const VERBINDUNG_GESAMT_SEKUNDEN = 20;
    private const VERBINDUNG_OPTIONEN = ['--contimeout', '10s', '--timeout', '15s', '--retries', '1', '--low-level-retries', '1'];
    private const ABGLEICH_OPTIONEN = ['--contimeout', '15s', '--timeout', '5m', '--retries', '3', '--use-json-log', '--stats', '1m', '--stats-log-level', 'NOTICE'];
    /** 2 h 45 min – muss unter der Hängelauf-Grenze des Planers (3 h) bleiben. */
    private const ABGLEICH_HOECHSTDAUER_SEKUNDEN = 2 * 3600 + 45 * 60;
    private const STATUSDATEI = '_baukalkulation_status.json';
    private const VERBINDUNGSTESTDATEI = '_baukalkulation_verbindungstest.txt';

    /** Positivliste statt Ausschlussliste (Review R2) – Reihenfolge exakt laut Vertrag. */
    private const FILTER_REGELN = [
        '- /database.sqlite*',
        '- /secret.key',
        '- /systemadmin-passwort.txt',
        '- /audit_pepper.txt',
        '- /update/**',
        '- /run/**',
        '- /backups/.tmp-*/**',
        '- *.lock',
        '+ /backups/**',
        '+ /uploads/**',
        '+ /bautagebuch/**',
        '+ /datanorm/**',
        '+ /archiv/**',
        '+ /archiv_material/**',
        '+ /exports/**',
        '+ /pg-backups/**',
        '+ /paths_config.json',
        '+ /firma_logo.*',
        '+ /audit.log',
        '- **',
    ];
    /** Ausgelagerte Pfade aus paths_config.json, deren realpath außerhalb von DATA_DIR liegt. */
    private const EXTERNE_SCHLUESSEL = ['backups', 'archiv', 'exports', 'bautagebuch', 'uploads', 'datanorm'];
    /**
     * Zusätzliche, unverankerte harte Ausschlüsse für ausgelagerte Pfade (Reviewbefund 2b) –
     * im Unterschied zu FILTER_REGELN (root-verankert, `/…`) greifen diese in jeder Tiefe,
     * da externe Ordner nicht dieselbe feste Struktur wie data/ haben. Bewusst ohne ein
     * führendes Doppelstern-Muster (zwei Sternchen + Schrägstrich) vor dem Dateinamen: rclone
     * verlangt dafür, dass dem Treffer mindestens ein Verzeichnistrenner vorausgeht (Review
     * Runde 3) – Dateien im Wurzelordner eines externen Pfads wären damit nicht gefiltert
     * worden. Ein unverankertes Muster ohne führendes `/` oder Doppelstern-Präfix greift in
     * rclone dagegen auf jeder Ebene inklusive Wurzel (mit echtem rclone 1.60.1 via `lsf -R`
     * gegengeprüft, s. AP T4 Runde 3).
     */
    private const EXTERN_FILTER_REGELN = [
        '- secret.key',
        '- database.sqlite*',
        '- systemadmin-passwort.txt',
        '- audit_pepper.txt',
        '- *.env',
        '- .tmp-*/**',
        '- *.lock',
    ];
    /**
     * Systempfade, auf die ein ausgelagerter Pfad niemals zeigen darf (Reviewbefund 2a) –
     * auch nicht auf deren Unterordner.
     */
    private const SYSTEM_PFADE = [
        '/', '/etc', '/proc', '/sys', '/dev', '/root', '/boot', '/usr', '/bin', '/sbin',
        '/lib', '/lib64', '/var/lib', '/run', '/var/run',
        '/tmp', '/var/tmp', '/var/log', '/var/backups', '/var/spool', '/var/mail',
        '/var/cache', '/home',
    ];

    /** @var list<string> */
    private array $rcloneBefehl;
    /** @var callable(): \DateTimeImmutable */
    private $uhr;

    /** @param list<string> $rcloneBefehl Standard: ['rclone'], per BK_RCLONE_BIN überschreibbar (E-034). */
    public function __construct(
        private readonly \PDO $db,
        array $rcloneBefehl = ['rclone'],
        ?callable $uhr = null,
    ) {
        if ($rcloneBefehl === ['rclone']) {
            $env = getenv('BK_RCLONE_BIN');
            if (is_string($env) && $env !== '') {
                $rcloneBefehl = [$env];
            }
        }
        $this->rcloneBefehl = $rcloneBefehl;
        $this->uhr = $uhr ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Schreibprobe gegen den konkreten Zielpfad (höchstens 20 s gesamt): `mkdir` + `rcat`
     * einer Testdatei. Bleibt wegen F3 liegen. $nas kann unveröffentlichte Formularwerte
     * enthalten (z. B. aus der UI vor dem Speichern) – ein Klartext-Passwort wird akzeptiert
     * (SecretBox::isEncrypted() erkennt den Unterschied zum gespeicherten, verschlüsselten Wert).
     * @param array<string,mixed> $nas
     * @return array{ok:bool,fehlerCode?:string}
     */
    public function verbindungTesten(array $nas): array
    {
        $begin = microtime(true);
        try {
            $normalisiert = $this->konfigurationPruefen($nas);
            $passwort = $this->passwortKlartext($normalisiert);
            $obscured = $this->obscure($passwort);
            $env = $this->nasEnv($normalisiert, $obscured);

            $ziel = $this->remotePfad($normalisiert, '');
            $rest = $this->restSekunden($begin, self::VERBINDUNG_GESAMT_SEKUNDEN, 'NAS_VERBINDUNG');
            $ergebnis = $this->prozessAusfuehren(array_merge(['mkdir', $ziel], self::VERBINDUNG_OPTIONEN), $env, null, $rest);
            $this->pruefeRcloneFehler($ergebnis, $normalisiert, $obscured);

            $inhalt = $this->jetzt()->format('Y-m-d H:i:s') . ' – Baukalkulation ' . (defined('APP_VERSION') ? APP_VERSION : '') . "\n";
            $dateiZiel = $this->remotePfad($normalisiert, self::VERBINDUNGSTESTDATEI);
            $rest = $this->restSekunden($begin, self::VERBINDUNG_GESAMT_SEKUNDEN, 'NAS_VERBINDUNG');
            $ergebnis = $this->prozessAusfuehren(array_merge(['rcat', $dateiZiel], self::VERBINDUNG_OPTIONEN), $env, $inhalt, $rest);
            $this->pruefeRcloneFehler($ergebnis, $normalisiert, $obscured);

            return ['ok' => true];
        } catch (NasAbgleichFehler $e) {
            // NAS_ZEIT ist laut Vertrag die Höchstdauer des Abgleichs (Phase 3/4); die
            // Schreibprobe selbst hat nur ihr eigenes, kurzes Budget – ein hier auftretendes
            // NAS_ZEIT (z. B. aus prozessAusfuehren()) wird deshalb auf NAS_VERBINDUNG
            // abgebildet (Reviewbefund 4), auch wenn verbindungTesten() als Schreibprobe vor
            // dem eigentlichen Abgleich aufgerufen wird.
            $code = $e->fehlerCode === 'NAS_ZEIT' ? 'NAS_VERBINDUNG' : $e->fehlerCode;
            return ['ok' => false, 'fehlerCode' => $code];
        }
    }

    /**
     * Führt den eigentlichen Abgleich aus: Schreibprobe, Hauptkopie (data/ → .../data mit der
     * Positivliste), ausgelagerte Pfade (paths_config.json), Statusdatei. Liest die
     * NAS-Einstellungen selbst aus der Datenbank.
     * @return array{transfers:int,bytes:int,fehler:int,dauerSek:int,externePfade:int,externeUebersprungen:int}
     * @throws NasAbgleichFehler mit Phasen-Fehlercode (NAS_KONFIGURATION, NAS_RCLONE_FEHLT,
     *         NAS_ANMELDUNG, NAS_NICHT_ERREICHBAR, NAS_KEINE_SCHREIBRECHTE, NAS_VERBINDUNG,
     *         NAS_TRANSFER, NAS_ZEIT)
     */
    public function abgleichen(): array
    {
        $start = microtime(true);
        $nasRoh = SystemEinstellungen::laden($this->db)['nas'] ?? [];
        $normalisiert = $this->konfigurationPruefen(is_array($nasRoh) ? $nasRoh : []);

        // Phase 2: Schreibprobe (dieselben Fehlercodes wie verbindungTesten()).
        $test = $this->verbindungTesten($normalisiert);
        if (!$test['ok']) {
            throw new NasAbgleichFehler((string)($test['fehlerCode'] ?? 'NAS_VERBINDUNG'), 'Schreibprobe vor dem Abgleich fehlgeschlagen.');
        }

        $passwort = $this->passwortKlartext($normalisiert);
        $obscured = $this->obscure($passwort);
        $env = $this->nasEnv($normalisiert, $obscured);
        $bandbreite = (int)($normalisiert['bandbreiteKBs'] ?? 0);

        $kennzahlen = ['transfers' => 0, 'bytes' => 0, 'fehler' => 0, 'dauerSek' => 0, 'externePfade' => 0, 'externeUebersprungen' => 0];
        $transferFehler = false;

        // Phase 3: Hauptkopie data/ -> :smb:<freigabe>/<pfad>/data
        $ziel = $this->remotePfad($normalisiert, 'data');
        $argumente = array_merge(['copy', rtrim(DATA_DIR, '/\\'), $ziel], $this->filterArgumente(), self::ABGLEICH_OPTIONEN);
        if ($bandbreite > 0) {
            $argumente[] = '--bwlimit';
            $argumente[] = $bandbreite . 'k';
        }
        $rest = $this->restSekundenAbgleich($start);
        $ergebnis = $this->prozessAusfuehren($argumente, $env, null, $rest);
        $zahlen = $this->statsAuswerten($ergebnis, $normalisiert, $obscured, 'Hauptkopie');
        $kennzahlen['transfers'] += $zahlen['transfers'];
        $kennzahlen['bytes'] += $zahlen['bytes'];
        $kennzahlen['fehler'] += $zahlen['fehler'];
        if ($ergebnis['exitCode'] !== 0) $transferFehler = true;

        // Phase 4: ausgelagerte Pfade aus paths_config.json, außerhalb von DATA_DIR
        $extern = $this->externePfade();
        $kennzahlen['externeUebersprungen'] = $extern['uebersprungen'];
        foreach ($extern['erlaubt'] as $schluessel => $quellpfad) {
            $rest = $this->restSekundenAbgleich($start);
            $zielExtern = $this->remotePfad($normalisiert, 'extern/' . $schluessel);
            $argumenteExtern = array_merge(
                ['copy', $quellpfad, $zielExtern],
                $this->externFilterArgumente(),
                self::ABGLEICH_OPTIONEN,
            );
            if ($bandbreite > 0) {
                $argumenteExtern[] = '--bwlimit';
                $argumenteExtern[] = $bandbreite . 'k';
            }
            $ergebnisExtern = $this->prozessAusfuehren($argumenteExtern, $env, null, $rest);
            $zahlenExtern = $this->statsAuswerten($ergebnisExtern, $normalisiert, $obscured, 'Externer Pfad ' . $schluessel);
            $kennzahlen['transfers'] += $zahlenExtern['transfers'];
            $kennzahlen['bytes'] += $zahlenExtern['bytes'];
            $kennzahlen['fehler'] += $zahlenExtern['fehler'];
            $kennzahlen['externePfade']++;
            if ($ergebnisExtern['exitCode'] !== 0) $transferFehler = true;
        }

        $kennzahlen['dauerSek'] = (int)round(microtime(true) - $start);

        // Phase 5: Statusdatei – bei Erfolg UND bei Transferfehlern; ein Fehler dabei wird nur geloggt.
        try {
            $statusJson = json_encode([
                'zeit'      => $this->jetzt()->format('Y-m-d H:i:s'),
                'ergebnis'  => $transferFehler ? 'fehler' : 'ok',
                'version'   => defined('APP_VERSION') ? APP_VERSION : '',
                'transfers' => $kennzahlen['transfers'],
                'fehler'    => $kennzahlen['fehler'],
            ], JSON_UNESCAPED_UNICODE) ?: '{}';
            $statusZiel = $this->remotePfad($normalisiert, self::STATUSDATEI);
            $this->prozessAusfuehren(array_merge(['rcat', $statusZiel], self::VERBINDUNG_OPTIONEN), $env, $statusJson, 20);
        } catch (\Throwable $e) {
            error_log('[NasAbgleich] Statusdatei konnte nicht geschrieben werden: ' . $e->getMessage());
        }

        if ($transferFehler) {
            throw new NasAbgleichFehler('NAS_TRANSFER', 'Der NAS-Abgleich meldete Übertragungsfehler.', $kennzahlen);
        }
        return $kennzahlen;
    }

    private function jetzt(): \DateTimeImmutable
    {
        return ($this->uhr)();
    }

    /**
     * @param array<string,mixed> $nas
     * @return array{host:string,port:int,freigabe:string,pfad:string,benutzer:string,domaene:string,bandbreiteKBs:int,passwort:string}
     */
    private function konfigurationPruefen(array $nas): array
    {
        try {
            $normalisiert = SystemEinstellungen::pruefeNasFelder($nas);
        } catch (\InvalidArgumentException $e) {
            throw new NasAbgleichFehler('NAS_KONFIGURATION', $e->getMessage());
        }
        if ($normalisiert['host'] === '' || $normalisiert['freigabe'] === '' || $normalisiert['benutzer'] === '') {
            throw new NasAbgleichFehler('NAS_KONFIGURATION', 'NAS-Zugangsdaten unvollständig.');
        }
        $passwortRoh = $nas['passwort'] ?? '';
        if (!is_string($passwortRoh)) {
            // Nicht-String vor dem Cast ablehnen (Reviewbefund 8) – kein `(string)`-Warning/-Exception.
            throw new NasAbgleichFehler('NAS_KONFIGURATION', 'Ungültiges NAS-Passwort.');
        }
        $normalisiert['passwort'] = $passwortRoh;
        return $normalisiert;
    }

    /** @param array<string,mixed> $nas verschlüsselt (gespeichert) oder Klartext (Formular-Testaufruf). */
    private function passwortKlartext(array $nas): string
    {
        $gespeichert = (string)($nas['passwort'] ?? '');
        if ($gespeichert === '') {
            throw new NasAbgleichFehler('NAS_KONFIGURATION', 'Kein NAS-Passwort hinterlegt.');
        }
        if (!SecretBox::isEncrypted($gespeichert)) {
            return $gespeichert;
        }
        try {
            return SecretBox::decrypt($gespeichert);
        } catch (\Throwable $e) {
            error_log('[NasAbgleich] NAS-Passwort kann nicht entschlüsselt werden: ' . $e->getMessage());
            throw new NasAbgleichFehler('NAS_KONFIGURATION', 'NAS-Passwort kann nicht entschlüsselt werden.');
        }
    }

    /** `rclone obscure -` liest das Klartext-Passwort ausschließlich über stdin (nie Argument/Log). */
    private function obscure(string $klartext): string
    {
        $ergebnis = $this->prozessAusfuehren(['obscure', '-'], [], $klartext, 10);
        if ($ergebnis['exitCode'] !== 0) {
            throw new NasAbgleichFehler('NAS_RCLONE_FEHLT', 'Passwort konnte nicht verschleiert werden.');
        }
        return trim($ergebnis['stdout']);
    }

    /** @param array<string,mixed> $nas @return array<string,string> nur Env, nie Argument/Log. */
    private function nasEnv(array $nas, string $passwortObscured): array
    {
        $env = [
            'RCLONE_SMB_HOST' => (string)($nas['host'] ?? ''),
            'RCLONE_SMB_PORT' => (string)($nas['port'] ?? 445),
            'RCLONE_SMB_USER' => (string)($nas['benutzer'] ?? ''),
            'RCLONE_SMB_PASS' => $passwortObscured,
        ];
        $domaene = (string)($nas['domaene'] ?? '');
        if ($domaene !== '') {
            $env['RCLONE_SMB_DOMAIN'] = $domaene;
        }
        return $env;
    }

    /**
     * Remote „on the fly“ als ein Argument (Review R4: ohne `:smb:` wäre es ein lokaler
     * Containerpfad und würde fälschlich „gelingen“). Freigabe/Pfad sind bereits durch
     * pruefeNasFelder() auf eine enge Zeichenklasse ohne `:`/`,`/`{}`/`..` geprüft.
     * @param array<string,mixed> $nas normalisiert (konfigurationPruefen())
     */
    private function remotePfad(array $nas, string $unterordner): string
    {
        $freigabe = (string)($nas['freigabe'] ?? '');
        $pfad = trim((string)($nas['pfad'] ?? ''), '/');
        $teile = array_values(array_filter([$freigabe, $pfad, $unterordner], static fn ($t) => $t !== ''));
        return ':smb:' . implode('/', $teile);
    }

    private function filterArgumente(): array
    {
        $out = [];
        foreach (self::FILTER_REGELN as $regel) {
            $out[] = '--filter';
            $out[] = $regel;
        }
        return $out;
    }

    /** Unverankerte harte Ausschlüsse für ausgelagerte (externe) Pfade (Reviewbefund 2b). */
    private function externFilterArgumente(): array
    {
        $out = [];
        foreach (self::EXTERN_FILTER_REGELN as $regel) {
            $out[] = '--filter';
            $out[] = $regel;
        }
        return $out;
    }

    /**
     * @return array{erlaubt:array<string,string>,uebersprungen:int} erlaubt: Schlüssel =>
     *         realpath, nur Pfade außerhalb von DATA_DIR, die weder Vorfahr/gleich von
     *         DATA_DIR/APP_ROOT noch innerhalb von APP_ROOT (z. B. `src`, `vendor`) noch ein
     *         Systempfad sind (Reviewbefund 2a, Hinweis Runde 3); uebersprungen: Anzahl der
     *         deswegen übersprungenen, sonst konfigurierten Pfade.
     */
    private function externePfade(): array
    {
        $datei = rtrim(DATA_DIR, '/\\') . '/paths_config.json';
        if (!is_file($datei)) return ['erlaubt' => [], 'uebersprungen' => 0];
        $konfiguriert = json_decode((string)file_get_contents($datei), true);
        if (!is_array($konfiguriert)) return ['erlaubt' => [], 'uebersprungen' => 0];

        $dataDirReal = realpath(rtrim(DATA_DIR, '/\\'));
        $appRootReal = defined('APP_ROOT') ? realpath((string)APP_ROOT) : false;
        $erlaubt = [];
        $uebersprungen = 0;
        foreach (self::EXTERNE_SCHLUESSEL as $schluessel) {
            $pfad = $konfiguriert[$schluessel] ?? null;
            if (!is_string($pfad) || $pfad === '') continue;
            $real = realpath($pfad);
            if ($real === false) continue;
            if ($dataDirReal !== false && ($real === $dataDirReal || str_starts_with($real, $dataDirReal . DIRECTORY_SEPARATOR))) {
                continue; // liegt innerhalb von DATA_DIR -> bereits über die Positivliste abgedeckt
            }
            if ($this->istGesperrterExternerPfad($real, $dataDirReal, $appRootReal)) {
                error_log('[NasAbgleich] Ausgelagerter Pfad übersprungen (Vorfahr von DATA_DIR/APP_ROOT, innerhalb von APP_ROOT oder Systempfad): Schlüssel=' . $schluessel);
                $uebersprungen++;
                continue;
            }
            $erlaubt[$schluessel] = $real;
        }
        return ['erlaubt' => $erlaubt, 'uebersprungen' => $uebersprungen];
    }

    /**
     * true, wenn $real auf DATA_DIR/APP_ROOT zeigt bzw. deren Vorfahr ist (Geheimnis-Exfiltration,
     * Reviewbefund 2a), innerhalb von APP_ROOT liegt (z. B. `src`, `vendor`; der Aufrufer hat
     * „innerhalb DATA_DIR“ bereits vorher ausgefiltert, s. externePfade()) oder ein Systempfad
     * bzw. dessen Unterordner ist (Hinweis Runde 3).
     * @param string|false $dataDirReal @param string|false $appRootReal
     */
    private function istGesperrterExternerPfad(string $real, string|false $dataDirReal, string|false $appRootReal): bool
    {
        foreach ([$dataDirReal, $appRootReal] as $anker) {
            if ($anker !== false && $this->istVorfahrOderGleich($real, $anker)) {
                return true;
            }
        }
        if ($appRootReal !== false && $this->istVorfahrOderGleich($appRootReal, $real)) {
            return true; // liegt innerhalb von APP_ROOT, aber (laut Aufrufer) außerhalb von DATA_DIR
        }
        $realNorm = str_replace('\\', '/', $real);
        foreach (self::SYSTEM_PFADE as $systemPfad) {
            if ($this->istVorfahrOderGleich($systemPfad, $realNorm)) {
                return true;
            }
        }
        return false;
    }

    /** true, wenn $vorfahr gleich $pfad ist oder ein Vorfahren-Verzeichnis von $pfad. */
    private function istVorfahrOderGleich(string $vorfahr, string $pfad): bool
    {
        $vorfahrNorm = rtrim(str_replace('\\', '/', $vorfahr), '/');
        if ($vorfahrNorm === '') $vorfahrNorm = '/';
        $pfadNorm = str_replace('\\', '/', $pfad);
        return $pfadNorm === $vorfahrNorm || str_starts_with($pfadNorm, $vorfahrNorm . '/');
    }

    /** @param array<string,mixed> $nas */
    private function pruefeRcloneFehler(array $ergebnis, array $nas, string $obscuredPasswort): void
    {
        if ($ergebnis['exitCode'] === 0) return;
        $ausgabe = $ergebnis['stdout'] . "\n" . $ergebnis['stderr'];
        error_log('[NasAbgleich] rclone-Fehler: ' . $this->bereinigen($ausgabe, $nas, $obscuredPasswort));

        // Erkennung case-insensitive (Reviewbefund 13): rclone/Windows-SMB-Treiber geben die
        // Meldungen nicht immer kleingeschrieben aus.
        $ausgabeKlein = mb_strtolower($ausgabe);
        $code = match (true) {
            str_contains($ausgabeKlein, 'logon is invalid') => 'NAS_ANMELDUNG',
            str_contains($ausgabeKlein, 'no such host'), str_contains($ausgabeKlein, 'dial tcp'),
            str_contains($ausgabeKlein, 'i/o timeout'), str_contains($ausgabeKlein, 'connection refused') => 'NAS_NICHT_ERREICHBAR',
            str_contains($ausgabeKlein, 'permission denied'), str_contains($ausgabeKlein, 'access denied'),
            str_contains($ausgabeKlein, 'access is denied') => 'NAS_KEINE_SCHREIBRECHTE',
            default => 'NAS_VERBINDUNG',
        };
        throw new NasAbgleichFehler($code, 'rclone meldete einen Fehler bei der Schreibprobe.');
    }

    /** @param array<string,mixed> $nas @return array{transfers:int,bytes:int,fehler:int} */
    private function statsAuswerten(array $ergebnis, array $nas, string $obscuredPasswort, string $kontext): array
    {
        $text = $ergebnis['stdout'] . "\n" . $ergebnis['stderr'];
        $letzteStats = null;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $zeile) {
            if ($zeile === '' || !str_contains($zeile, '"stats"')) continue;
            $decoded = json_decode($zeile, true);
            if (is_array($decoded) && is_array($decoded['stats'] ?? null)) {
                $letzteStats = $decoded['stats'];
            }
        }
        if ($ergebnis['exitCode'] !== 0) {
            error_log('[NasAbgleich] rclone copy (' . $kontext . ') meldete Fehler (Exit ' . $ergebnis['exitCode'] . '): '
                . $this->bereinigen($text, $nas, $obscuredPasswort));
        }
        return [
            'transfers' => (int)($letzteStats['transfers'] ?? 0),
            'bytes'     => (int)($letzteStats['bytes'] ?? 0),
            'fehler'    => (int)($letzteStats['errors'] ?? ($ergebnis['exitCode'] !== 0 ? 1 : 0)),
        ];
    }

    /** Host, Benutzer, Passwort und das verschleierte Passwort werden vor jedem error_log() entfernt. */
    private function bereinigen(string $text, array $nas, string $obscuredPasswort): string
    {
        foreach ([(string)($nas['host'] ?? ''), (string)($nas['benutzer'] ?? ''), $obscuredPasswort] as $wert) {
            if ($wert !== '') {
                $text = str_replace($wert, '***', $text);
            }
        }
        return mb_substr($text, 0, 2000);
    }

    private function restSekunden(float $begin, int $gesamt, string $fehlerCodeBeiZeitueberschreitung): int
    {
        $rest = $gesamt - (int)floor(microtime(true) - $begin);
        if ($rest < 1) {
            throw new NasAbgleichFehler($fehlerCodeBeiZeitueberschreitung, 'Zeitüberschreitung bei der Schreibprobe.');
        }
        return $rest;
    }

    private function restSekundenAbgleich(float $begin): int
    {
        $rest = self::ABGLEICH_HOECHSTDAUER_SEKUNDEN - (int)floor(microtime(true) - $begin);
        if ($rest < 1) {
            throw new NasAbgleichFehler('NAS_ZEIT', 'Höchstdauer des NAS-Abgleichs überschritten.');
        }
        return $rest;
    }

    /**
     * Führt rclone per proc_open mit Argument-Array aus (nie Shell). Minimal-Env (PATH, HOME,
     * RCLONE_CONFIG = nicht vorhandene Datei im run-Verzeichnis) + $zusatzEnv (Zugangsdaten).
     * Zeitbegrenzung über eine stream_select-Schleife, danach proc_terminate. Kein `@` (E-063) –
     * stattdessen ein vorübergehend deaktivierter Error-Handler um mkdir/proc_open/fwrite/stream_select.
     * Exit-Code: `proc_close()` liefert auf PHP < 8.3 nach einem bereits beobachteten
     * `proc_get_status()` mit `running=false` fälschlich -1 (Reviewbefund 1) – deshalb wird der
     * Exit-Code beim ersten `running===false` aus `$status['exitcode']` gemerkt (nur dann
     * gültig laut PHP-Doku) und verwendet; die Schleife kann nur darüber oder per `throw`
     * (Zeitüberschreitung) enden, `proc_close()` wird weiterhin aufgerufen (Ressource
     * freigeben), sein Rückgabewert aber nicht mehr als Exit-Code genutzt.
     * @param list<string> $argumente @param array<string,string> $zusatzEnv
     * @return array{exitCode:int,stdout:string,stderr:string}
     */
    private function prozessAusfuehren(array $argumente, array $zusatzEnv, ?string $stdin, int $timeoutSekunden): array
    {
        $runDir = rtrim(DATA_DIR, '/\\') . '/run';
        if (!is_dir($runDir)) {
            set_error_handler(static fn (): bool => true);
            try {
                $erstellt = mkdir($runDir, 0750, true);
            } finally {
                restore_error_handler();
            }
            if (!$erstellt && !is_dir($runDir)) {
                throw new NasAbgleichFehler('NAS_RCLONE_FEHLT', 'Sperrverzeichnis konnte nicht erstellt werden.');
            }
        }

        $env = array_merge([
            'PATH'           => (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'HOME'           => $runDir,
            'RCLONE_CONFIG'  => $runDir . '/rclone-leer.conf',
        ], $zusatzEnv);

        $befehl = array_merge($this->rcloneBefehl, $argumente);
        $deskriptoren = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        set_error_handler(static fn (): bool => true);
        try {
            $prozess = proc_open($befehl, $deskriptoren, $pipes, $runDir, $env);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($prozess)) {
            throw new NasAbgleichFehler('NAS_RCLONE_FEHLT', 'rclone konnte nicht gestartet werden.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if ($stdin !== null && $stdin !== '') {
            set_error_handler(static fn (): bool => true);
            try {
                $geschrieben = fwrite($pipes[0], $stdin);
            } finally {
                restore_error_handler();
            }
            if ($geschrieben === false) {
                fclose($pipes[0]);
                $this->prozessAbbrechen($prozess, $pipes[1], $pipes[2]);
                throw new NasAbgleichFehler('NAS_RCLONE_FEHLT', 'rclone konnte nicht angesprochen werden.');
            }
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        /** Nur beim ersten running===false gültig (PHP < 8.3: proc_close() liefert danach -1). */
        $exitCodeGemerkt = null;
        while (true) {
            $status = proc_get_status($prozess);
            $uebrig = $timeoutSekunden - (microtime(true) - $start);
            if ($uebrig <= 0) {
                proc_terminate($prozess);
                usleep(200_000);
                if (proc_get_status($prozess)['running']) {
                    proc_terminate($prozess, 9);
                }
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($prozess);
                throw new NasAbgleichFehler('NAS_ZEIT', 'Zeitüberschreitung beim rclone-Aufruf.');
            }

            $lesen = [$pipes[1], $pipes[2]];
            $schreiben = null;
            $ausnahme = null;
            set_error_handler(static fn (): bool => true);
            try {
                $anzahl = stream_select($lesen, $schreiben, $ausnahme, (int)floor($uebrig), (int)(($uebrig - floor($uebrig)) * 1_000_000));
            } finally {
                restore_error_handler();
            }
            if ($anzahl !== false) {
                foreach ($lesen as $stream) {
                    $daten = fread($stream, 65536);
                    if ($daten === false || $daten === '') continue;
                    if ($stream === $pipes[1]) $stdout .= $daten; else $stderr .= $daten;
                }
            }
            if (!$status['running']) {
                $exitCodeGemerkt = $status['exitcode'];
                break;
            }
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // Rückgabewert von proc_close() hier nicht als Exit-Code verwenden (Reviewbefund 1):
        // PHP < 8.3 liefert nach einem bereits beobachteten running=false fälschlich -1; der
        // echte Exit-Code wurde oben beim ersten running===false bereits gemerkt (einzige
        // Möglichkeit, diese Schleife ohne throw zu verlassen). proc_close() wird trotzdem
        // aufgerufen, um die Prozess-Ressource ordentlich freizugeben.
        proc_close($prozess);
        $exitCode = $exitCodeGemerkt;

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Bricht einen laufenden Prozess sauber ab (Terminate, Pipes/Prozess schließen) – genutzt,
     * wenn das Schreiben auf die stdin-Pipe fehlschlägt (Reviewbefund 6).
     * @param resource $prozess @param resource $stdoutPipe @param resource $stderrPipe
     */
    private function prozessAbbrechen($prozess, $stdoutPipe, $stderrPipe): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $status = proc_get_status($prozess);
            if ($status['running']) {
                proc_terminate($prozess);
                usleep(200_000);
                $status = proc_get_status($prozess);
                if ($status['running']) {
                    proc_terminate($prozess, 9);
                }
            }
            if (is_resource($stdoutPipe)) fclose($stdoutPipe);
            if (is_resource($stderrPipe)) fclose($stderrPipe);
            proc_close($prozess);
        } finally {
            restore_error_handler();
        }
    }
}
