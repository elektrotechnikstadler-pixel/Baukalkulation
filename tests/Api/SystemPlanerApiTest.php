<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Backup\BackupArchive;

final class SystemPlanerApiTest extends ApiTestCase
{
    private function runPlaner(): string
    {
        [$code, $out] = $this->server->runCli('bin/console', ['system:planer']);
        $this->assertSame(0, $code, $out);
        return $out;
    }

    private function setSicherung(string $uhrzeit, int $aufbewahrungTage): void
    {
        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'sicherung' => ['uhrzeit' => $uhrzeit, 'aufbewahrungTage' => $aufbewahrungTage],
        ]));
    }

    private function backupRoot(): string
    {
        return $this->server->dataPath('backups');
    }

    private function backupDir(string $name): string
    {
        return $this->backupRoot() . DIRECTORY_SEPARATOR . $name;
    }

    /** @return list<string> */
    private function listDailyBackupNames(): array
    {
        $dirs = glob($this->backupRoot() . DIRECTORY_SEPARATOR . '????-??-??', GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);
        return $names;
    }

    private function createSimpleBackupDir(string $name): void
    {
        $dir = $this->backupDir($name);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . BackupArchive::FILE_JSON, json_encode([
            'v' => 'test',
            'ts' => date('c'),
            'data' => ['baustellen' => []],
        ], JSON_UNESCAPED_UNICODE));
        file_put_contents($dir . DIRECTORY_SEPARATOR . BackupArchive::FILE_DB, 'dummy');
    }

    private function createInvalidDailyBackup(string $day): void
    {
        $this->createSimpleBackupDir($day);
        file_put_contents($this->backupDir($day) . DIRECTORY_SEPARATOR . BackupArchive::FILE_MANIFEST, json_encode([
            'format' => 2,
            'files' => [
                BackupArchive::FILE_JSON => ['sha256' => 'deadbeef'],
                BackupArchive::FILE_DB => ['sha256' => 'deadbeef'],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function tableCount(string $table): int
    {
        return (int) $this->server->db()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function statusRow(string $job): array
    {
        $stmt = $this->server->db()->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute([$job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    public function testPlanerErstelltTaeglicheSicherungNurEinmalUndSetztStatus(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $this->runPlaner();

        $dailyDir = $this->backupDir($today);
        $this->assertDirectoryExists($dailyDir);
        BackupArchive::fromDir($dailyDir);

        $status = $this->statusRow('sicherung');
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
        $this->assertSame($today, $status['tageslaufDatum'] ?? null);
        $this->assertNotSame('', (string) ($this->statusRow('planer')['letzterLaufAm'] ?? ''));
        $this->assertSame(1, $this->tableCount('job_laeufe'));
        $this->assertSame('erfolg', $this->server->db()->query("SELECT status FROM job_laeufe WHERE job='sicherung' ORDER BY id DESC LIMIT 1")->fetchColumn());

        $this->runPlaner();

        $this->assertSame([$today], $this->listDailyBackupNames());
        $this->assertSame(1, $this->tableCount('job_laeufe'));
    }

    public function testUngueltigerTagesordnerWirdErsetztUndTmpOrdnerNichtGelistet(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->createInvalidDailyBackup($today);
        $tmpRest = $this->backupDir('.tmp-test-rest');
        if (!is_dir($tmpRest)) {
            mkdir($tmpRest, 0777, true);
        }
        file_put_contents($tmpRest . DIRECTORY_SEPARATOR . BackupArchive::FILE_JSON, '{}');

        $this->runPlaner();

        $dailyDir = $this->backupDir($today);
        $manifest = json_decode((string) file_get_contents($dailyDir . DIRECTORY_SEPARATOR . BackupArchive::FILE_MANIFEST), true);
        $this->assertNotSame('deadbeef', $manifest['files'][BackupArchive::FILE_DB]['sha256'] ?? null);
        BackupArchive::fromDir($dailyDir);

        $listed = array_column($this->assertOk($this->api->get('backups'))['backups'], 'date');
        $this->assertNotContains('.tmp-test-rest', $listed);
        foreach ($listed as $name) {
            $this->assertStringStartsNotWith('.tmp-', (string) $name);
        }
    }

    public function testAufbewahrungBehaeltNurDreiNeuesteTagessicherungenUndLaesstAndereKategorienStehen(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 3);
        $today = new \DateTimeImmutable();
        for ($i = 1; $i <= 5; $i++) {
            $this->createSimpleBackupDir($today->modify("-{$i} days")->format('Y-m-d'));
        }
        $manualName = $today->modify('-10 days')->format('Y-m-d') . '_manuell';
        $preName = $today->modify('-9 days')->format('Y-m-d') . '_pre-restore';
        $this->createSimpleBackupDir($manualName);
        $this->createSimpleBackupDir($preName);

        $this->runPlaner();

        $this->assertEqualsCanonicalizing([
            $today->format('Y-m-d'),
            $today->modify('-1 days')->format('Y-m-d'),
            $today->modify('-2 days')->format('Y-m-d'),
        ], $this->listDailyBackupNames());
        $this->assertDirectoryExists($this->backupDir($manualName));
        $this->assertDirectoryExists($this->backupDir($preName));
    }

    public function testSpeichernErstelltTagessicherungAlsRueckfallOhneBestehendeSicherung(): void
    {
        $this->setupAdmin();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $dailyDir = $this->backupDir($today);
        $this->assertDirectoryDoesNotExist($dailyDir);

        $saved = $this->saveBaustellen([['id' => 1, 'name' => 'Rueckfalltest']]);
        $this->assertIsInt($saved['rev'] ?? null);

        $this->assertDirectoryExists($dailyDir);
        BackupArchive::fromDir($dailyDir);
        $this->assertSame('speichern', $this->server->db()->query("SELECT ausloeser FROM job_laeufe WHERE job='sicherung' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testSystemJobsApiRechteMethodenUndValidierung(): void
    {
        $this->setupAdmin();
        $normal = $this->createActiveUser('normal-user', 'Normal-Pass-1', 'normal');
        $master = $this->createActiveUser('master-user', 'Master-Pass-1', 'master');
        $anon = $this->server->client();

        $this->assertContains($anon->get('system_jobs_status')->status, [401, 403]);
        $this->assertContains($anon->post('system_einstellungen_speichern', ['sicherung' => ['uhrzeit' => '00:00', 'aufbewahrungTage' => 7]])->status, [401, 403]);
        $this->assertContains($anon->post('job_anfordern', ['job' => 'sicherung'])->status, [401, 403]);
        $this->assertContains($anon->get('job_auftrag_status', ['id' => 'nicht-da'])->status, [401, 403]);

        $this->assertStatus(403, $normal->get('system_jobs_status'));
        $this->assertStatus(403, $normal->post('system_einstellungen_speichern', ['sicherung' => ['uhrzeit' => '00:00', 'aufbewahrungTage' => 7]]));
        $this->assertStatus(403, $normal->post('job_anfordern', ['job' => 'sicherung']));
        $this->assertStatus(403, $normal->get('job_auftrag_status', ['id' => 'x']));
        $this->assertStatus(403, $master->get('system_jobs_status'));
        $this->assertStatus(403, $master->post('system_einstellungen_speichern', ['sicherung' => ['uhrzeit' => '00:00', 'aufbewahrungTage' => 7]]));
        $this->assertStatus(403, $master->post('job_anfordern', ['job' => 'sicherung']));
        $this->assertStatus(403, $master->get('job_auftrag_status', ['id' => 'x']));

        $statusResponse = $this->api->get('system_jobs_status');
        $this->assertOk($statusResponse);
        $body = $statusResponse->body;
        $this->assertStringNotContainsString('DATA_DIR', $body);
        $this->assertStringNotContainsString('BACKUP_DIR', $body);
        $this->assertStringNotContainsString(str_replace('\\', '/', $this->server->dataDir), str_replace('\\', '/', $body));

        $check = $anon->get('check')->json();
        foreach (['einstellungen', 'status', 'laeufe', 'planerAktiv'] as $key) {
            $this->assertArrayNotHasKey($key, $check);
        }

        $this->assertStatus(405, $this->api->get('system_einstellungen_speichern'));
        $this->assertStatus(405, $this->api->get('job_anfordern'));

        $this->assertOk($this->api->post('system_einstellungen_speichern', ['sicherung' => ['uhrzeit' => '06:15', 'aufbewahrungTage' => 8]]));
        foreach ([
            ['uhrzeit' => '25:00', 'aufbewahrungTage' => 8],
            ['uhrzeit' => '06:15', 'aufbewahrungTage' => 2],
            ['uhrzeit' => '06:15', 'aufbewahrungTage' => 91],
            ['uhrzeit' => '06:15', 'aufbewahrungTage' => 'Text'],
        ] as $invalid) {
            $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', ['sicherung' => $invalid]));
        }
        $stored = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen']['sicherung'];
        $this->assertSame('06:15', $stored['uhrzeit']);
        $this->assertSame(8, $stored['aufbewahrungTage']);

        $this->assertStatus(400, $this->api->post('job_anfordern', ['job' => 'unbekannt']));
        $first = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $second = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $this->assertSame($first, $second);

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $first]));
        $this->assertArrayNotHasKey('payload', $auftrag);
    }

    public function testAuftragWirdNachPlanerLaufAlsFertigGemeldet(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];

        $this->runPlaner();

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $id]));
        $this->assertSame('fertig', $auftrag['status']);
        $this->assertArrayNotHasKey('payload', $auftrag);
    }

    public function testRestoreImportiertKeineJobTabellenUndFixtureV3020IstImFixtureTestEnthalten(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $this->runPlaner();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db = $this->server->db();
        $db->prepare(
            "INSERT INTO job_auftraege (id, job, status, erstelltAm, ablaufAm, aktualisiertAm, angefordertVon, payload, ergebnis)
             VALUES (?, 'sicherung', 'wartend', ?, ?, ?, 'admin', '', '{}')",
        )->execute(['fixture-auftrag', $now, $now, $now]);
        $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES ('fixture-key', 'backupmail', 'fehler', '{}', ?, '', 0, '')",
        )->execute([$now]);

        $download = $this->api->get('backup_download');
        $this->assertSame(200, $download->status, $download->describe());
        $zipBytes = $download->body;
        $db = null;
        gc_collect_cycles();

        $this->server->resetData();
        $this->api = $this->server->client();
        $this->setupAdmin();

        $zipPath = $this->server->dataPath('import-jobtabellen.zip');
        file_put_contents($zipPath, $zipBytes);
        $this->assertOk($this->api->upload('backup_upload', 'backup', $zipPath));

        foreach (['job_status', 'job_laeufe', 'job_auftraege', 'meldungen_outbox'] as $table) {
            $this->assertSame(0, $this->tableCount($table), $table);
        }

        $fixtures = iterator_to_array(BackupFixtureImportTest::fixtures());
        $this->assertArrayHasKey('v3.0.20.zip', $fixtures);
    }

    public function testCronBackupEmailProtokolliertDeaktiviertNichtUndDedupliziertFehlerImSelbenZyklus(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_erinnerung_settings', [
            'backup_email_aktiv' => false,
            'backup_email_uhrzeit' => '00:00',
        ]));

        [$codeOff, $outOff] = $this->server->runCli('cron_backup_email.php');
        $this->assertSame(0, $codeOff, $outOff);
        $this->assertSame(0, $this->tableCount('job_laeufe'));

        $this->assertOk($this->api->post('save_erinnerung_settings', [
            'backup_email_aktiv' => true,
            'backup_email_empfaenger' => '',
            'backup_email_zyklus' => 'taeglich',
            'backup_email_uhrzeit' => '00:00',
        ]));

        [$code1, $out1] = $this->server->runCli('cron_backup_email.php');
        [$code2, $out2] = $this->server->runCli('cron_backup_email.php');
        $this->assertSame(1, $code1, $out1);
        $this->assertSame(1, $code2, $out2);

        $this->assertSame(1, (int) $this->server->db()->query("SELECT COUNT(*) FROM job_laeufe WHERE job = 'backupmail'")->fetchColumn());
        $row = $this->server->db()->query("SELECT status, fehlerCode FROM job_laeufe WHERE job = 'backupmail' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('fehler', $row['status'] ?? null);
        $this->assertSame('BACKUPMAIL_EMPFAENGER', $row['fehlerCode'] ?? null);
    }

    public function testBekannterBefundSicherungsSperreDarfNichtAlsErfolgZaehlen(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $runDir = $this->server->dataPath('run');
        if (!is_dir($runDir)) {
            mkdir($runDir, 0777, true);
        }
        $lockPath = $runDir . DIRECTORY_SEPARATOR . 'sicherung.lock';
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'Vorbedingung: Sperre muss exklusiv gehalten werden.');

        try {
            $this->runPlaner();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertGreaterThanOrEqual(1, $this->tableCount('job_laeufe'), 'Vorbedingung: Planer muss einen Sicherungslauf protokolliert haben.');
        $status = $this->statusRow('sicherung');
        $this->assertSame('fehler', $status['tageslaufStatus'] ?? null);
        $lauf = $this->server->db()->query("SELECT status, fehlerCode FROM job_laeufe WHERE job = 'sicherung' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame('fehler', $lauf['status'] ?? null);
        $this->assertSame('SICHERUNG_GESPERRT', $lauf['fehlerCode'] ?? null);
    }

    public function testSicherungErstellenCommandScheitertBeiGehaltenerSperreOhneNeuenTagesordner(): void
    {
        $this->setupAdmin();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $dailyDir = $this->backupDir($today);
        $this->assertDirectoryDoesNotExist($dailyDir);

        $runDir = $this->server->dataPath('run');
        if (!is_dir($runDir)) {
            mkdir($runDir, 0777, true);
        }
        $lockPath = $runDir . DIRECTORY_SEPARATOR . 'sicherung.lock';
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'Vorbedingung: Sperre muss exklusiv gehalten werden.');

        try {
            [$code, $out] = $this->server->runCli('bin/console', ['sicherung:erstellen']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertNotSame(0, $code, $out);
        $this->assertStringContainsString('gesperrt', $out);
        $this->assertDirectoryDoesNotExist($dailyDir);
    }

    public function testHaengenderLaufenderAuftragWirdAbgebrochenUndNeueAnforderungErhaeltNeueId(): void
    {
        $this->setupAdmin();

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $stale = (new \DateTimeImmutable('-4 hours'))->format('Y-m-d H:i:s');
        $db = $this->server->db();
        $db->prepare("UPDATE job_auftraege SET status = 'laufend', aktualisiertAm = ?, ergebnis = '{}' WHERE id = ?")
            ->execute([$stale, $id]);
        $db->prepare("UPDATE job_status SET laeuftSeit = ?, letzterLaufAm = ? WHERE job = 'sicherung'")
            ->execute([$stale, $stale]);

        $this->runPlaner();

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $id]));
        $this->assertSame('fehlgeschlagen', $auftrag['status']);
        $this->assertSame('AUFTRAG_ABGEBROCHEN', $auftrag['ergebnis']['fehlerCode'] ?? null);

        $neueId = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $this->assertNotSame($id, $neueId);
    }

    public function testFrischerLaufenderAuftragWirdBeiNeuerAnforderungWiederverwendet(): void
    {
        $this->setupAdmin();

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $fresh = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $this->server->db()->prepare("UPDATE job_auftraege SET status = 'laufend', aktualisiertAm = ? WHERE id = ?")
            ->execute([$fresh, $id]);

        $again = $this->assertOk($this->api->post('job_anfordern', ['job' => 'sicherung']))['id'];
        $this->assertSame($id, $again);
    }

    public function testCronBackupEmailMitProtokollfehlerBleibtBeimFachlichenEmpfaengerFehler(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('save_erinnerung_settings', [
            'backup_email_aktiv' => true,
            'backup_email_empfaenger' => '',
            'backup_email_zyklus' => 'taeglich',
            'backup_email_uhrzeit' => '00:00',
        ]));

        $db = $this->server->db();
        $db->exec('ALTER TABLE job_laeufe RENAME TO job_laeufe_tmp');
        try {
            [$code, $out] = $this->server->runCli('cron_backup_email.php');
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('Keine gueltige Empfaenger-E-Mail', $out);
            $this->assertStringNotContainsString('Fatal error', $out);
        } finally {
            $db->exec('ALTER TABLE job_laeufe_tmp RENAME TO job_laeufe');
        }
    }
}
