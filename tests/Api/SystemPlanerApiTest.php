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

    /** @param array<string,mixed> $overrides */
    private function upsertStatusRow(string $job, array $overrides): void
    {
        $row = array_replace([
            'job' => $job,
            'letzterLaufAm' => '',
            'letzterErfolgAm' => '',
            'letzterFehlerAm' => '',
            'fehlerCode' => '',
            'stoerungSeit' => '',
            'laeuftSeit' => '',
            'tageslaufDatum' => '',
            'tageslaufStatus' => '',
            'versuche' => 0,
            'naechsterVersuchAm' => '',
            'beobachtetSeit' => '',
        ], $overrides);

        $this->server->db()->prepare(
            "INSERT INTO job_status (job, letzterLaufAm, letzterErfolgAm, letzterFehlerAm, fehlerCode, stoerungSeit, laeuftSeit, tageslaufDatum, tageslaufStatus, versuche, naechsterVersuchAm, beobachtetSeit)
             VALUES (:job, :letzterLaufAm, :letzterErfolgAm, :letzterFehlerAm, :fehlerCode, :stoerungSeit, :laeuftSeit, :tageslaufDatum, :tageslaufStatus, :versuche, :naechsterVersuchAm, :beobachtetSeit)
             ON CONFLICT(job) DO UPDATE SET
                letzterLaufAm = excluded.letzterLaufAm,
                letzterErfolgAm = excluded.letzterErfolgAm,
                letzterFehlerAm = excluded.letzterFehlerAm,
                fehlerCode = excluded.fehlerCode,
                stoerungSeit = excluded.stoerungSeit,
                laeuftSeit = excluded.laeuftSeit,
                tageslaufDatum = excluded.tageslaufDatum,
                tageslaufStatus = excluded.tageslaufStatus,
                versuche = excluded.versuche,
                naechsterVersuchAm = excluded.naechsterVersuchAm,
                beobachtetSeit = excluded.beobachtetSeit",
        )->execute($row);
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

    public function testRestoreImportiertKeineJobTabellenUndFixtureV3020UndV3023SindImFixtureTestEnthalten(): void
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
        $this->assertArrayHasKey('v3.0.23.zip', $fixtures);
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


    public function testUeberwachungHinweiseUndTestmailRechteMethodenUndDrosselung(): void
    {
        $this->setupAdmin();
        $normal = $this->createActiveUser('normal-hinweis', 'Normal-Hinweis-1', 'normal');
        $master = $this->createActiveUser('master-hinweis', 'Master-Hinweis-1', 'master');
        $anon = $this->server->client();

        $this->assertContains($anon->get('system_hinweise')->status, [401, 403]);
        $this->assertContains($anon->post('ueberwachung_testmail')->status, [401, 403]);

        $this->assertStatus(403, $normal->get('system_hinweise'));
        $this->assertStatus(403, $normal->post('ueberwachung_testmail'));
        $this->assertStatus(403, $master->get('system_hinweise'));
        $this->assertStatus(403, $master->post('ueberwachung_testmail'));

        $this->assertStatus(405, $this->api->get('ueberwachung_testmail'));

        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => true,
                'empfaenger' => ['eins@example.com', 'zwei@example.com', 'drei@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1024,
            ],
        ]));

        $ersteMail = $this->assertOk($this->api->post('ueberwachung_testmail'));
        $ergebnisse = $ersteMail['ergebnisse'] ?? [];
        $this->assertCount(3, $ergebnisse);
        $this->assertSame('eins@example.com', $ergebnisse[0]['empfaenger'] ?? null);
        $this->assertFalse((bool) ($ergebnisse[0]['ok'] ?? true));
        $this->assertStringContainsString('Mailversand fehlgeschlagen.', (string) ($ergebnisse[0]['fehler'] ?? ''));
        $this->assertStringContainsString('Nicht versucht', (string) ($ergebnisse[1]['fehler'] ?? ''));
        $this->assertStringContainsString('Nicht versucht', (string) ($ergebnisse[2]['fehler'] ?? ''));

        $zuSchnell = $this->assertStatus(429, $this->api->post('ueberwachung_testmail'));
        $this->assertStringContainsString('60 Sekunden', (string) ($zuSchnell['error'] ?? ''));

        $hinweise = $this->assertOk($this->api->get('system_hinweise'));
        $this->assertIsArray($hinweise['hinweise'] ?? null);
    }

    public function testUeberwachungEinstellungenWerdenValidiertUndDuplikateEntfernt(): void
    {
        $this->setupAdmin();
        $baseline = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen'];

        $zuViele = [];
        for ($i = 0; $i < 11; $i++) {
            $zuViele[] = 'ops' . $i . '@example.com';
        }

        foreach ([
            ['aktiv' => true, 'empfaenger' => $zuViele, 'ausbleibenStunden' => 26, 'speicherMinMb' => 1024],
            ['aktiv' => true, 'empfaenger' => ['ungueltig'], 'ausbleibenStunden' => 26, 'speicherMinMb' => 1024],
            ['aktiv' => true, 'empfaenger' => ['ops@example.com'], 'ausbleibenStunden' => 25, 'speicherMinMb' => 1024],
            ['aktiv' => true, 'empfaenger' => ['ops@example.com'], 'ausbleibenStunden' => 169, 'speicherMinMb' => 1024],
            ['aktiv' => true, 'empfaenger' => ['ops@example.com'], 'ausbleibenStunden' => 26, 'speicherMinMb' => 99],
        ] as $invalid) {
            $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', ['ueberwachung' => $invalid]));
        }

        $invalidAktivAbc = $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => 'abc',
                'empfaenger' => ['ops@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1024,
            ],
        ]));
        $this->assertSame('"aktiv" muss ein echter Wahrheitswert sein.', $invalidAktivAbc['error'] ?? null);
        $this->assertStringNotContainsString('abc', (string) ($invalidAktivAbc['error'] ?? ''));

        $invalidArrayAdresse = $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => true,
                'empfaenger' => [['array@example.com']],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1024,
            ],
        ]));
        $this->assertStringContainsString('Text-Adressen', (string) ($invalidArrayAdresse['error'] ?? ''));

        $nachInvalid = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen'];
        $this->assertSame($baseline['ueberwachung'], $nachInvalid['ueberwachung']);

        $antwort = $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => 'false',
                'empfaenger' => ['ops@example.com', 'ops@example.com', 'admin@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1000000,
            ],
        ]));
        $this->assertFalse((bool) ($antwort['ueberwachung']['aktiv'] ?? true));
        $this->assertSame(['ops@example.com', 'admin@example.com'], $antwort['ueberwachung']['empfaenger']);

        $nurSicherung = $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'sicherung' => ['uhrzeit' => '07:45', 'aufbewahrungTage' => 9],
        ]));
        $this->assertSame('07:45', $nurSicherung['sicherung']['uhrzeit'] ?? null);
        $this->assertArrayNotHasKey('ueberwachung', $nurSicherung);

        $gespeichert = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen'];
        $this->assertSame('07:45', $gespeichert['sicherung']['uhrzeit']);
        $this->assertSame(9, $gespeichert['sicherung']['aufbewahrungTage']);
        $this->assertFalse((bool) ($gespeichert['ueberwachung']['aktiv'] ?? true));
        $this->assertSame(['ops@example.com', 'admin@example.com'], $gespeichert['ueberwachung']['empfaenger']);
    }


    public function testUeberwachungEinstellungenAkzeptieren26UndBoolFalse(): void
    {
        $this->setupAdmin();

        $antwort = $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => false,
                'empfaenger' => ['ops@example.com', 'ops@example.com', 'admin@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1000000,
            ],
        ]));
        $this->assertFalse((bool) ($antwort['ueberwachung']['aktiv'] ?? true));
        $this->assertSame(['ops@example.com', 'admin@example.com'], $antwort['ueberwachung']['empfaenger']);

        $zuKurz = $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => false,
                'empfaenger' => ['ops@example.com'],
                'ausbleibenStunden' => 25,
                'speicherMinMb' => 1024,
            ],
        ]));
        $this->assertSame('Ausbleiben-Frist muss eine ganze Zahl zwischen 26 und 168 Stunden sein.', $zuKurz['error'] ?? null);
    }

    public function testSystemHinweiseBlendenAlteNichtZustellbareAusUndNutzenLesbareNamen(): void
    {
        $this->setupAdmin();
        $this->runPlaner();

        $this->assertOk($this->api->post('save_erinnerung_settings', [
            'backup_email_aktiv' => false,
            'backup_email_uhrzeit' => '00:00',
        ]));

        $db = $this->server->db();
        $stoerungSeit = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $alt = (new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:s');
        $neu = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        $this->upsertStatusRow('backupmail', ['stoerungSeit' => $stoerungSeit]);
        $this->upsertStatusRow('probe_wiederherstellung', ['stoerungSeit' => $stoerungSeit]);

        $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES ('alt-zustell', 'sicherung', 'fehler', '{}', ?, '', 3, '')",
        )->execute([$alt]);

        $hinweiseAlt = $this->assertOk($this->api->get('system_hinweise'))['hinweise'];
        $this->assertSame([], array_values(array_filter($hinweiseAlt, static fn(array $hinweis): bool => (string) ($hinweis['art'] ?? '') === 'zustellung')));
        $this->assertSame([], array_values(array_filter($hinweiseAlt, static fn(array $hinweis): bool => (string) ($hinweis['job'] ?? '') === 'backupmail')));
        $texteAlt = implode('\n', array_column($hinweiseAlt, 'text'));
        $this->assertStringContainsString('Wiederherstellungs-Probe', $texteAlt);
        $this->assertStringNotContainsString('probe_wiederherstellung', $texteAlt);

        $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES ('neu-zustell', 'sicherung', 'fehler', '{}', ?, '', 3, '')",
        )->execute([$neu]);

        $hinweiseNeu = $this->assertOk($this->api->get('system_hinweise'))['hinweise'];
        $this->assertNotEmpty(array_values(array_filter($hinweiseNeu, static fn(array $hinweis): bool => (string) ($hinweis['art'] ?? '') === 'zustellung')));
    }

    public function testWatchdogLegtBeiOffenerPlanerStoerungKeinenNeuenTageseintragAn(): void
    {
        $this->setupAdmin();

        $stale = (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s');
        $heute = (new \DateTimeImmutable())->format('Y-m-d');
        $this->upsertStatusRow('planer', [
            'letzterLaufAm' => $stale,
            'stoerungSeit' => $stale,
            'beobachtetSeit' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $this->server->db()->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES (?, 'planer', 'ausbleiben', ?, ?, '', 0, '')",
        )->execute([
            'planer:' . $heute . ':ausbleiben',
            json_encode(['fehlerCode' => '', 'seit' => $stale], JSON_UNESCAPED_UNICODE),
            (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $check = $this->server->client()->get('check');
        $this->assertSame(200, $check->status, $check->describe());
        $this->assertSame(1, (int) $this->server->db()->query("SELECT COUNT(*) FROM meldungen_outbox WHERE job='planer' AND typ='ausbleiben'")->fetchColumn());
    }

    public function testWatchdogErzeugtBeiDeaktivierterUeberwachungWederEintragNochZustellung(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => false,
                'empfaenger' => ['ops@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1024,
            ],
        ]));

        $stale = (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s');
        $this->upsertStatusRow('planer', [
            'letzterLaufAm' => $stale,
            'beobachtetSeit' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $check = $this->server->client()->get('check');
        $this->assertSame(200, $check->status, $check->describe());
        $this->assertSame(0, (int) $this->server->db()->query("SELECT COUNT(*) FROM meldungen_outbox WHERE job='planer' AND typ='ausbleiben'")->fetchColumn());
    }

    public function testProbeStatusIstBeiFrischerInstallationUebersprungenOhneLetztenErfolg(): void
    {
        $this->setupAdmin();
        $this->setSicherung('23:59', 7);

        $this->runPlaner();

        $status = $this->statusRow('probe_wiederherstellung');
        $this->assertSame((new \DateTimeImmutable())->format('Y-m'), $status['tageslaufDatum'] ?? null);
        $this->assertSame('uebersprungen', $status['tageslaufStatus'] ?? null);
        $this->assertSame('', $status['letzterErfolgAm'] ?? null);
    }

    public function testProbeLaeuftZurErstenFaelligkeitWennAeltererGueltigerTagesordnerExistiert(): void
    {
        if ((int) (new \DateTimeImmutable())->format('H') < 4) {
            $this->markTestSkipped('Braucht Uhrzeit ab 04:00 fuer die erste Monatsfaelligkeit.');
        }

        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $this->runPlaner();

        $heute = (new \DateTimeImmutable())->format('Y-m-d');
        $aeltererTag = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d');
        $heuteDir = $this->backupDir($heute);
        $aeltererDir = $this->backupDir($aeltererTag);

        if (is_dir($aeltererDir)) {
            $this->assertTrue(rmdir($aeltererDir));
        }
        $this->assertTrue(rename($heuteDir, $aeltererDir));

        $db = $this->server->db();
        $db->exec("DELETE FROM job_status WHERE job = 'probe_wiederherstellung'");
        $db->exec("DELETE FROM job_laeufe WHERE job = 'probe_wiederherstellung'");

        $this->runPlaner();

        $status = $this->statusRow('probe_wiederherstellung');
        $this->assertSame('ok', $status['tageslaufStatus'] ?? null);
        $this->assertNotSame('', (string) ($status['letzterErfolgAm'] ?? ''));
    }

    public function testWatchdogOhnePlanerHerzschlagErzeugtKeinenAusbleibenAlarm(): void
    {
        $this->setupAdmin();

        $anon = $this->server->client();
        $check = $anon->get('check');
        $this->assertSame(200, $check->status, $check->describe());
        $this->assertSame(0, (int) $this->server->db()->query("SELECT COUNT(*) FROM meldungen_outbox WHERE job='planer' AND typ='ausbleiben'")->fetchColumn());
    }

    public function testSystemJobsStatusUndCheckBleibenOhnePfadLeakUndOhneHaenger(): void
    {
        $this->setupAdmin();
        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => true,
                'empfaenger' => ['ops@example.com'],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1024,
            ],
        ]));

        $stale = (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s');
        $this->upsertStatusRow('planer', [
            'letzterLaufAm' => $stale,
            'beobachtetSeit' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
        ]);

        $anon = $this->server->client();
        $start = microtime(true);
        $checkResponse = $anon->get('check');
        $dauer = microtime(true) - $start;

        $this->assertSame(200, $checkResponse->status, $checkResponse->describe());
        $this->assertLessThan(3.0, $dauer, 'check darf auch nach Watchdog-Ausloesung nicht haengen.');

        $check = $checkResponse->json();
        $expectedKeys = [
            'loggedIn', 'version', 'username', 'role', 'kuerzel', 'permissions', 'visibility',
            'needSetup', 'mustChangePassword', 'isSubunternehmer', 'dienstleisterId',
            'stundenKategorie', 'settings', 'modules', 'license',
        ];
        $actualKeys = array_keys($check);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        $this->assertSame(1, (int) $this->server->db()->query("SELECT COUNT(*) FROM meldungen_outbox WHERE job='planer' AND typ='ausbleiben'")->fetchColumn());
        $this->server->db()->prepare("UPDATE meldungen_outbox SET erstelltAm = ? WHERE job = 'planer' AND typ = 'ausbleiben'")
            ->execute([(new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s')]);

        $this->runPlaner();
        $this->assertSame(1, (int) $this->server->db()->query("SELECT COUNT(*) FROM meldungen_outbox WHERE job='planer' AND typ='entwarnung'")->fetchColumn());

        $statusResponse = $this->api->get('system_jobs_status');
        $status = $this->assertOk($statusResponse);
        $this->assertArrayHasKey('ueberwachung', $status['einstellungen']);
        $this->assertArrayHasKey('probe_wiederherstellung', $status['status']);
        $this->assertArrayHasKey('speicherplatz', $status['status']);
        $this->assertArrayHasKey('empfaengerWirksam', $status);
        $this->assertArrayHasKey('meldungen', $status);

        $body = str_replace('\\', '/', $statusResponse->body);
        $this->assertStringNotContainsString(str_replace('\\', '/', $this->server->dataDir), $body);
        $this->assertStringNotContainsString('database.sqlite', $body);
    }

    public function testProbeWiederherstellungAuftragWirdMitKennzahlenFertigGemeldet(): void
    {
        $this->setupAdmin();
        $this->setSicherung('00:00', 7);
        $this->runPlaner();

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'probe_wiederherstellung']))['id'];
        $this->runPlaner();

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $id]));
        $this->assertSame('fertig', $auftrag['status']);
        $kennzahlen = $auftrag['ergebnis']['ergebnis'] ?? [];
        $this->assertArrayHasKey('sicherungTag', $kennzahlen);
        $this->assertArrayHasKey('tabellen', $kennzahlen);
        $this->assertArrayHasKey('groesseBytes', $kennzahlen);

        $json = (string) json_encode($kennzahlen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('\\', $json);
        $this->assertStringNotContainsString('/var/', $json);
    }

    public function testProbeWiederherstellungOhneTagessicherungMeldetCodeKeineSicherung(): void
    {
        $this->setupAdmin();
        $this->setSicherung('23:59', 7);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->upsertStatusRow('sicherung', [
            'tageslaufDatum' => $today,
            'tageslaufStatus' => 'ok',
        ]);

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'probe_wiederherstellung']))['id'];
        $this->runPlaner();

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $id]));
        $this->assertSame('fehlgeschlagen', $auftrag['status']);
        $this->assertSame('PROBE_KEINE_SICHERUNG', $auftrag['ergebnis']['fehlerCode'] ?? null);
    }

    public function testProbeWiederherstellungMitDefekterDateiMeldetCodeDefekt(): void
    {
        $this->setupAdmin();
        $this->setSicherung('23:59', 7);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->upsertStatusRow('sicherung', [
            'tageslaufDatum' => $today,
            'tageslaufStatus' => 'ok',
        ]);
        $this->createInvalidDailyBackup($today);

        $id = $this->assertOk($this->api->post('job_anfordern', ['job' => 'probe_wiederherstellung']))['id'];
        $this->runPlaner();

        $auftrag = $this->assertOk($this->api->get('job_auftrag_status', ['id' => $id]));
        $this->assertSame('fehlgeschlagen', $auftrag['status']);
        $this->assertSame('PROBE_DEFEKT', $auftrag['ergebnis']['fehlerCode'] ?? null);
    }

    public function testSpeicherplatzMeldetNurBeiZustandswechselUndNachStundeWiederErfolg(): void
    {
        $this->setupAdmin();
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->setSicherung('23:59', 7);
        $this->upsertStatusRow('sicherung', [
            'tageslaufDatum' => $today,
            'tageslaufStatus' => 'ok',
        ]);

        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => true,
                'empfaenger' => [],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 1000000,
            ],
        ]));

        $this->runPlaner();
        $status = $this->statusRow('speicherplatz');
        $this->assertSame('SPEICHER_KNAPP', $status['fehlerCode'] ?? null);
        $laeufeNachErstemLauf = (int) $this->server->db()->query("SELECT COUNT(*) FROM job_laeufe WHERE job='speicherplatz'")->fetchColumn();
        $this->assertSame(1, $laeufeNachErstemLauf);

        $this->runPlaner();
        $laeufeNachZweitemLauf = (int) $this->server->db()->query("SELECT COUNT(*) FROM job_laeufe WHERE job='speicherplatz'")->fetchColumn();
        $this->assertSame($laeufeNachErstemLauf, $laeufeNachZweitemLauf);

        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'ueberwachung' => [
                'aktiv' => true,
                'empfaenger' => [],
                'ausbleibenStunden' => 26,
                'speicherMinMb' => 100,
            ],
        ]));

        $stale = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $this->server->db()->prepare("UPDATE job_status SET letzterLaufAm = ? WHERE job = 'speicherplatz'")->execute([$stale]);

        $this->runPlaner();

        $this->assertSame(2, (int) $this->server->db()->query("SELECT COUNT(*) FROM job_laeufe WHERE job='speicherplatz'")->fetchColumn());
        $this->assertSame('erfolg', $this->server->db()->query("SELECT status FROM job_laeufe WHERE job='speicherplatz' ORDER BY id DESC LIMIT 1")->fetchColumn());
        $this->assertSame('', $this->statusRow('speicherplatz')['fehlerCode'] ?? null);
    }


    public function testNasEinstellungenWerdenValidiertUndPasswortBleibtMaskiert(): void
    {
        $this->setupAdmin();
        $baseline = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen']['nas'];

        $gueltig = [
            'aktiv' => false,
            'host' => 'nas.example.local',
            'port' => 445,
            'freigabe' => 'Freigabe',
            'pfad' => 'baukalkulation',
            'benutzer' => 'nas-user',
            'passwort' => '',
            'domaene' => '',
            'bandbreiteKBs' => 0,
        ];

        $ungueltig = [
            [array_replace($gueltig, ['host' => 'bad host']), 'NAS-Host'],
            [array_replace($gueltig, ['host' => 'bad;host']), 'NAS-Host'],
            [array_replace($gueltig, ['host' => '-leading']), 'NAS-Host'],
            [array_replace($gueltig, ['host' => []]), 'NAS-Host'],
            [array_replace($gueltig, ['freigabe' => 'bad:name']), 'NAS-Freigabename'],
            [array_replace($gueltig, ['freigabe' => 'bad,name']), 'NAS-Freigabename'],
            [array_replace($gueltig, ['freigabe' => '.']), 'NAS-Freigabename'],
            [array_replace($gueltig, ['freigabe' => '..']), 'NAS-Freigabename'],
            [array_replace($gueltig, ['pfad' => '../x']), 'NAS-Pfad'],
            [array_replace($gueltig, ['pfad' => '/leading']), 'NAS-Pfad'],
            [array_replace($gueltig, ['pfad' => "a\nb"]), 'NAS-Pfad'],
            [array_replace($gueltig, ['pfad' => "a\n/b"]), 'NAS-Pfad'],
            [array_replace($gueltig, ['pfad' => 'ordner/../ziel']), 'NAS-Pfad'],
            [array_replace($gueltig, ['benutzer' => []]), 'NAS-Benutzername'],
            [array_replace($gueltig, ['passwort' => []]), 'NAS-Passwort'],
            [array_replace($gueltig, ['port' => 0]), 'NAS-Port muss eine ganze Zahl zwischen 1 und 65535 sein.'],
            [array_replace($gueltig, ['port' => 65536]), 'NAS-Port muss eine ganze Zahl zwischen 1 und 65535 sein.'],
            [array_replace($gueltig, ['bandbreiteKBs' => -1]), 'Bandbreitenbegrenzung muss eine ganze Zahl zwischen 0 und 1.000.000 KB/s sein.'],
        ];

        foreach ($ungueltig as [$payload, $expectedPart]) {
            $resp = $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', ['nas' => $payload]));
            $this->assertStringContainsString($expectedPart, (string) ($resp['error'] ?? ''));
        }

        $aktivOhnePass = $this->assertStatus(400, $this->api->post('system_einstellungen_speichern', ['nas' => array_replace($gueltig, [
            'aktiv' => true,
            'passwort' => '',
        ])]));
        $this->assertStringContainsString('aktiven NAS-Abgleich', (string) ($aktivOhnePass['error'] ?? ''));

        $nachInvalid = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen']['nas'];
        $this->assertSame($baseline, $nachInvalid);

        $mitGetrimmtemPfad = $this->assertOk($this->api->post('system_einstellungen_speichern', ['nas' => array_replace($gueltig, [
            'pfad' => "baukalkulation\n",
        ])]));
        $this->assertSame('baukalkulation', $mitGetrimmtemPfad['nas']['pfad'] ?? null);
        $nachTrim = $this->assertOk($this->api->get('system_jobs_status'))['einstellungen']['nas'];
        $this->assertSame('baukalkulation', $nachTrim['pfad'] ?? null);

        $geheim = 'NAS-TopSecret-123!';
        $gespeichert = $this->assertOk($this->api->post('system_einstellungen_speichern', ['nas' => array_replace($gueltig, [
            'aktiv' => true,
            'passwort' => $geheim,
        ])]));
        $this->assertArrayNotHasKey('passwort', $gespeichert['nas']);
        $this->assertTrue((bool) ($gespeichert['nas']['passwortGesetzt'] ?? false));

        $behalten = $this->assertOk($this->api->post('system_einstellungen_speichern', ['nas' => array_replace($gueltig, [
            'aktiv' => true,
            'passwort' => '',
        ])]));
        $this->assertTrue((bool) ($behalten['nas']['passwortGesetzt'] ?? false));

        $geloescht = $this->assertOk($this->api->post('system_einstellungen_speichern', ['nas' => array_replace($gueltig, [
            'aktiv' => false,
            'passwort' => '',
            'passwortLoeschen' => true,
        ])]));
        $this->assertFalse((bool) ($geloescht['nas']['passwortGesetzt'] ?? true));

        $statusResp = $this->api->get('system_jobs_status');
        $status = $this->assertOk($statusResp);
        $this->assertArrayHasKey('nas', $status['status']);
        $this->assertArrayNotHasKey('passwort', $status['einstellungen']['nas']);

        $body = $statusResp->body . json_encode($gespeichert, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($geheim, $body);
        $this->assertStringNotContainsString('enc:v1:', $body);
    }


    public function testNasUndSecretKeyApiLehnenArrayPasswoerterMit400Ab(): void
    {
        $this->setupAdmin();

        $nasPasswortArray = $this->assertStatus(400, $this->api->post('nas_verbindung_testen', [
            'host' => 'nas.example.local',
            'freigabe' => 'Freigabe',
            'pfad' => 'baukalkulation',
            'benutzer' => 'nas-user',
            'passwort' => [],
        ]));
        $this->assertStringContainsString('NAS-Passwort', (string) ($nasPasswortArray['error'] ?? ''));

        $secretPasswortArray = $this->assertStatus(400, $this->api->post('secret_key_anzeigen', ['passwort' => []]));
        $this->assertStringContainsString('Passwort', (string) ($secretPasswortArray['error'] ?? ''));
    }

    public function testNasUndSecretKeyApiRechteMethodenDrosselungUndAudit(): void
    {
        $this->setupAdmin();
        $normal = $this->createActiveUser('nas-normal', 'Passwort-123', 'normal');
        $master = $this->createActiveUser('nas-master', 'Passwort-123', 'master');
        $anon = $this->server->client();

        $this->assertContains($anon->post('nas_verbindung_testen')->status, [401, 403]);
        $this->assertContains($anon->post('secret_key_anzeigen', ['passwort' => 'x'])->status, [401, 403]);

        $this->assertStatus(403, $normal->post('nas_verbindung_testen'));
        $this->assertStatus(403, $normal->post('secret_key_anzeigen', ['passwort' => 'x']));
        $this->assertStatus(403, $master->post('nas_verbindung_testen'));
        $this->assertStatus(403, $master->post('secret_key_anzeigen', ['passwort' => 'x']));

        $this->assertStatus(405, $this->api->get('nas_verbindung_testen'));
        $this->assertStatus(405, $this->api->get('secret_key_anzeigen'));

        $first = $this->assertStatus(200, $this->api->post('nas_verbindung_testen', [
            'host' => 'nas.example.local',
            'freigabe' => 'Freigabe',
            'pfad' => 'baukalkulation',
            'benutzer' => 'nas-user',
            'passwort' => 'nas-secret',
        ]));
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertArrayHasKey('fehlerCode', $first);

        $this->assertStatus(429, $this->api->post('nas_verbindung_testen', [
            'host' => 'nas.example.local',
            'freigabe' => 'Freigabe',
            'pfad' => 'baukalkulation',
            'benutzer' => 'nas-user',
            'passwort' => 'nas-secret',
        ]));

        for ($i = 1; $i <= 5; $i++) {
            $this->assertStatus(403, $this->api->post('secret_key_anzeigen', ['passwort' => 'falsch-' . $i]));
        }
        $this->assertStatus(429, $this->api->post('secret_key_anzeigen', ['passwort' => 'falsch-6']));

        $secret = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        file_put_contents($this->server->dataPath('secret.key'), $secret);

        $admin2Pass = 'Admin2-Test-123';
        $admin2 = $this->createActiveUser('admin2', $admin2Pass, 'admin');
        $resp = $admin2->post('secret_key_anzeigen', ['passwort' => $admin2Pass]);
        $ok = $this->assertStatus(200, $resp);
        $this->assertTrue((bool) ($ok['ok'] ?? false));
        $this->assertSame(trim($secret), trim((string) ($ok['schluessel'] ?? '')));
        $this->assertSame('no-store', $resp->header('Cache-Control'));

        $auditRows = $this->server->db()->query("SELECT action, details FROM audit_log WHERE action LIKE 'nas_%' OR action LIKE 'secret_key_anzeigen%' ORDER BY id DESC")->fetchAll();
        $auditText = json_encode($auditRows, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('nas-secret', (string) $auditText);
        $this->assertStringNotContainsString('falsch-1', (string) $auditText);
        $this->assertStringNotContainsString($admin2Pass, (string) $auditText);
    }

    public function testJobAnfordernErlaubtNasUndNasCommandUeberspringtBeiLock(): void
    {
        $this->setupAdmin();

        $id1 = $this->assertOk($this->api->post('job_anfordern', ['job' => 'nas']))['id'];
        $id2 = $this->assertOk($this->api->post('job_anfordern', ['job' => 'nas']))['id'];
        $this->assertSame($id1, $id2);

        $runDir = $this->server->dataPath('run');
        if (!is_dir($runDir)) {
            mkdir($runDir, 0777, true);
        }
        $lockPath = $runDir . DIRECTORY_SEPARATOR . 'nas.lock';
        $lock = fopen($lockPath, 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            [$code, $out] = $this->server->runCli('bin/console', ['nas:abgleich']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('sprungen', $out);
    }

    public function testRestoreMitFremdemSecretLeertNasPasswortUndSetztHinweis(): void
    {
        $this->setupAdmin();

        $this->assertOk($this->api->post('system_einstellungen_speichern', [
            'nas' => [
                'aktiv' => true,
                'host' => 'nas.example.local',
                'port' => 445,
                'freigabe' => 'Freigabe',
                'pfad' => 'baukalkulation',
                'benutzer' => 'nas-user',
                'passwort' => 'Import-Geheim-123',
                'domaene' => '',
                'bandbreiteKBs' => 0,
            ],
        ]));

        $download = $this->api->get('backup_download');
        $this->assertSame(200, $download->status, $download->describe());
        $zipBytes = $download->body;

        $this->server->resetData();
        $this->api = $this->server->client();
        $this->setupAdmin();

        $newSecret = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        file_put_contents($this->server->dataPath('secret.key'), $newSecret);

        $zipPath = $this->server->dataPath('restore-nas-secret.zip');
        file_put_contents($zipPath, $zipBytes);
        $this->assertOk($this->api->upload('backup_upload', 'backup', $zipPath));

        $status = $this->assertOk($this->api->get('system_jobs_status'));
        $nas = $status['einstellungen']['nas'] ?? [];
        $this->assertFalse((bool) ($nas['passwortGesetzt'] ?? true));
        $this->assertTrue((bool) ($nas['passwortNeuEingeben'] ?? false));

        $hinweise = $this->assertOk($this->api->get('system_hinweise'))['hinweise'];
        $nasHinweis = array_values(array_filter($hinweise, static fn(array $h): bool => ($h['job'] ?? '') === 'nas'));
        $this->assertNotEmpty($nasHinweis);
        $this->assertStringContainsString('neu eingeben', (string) ($nasHinweis[0]['text'] ?? ''));
    }

}
