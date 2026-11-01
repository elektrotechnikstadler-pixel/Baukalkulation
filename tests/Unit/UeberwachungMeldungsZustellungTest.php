<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jobs\MeldungsZustellung;
use App\Services\Jobs\Ueberwachung;
use PHPUnit\Framework\TestCase;

final class UeberwachungMeldungsZustellungTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = $this->newSqlitePdo('sqlite::memory:');
        $this->createSchema($this->db);
        $this->seedBasisdaten($this->db);
    }

    public function testSicherungFehlgeschlagenErzeugtGenauEineFehlermeldungProTag(): void
    {
        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehlgeschlagen',
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'stoerungSeit' => '2026-11-01 02:00:00',
            'beobachtetSeit' => '2026-11-01 03:00:00',
        ]);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 09:00:00'));
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 10:00:00'));

        $this->assertSame(1, $this->outboxCount($this->db, 'sicherung', 'fehler'));

        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-02',
            'tageslaufStatus' => 'fehlgeschlagen',
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'stoerungSeit' => '2026-11-02 02:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-02 09:00:00'));

        $this->assertSame(
            ['sicherung:2026-11-01:fehler', 'sicherung:2026-11-02:fehler'],
            array_column($this->outboxRows($this->db, 'sicherung', 'fehler'), 'schluessel'),
        );
    }

    public function testEinzelnerFehlversuchMitSpaeteremErfolgBleibtOhneMailUndLeertStoerungSeit(): void
    {
        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehler',
            'letzterFehlerAm' => '2026-11-01 03:00:00',
            'stoerungSeit' => '2026-11-01 03:00:00',
            'beobachtetSeit' => '2026-11-01 03:00:00',
        ]);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 03:15:00'));

        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'ok',
            'letzterErfolgAm' => '2026-11-01 05:00:00',
            'stoerungSeit' => '2026-11-01 03:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 05:15:00'));

        $this->assertSame(0, $this->outboxCount($this->db, 'sicherung'));
        $this->assertSame('', $this->statusRow($this->db, 'sicherung')['stoerungSeit']);
    }

    public function testGemeldeteStoerungWirdNachErfolgGenauEinmalEntwarnt(): void
    {
        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehlgeschlagen',
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'letzterErfolgAm' => '2026-11-01 06:00:00',
            'stoerungSeit' => '2026-11-01 03:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        $this->insertOutbox($this->db, 'sicherung:2026-11-01:fehler', 'sicherung', 'fehler', [
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'seit' => '2026-11-01 03:00:00',
        ], '2026-11-01 04:00:00');

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 06:05:00'));
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 06:10:00'));

        $this->assertSame(1, $this->outboxCount($this->db, 'sicherung', 'entwarnung'));
        $this->assertSame('', $this->statusRow($this->db, 'sicherung')['stoerungSeit']);
    }

    public function testAusbleibenBeachtetSchonfristUndMeldetBeiOffenerStoerungNichtNochmal(): void
    {
        $this->insertStatus($this->db, 'sicherung', [
            'beobachtetSeit' => '',
            'letzterErfolgAm' => '',
            'stoerungSeit' => '',
        ]);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 12:00:00'));
        $this->assertSame(0, $this->outboxCount($this->db, 'sicherung', 'ausbleiben'));
        $this->assertSame('2026-11-01 12:00:00', $this->statusRow($this->db, 'sicherung')['beobachtetSeit']);

        $this->insertStatus($this->db, 'sicherung', [
            'beobachtetSeit' => '2026-10-30 00:00:00',
            'letzterErfolgAm' => '',
            'stoerungSeit' => '',
        ]);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 12:10:00'));
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 12:20:00'));
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-02 12:20:00'));

        $this->assertSame(1, $this->outboxCount($this->db, 'sicherung', 'ausbleiben'));
    }

    public function testBackupmailMeldetNurWennAktivUndStoerungSeitMindestensSechzigMinuten(): void
    {
        $this->setErinnerung(['backup_email_aktiv' => false, 'backup_email_zyklus' => 'taeglich']);
        $this->insertStatus($this->db, 'backupmail', [
            'fehlerCode' => 'BACKUPMAIL_VERSAND',
            'letzterFehlerAm' => '2026-11-01 10:00:00',
            'letzterErfolgAm' => '2026-10-31 10:00:00',
            'stoerungSeit' => '2026-11-01 09:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 10:30:00'));
        $this->assertSame(0, $this->outboxCount($this->db, 'backupmail', 'fehler'));

        $this->setErinnerung(['backup_email_aktiv' => true, 'backup_email_zyklus' => 'taeglich']);
        $this->insertStatus($this->db, 'backupmail', [
            'fehlerCode' => 'BACKUPMAIL_VERSAND',
            'letzterFehlerAm' => '2026-11-01 10:00:00',
            'letzterErfolgAm' => '2026-10-31 10:00:00',
            'stoerungSeit' => '2026-11-01 09:31:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 10:30:00'));
        $this->assertSame(0, $this->outboxCount($this->db, 'backupmail', 'fehler'));

        $this->insertStatus($this->db, 'backupmail', [
            'fehlerCode' => 'BACKUPMAIL_VERSAND',
            'letzterFehlerAm' => '2026-11-01 10:00:00',
            'letzterErfolgAm' => '2026-10-31 10:00:00',
            'stoerungSeit' => '2026-11-01 09:29:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);
        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 10:30:00'));

        $this->assertSame(1, $this->outboxCount($this->db, 'backupmail', 'fehler'));
    }

    public function testUeberwachungDeaktiviertErzeugtKeineOutboxEintraege(): void
    {
        $this->setUeberwachung(['aktiv' => false]);
        $this->insertStatus($this->db, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehlgeschlagen',
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'stoerungSeit' => '2026-11-01 02:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);

        Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 09:00:00'));

        $this->assertSame(0, $this->outboxCount($this->db));
    }

    public function testZustellungSetztBackoffUndIgnoriertEintraegeAelterAlsSiebenTage(): void
    {
        $this->setUeberwachung(['empfaenger' => ['ops@example.com']]);
        $this->insertOutbox($this->db, 'neu', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-11-01 10:00:00'], '2026-11-01 11:00:00');
        $this->insertOutbox($this->db, 'alt', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-10-20 10:00:00'], '2026-10-20 11:00:00');

        $sender = static function (): void {
            throw new \RuntimeException('SMTP down');
        };
        $zustellung = new MeldungsZustellung($sender);

        $zustellung->zustellen($this->db, new \DateTimeImmutable('2026-11-01 11:05:00'));
        $neu = $this->outboxByKey($this->db, 'neu');
        $alt = $this->outboxByKey($this->db, 'alt');
        $this->assertSame(1, (int) $neu['versuche']);
        $this->assertSame('2026-11-01 11:10:00', $neu['naechsterVersuchAm']);
        $this->assertSame(0, (int) $alt['versuche']);
        $this->assertSame('', $alt['naechsterVersuchAm']);

        $zustellung->zustellen($this->db, new \DateTimeImmutable('2026-11-01 11:10:00'));
        $neu = $this->outboxByKey($this->db, 'neu');
        $this->assertSame(2, (int) $neu['versuche']);
        $this->assertSame('2026-11-01 11:40:00', $neu['naechsterVersuchAm']);
    }

    public function testZustellungMarkiertBeiMindestensEinemEmpfaengerAlsGesendetUndEscapedHtml(): void
    {
        $this->setUeberwachung(['empfaenger' => ['ok@example.com', 'fail@example.com']]);
        $this->setSettings(['firma_name' => 'Muster <script>alert(1)</script>']);
        $this->insertOutbox($this->db, 'meldung-1', 'sicherung', 'fehler', [
            'fehlerCode' => 'SICHERUNG_GESPERRT',
            'seit' => '<script>alert(1)</script>',
        ], '2026-11-01 10:05:00');

        $gesendet = [];
        $sender = static function (array $cfg, string $an, string $betreff, string $html) use (&$gesendet): void {
            $gesendet[] = ['cfg' => $cfg, 'an' => $an, 'betreff' => $betreff, 'html' => $html];
            if ($an === 'fail@example.com') {
                throw new \RuntimeException('kaputt');
            }
        };

        $anzahl = (new MeldungsZustellung($sender))->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:10:00'), 20, 12);
        $this->assertSame(1, $anzahl);

        $row = $this->outboxByKey($this->db, 'meldung-1');
        $this->assertSame('2026-11-01 10:10:00', $row['gesendetAm']);
        $this->assertCount(2, $gesendet);
        $this->assertSame('ok@example.com', $gesendet[0]['an']);
        $this->assertSame('fail@example.com', $gesendet[1]['an']);
        $this->assertSame(12, (int) $gesendet[0]['cfg']['smtp_timeout']);

        $html = (string) $gesendet[0]['html'];
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('C:\\', $html);
        $this->assertStringNotContainsString('/var/', $html);
        $this->assertStringNotContainsString('secret.key', $html);
        $this->assertStringNotContainsString('Passwort', $html);
    }

    public function testLeereEmpfaengerlisteFaelltAufAktiveAdminsMitEmailZurueck(): void
    {
        $this->setUeberwachung(['empfaenger' => []]);
        $this->db->exec("INSERT INTO users (role, isLocked, email) VALUES ('admin', 0, 'a@example.com')");
        $this->db->exec("INSERT INTO users (role, isLocked, email) VALUES ('admin', 1, 'locked@example.com')");
        $this->db->exec("INSERT INTO users (role, isLocked, email) VALUES ('admin', 0, '')");
        $this->db->exec("INSERT INTO users (role, isLocked, email) VALUES ('normal', 0, 'normal@example.com')");

        $liste = MeldungsZustellung::empfaengerWirksam($this->db);
        sort($liste);

        $this->assertSame(['a@example.com'], $liste);
    }

    public function testDoppelmailSchutzMitZweiPdoVerbindungenErzeugtNurEinenOutboxEintrag(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bk-ueb-');
        $this->assertIsString($tmp);
        $pdoA = $this->newSqlitePdo('sqlite:' . $tmp);
        $pdoB = $this->newSqlitePdo('sqlite:' . $tmp);

        $this->createSchema($pdoA);
        $this->seedBasisdaten($pdoA);
        $this->insertStatus($pdoA, 'sicherung', [
            'tageslaufDatum' => '2026-11-01',
            'tageslaufStatus' => 'fehlgeschlagen',
            'fehlerCode' => 'SICHERUNG_FEHLER',
            'stoerungSeit' => '2026-11-01 02:00:00',
            'beobachtetSeit' => '2026-10-31 00:00:00',
        ]);

        Ueberwachung::pruefen($pdoA, new \DateTimeImmutable('2026-11-01 09:00:00'));
        Ueberwachung::pruefen($pdoB, new \DateTimeImmutable('2026-11-01 09:00:00'));

        $this->assertSame(1, $this->outboxCount($pdoA, 'sicherung', 'fehler'));

        $pdoA = null;
        $pdoB = null;
        gc_collect_cycles();
        @unlink($tmp);
    }


    public function testZustelllaufBrichtNachErstemEmpfaengerFehlerAbUndSetztBackoff(): void
    {
        $this->setUeberwachung(['empfaenger' => ['alpha@example.com', 'beta@example.com']]);
        $this->insertOutbox($this->db, 'stop-1', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-11-01 09:00:00'], '2026-11-01 10:00:00');
        $this->insertOutbox($this->db, 'stop-2', 'backupmail', 'fehler', ['fehlerCode' => 'BACKUPMAIL_VERSAND', 'seit' => '2026-11-01 09:10:00'], '2026-11-01 10:01:00');

        $versuche = [];
        $sender = static function (array $cfg, string $an, string $betreff, string $html) use (&$versuche): void {
            $versuche[] = $an;
            throw new \RuntimeException('SMTP down');
        };

        (new MeldungsZustellung($sender))->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:05:00'));

        $this->assertSame(['alpha@example.com'], $versuche);

        $erst = $this->outboxByKey($this->db, 'stop-1');
        $zweit = $this->outboxByKey($this->db, 'stop-2');
        $this->assertSame(1, (int) $erst['versuche']);
        $this->assertSame('2026-11-01 10:10:00', $erst['naechsterVersuchAm']);
        $this->assertSame(0, (int) $zweit['versuche']);
        $this->assertSame('', $zweit['naechsterVersuchAm']);
    }

    public function testEmpfaengerRotationBeginntBeimNaechstenVersuchMitAnderemEmpfaenger(): void
    {
        $this->setUeberwachung(['empfaenger' => ['fail@example.com', 'ok@example.com']]);
        $this->insertOutbox($this->db, 'rotation-1', 'nas', 'fehler', ['fehlerCode' => 'NAS_TRANSFER', 'seit' => '2026-11-01 09:00:00'], '2026-11-01 10:00:00');

        $versendet = [];
        $sender = static function (array $cfg, string $an, string $betreff, string $html) use (&$versendet): void {
            $versendet[] = $an;
            if ($an === 'fail@example.com') {
                throw new \RuntimeException('SMTP 550');
            }
        };
        $zustellung = new MeldungsZustellung($sender);

        $zustellung->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:00:00'));
        $erst = $this->outboxByKey($this->db, 'rotation-1');
        $this->assertSame(1, (int) $erst['versuche']);
        $this->assertSame('', $erst['gesendetAm']);

        $zustellung->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:05:00'));
        $zweit = $this->outboxByKey($this->db, 'rotation-1');

        $this->assertSame(['fail@example.com', 'ok@example.com', 'fail@example.com'], $versendet);
        $this->assertSame('2026-11-01 10:05:00', $zweit['gesendetAm']);
    }

    public function testAusbleibenWirdBeiAktuellemLaufendOderFehlerStatusNichtGemeldet(): void
    {
        foreach (['laufend', 'fehler'] as $status) {
            $this->db->exec('DELETE FROM meldungen_outbox');
            $this->insertStatus($this->db, 'sicherung', [
                'tageslaufDatum' => '2026-11-01',
                'tageslaufStatus' => $status,
                'versuche' => 1,
                'letzterErfolgAm' => '2026-10-29 01:00:00',
                'beobachtetSeit' => '2026-10-29 01:00:00',
                'stoerungSeit' => '',
            ]);

            Ueberwachung::pruefen($this->db, new \DateTimeImmutable('2026-11-01 12:00:00'));
            $this->assertSame(0, $this->outboxCount($this->db, 'sicherung', 'ausbleiben'), $status);
        }
    }

    public function testMailInhaltZeigtGueltigenFehlercodeUndUnterdruecktUngueltigenCode(): void
    {
        $this->setUeberwachung(['empfaenger' => ['ops@example.com']]);
        $this->insertOutbox($this->db, 'code-valid', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-11-01 09:00:00'], '2026-11-01 10:00:00');
        $this->insertOutbox($this->db, 'code-invalid', 'sicherung', 'fehler', ['fehlerCode' => 'bad-code', 'seit' => '2026-11-01 09:10:00'], '2026-11-01 10:01:00');

        $mails = [];
        $sender = static function (array $cfg, string $an, string $betreff, string $html) use (&$mails): void {
            $mails[] = ['an' => $an, 'html' => $html];
        };

        (new MeldungsZustellung($sender))->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:05:00'));

        $this->assertCount(2, $mails);
        $html = implode('\n---\n', array_column($mails, 'html'));
        $this->assertStringContainsString('Fehlercode: SICHERUNG_FEHLER', $html);
        $this->assertStringNotContainsString('Fehlercode: bad-code', $html);
    }

    public function testZustellungRaeumtUngesendeteEintraegeAelterAlsNeunzigTageAuf(): void
    {
        $this->setUeberwachung(['empfaenger' => ['ops@example.com']]);
        $this->insertOutbox($this->db, 'sehr-alt', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-07-01 00:00:00'], '2026-07-01 00:00:00');
        $this->insertOutbox($this->db, 'frisch', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-11-01 09:00:00'], '2026-11-01 09:00:00');

        $sender = static function (array $cfg, string $an, string $betreff, string $html): void {};
        (new MeldungsZustellung($sender))->zustellen($this->db, new \DateTimeImmutable('2026-11-01 10:00:00'));

        $this->assertSame([], $this->outboxByKey($this->db, 'sehr-alt'));
        $this->assertSame('2026-11-01 10:00:00', $this->outboxByKey($this->db, 'frisch')['gesendetAm']);
    }

    public function testDoppelzustellungMitZweiInstanzenUndPdoVerbindungenSendetNurEinmal(): void
    {
        $tmp = __DIR__ . DIRECTORY_SEPARATOR . 'tmp-doppelzustellung-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdoA = $this->newSqlitePdo('sqlite:' . $tmp);
        $pdoB = $this->newSqlitePdo('sqlite:' . $tmp);

        try {
            $this->createSchema($pdoA);
            $this->seedBasisdaten($pdoA);
            $this->setUeberwachung(['empfaenger' => ['ops@example.com']], $pdoA);
            $this->insertOutbox($pdoA, 'zustellung-1', 'sicherung', 'fehler', ['fehlerCode' => 'SICHERUNG_FEHLER', 'seit' => '2026-11-01 09:00:00'], '2026-11-01 10:00:00');

            $aufA = 0;
            $aufB = 0;
            $senderA = static function (array $cfg, string $an, string $betreff, string $html) use (&$aufA): void {
                $aufA++;
            };
            $senderB = static function (array $cfg, string $an, string $betreff, string $html) use (&$aufB): void {
                $aufB++;
            };

            (new MeldungsZustellung($senderA))->zustellen($pdoA, new \DateTimeImmutable('2026-11-01 10:05:00'));
            (new MeldungsZustellung($senderB))->zustellen($pdoB, new \DateTimeImmutable('2026-11-01 10:05:00'));

            $this->assertSame(1, $aufA + $aufB);
            $this->assertSame('2026-11-01 10:05:00', $this->outboxByKey($pdoA, 'zustellung-1')['gesendetAm']);
        } finally {
            $pdoA = null;
            $pdoB = null;
            gc_collect_cycles();
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testEmpfaengerWirksamBeruecksichtigtNullLockFallsSchemaEsZulaesst(): void
    {
        $db = $this->newSqlitePdo('sqlite::memory:');
        $db->exec("CREATE TABLE system_einstellungen (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL DEFAULT '', isLocked INTEGER NULL, email TEXT NOT NULL DEFAULT '')");
        $db->prepare('INSERT INTO system_einstellungen (id, data) VALUES (1, ?)')
            ->execute([json_encode(['ueberwachung' => ['empfaenger' => []]], JSON_UNESCAPED_UNICODE)]);
        $db->exec("INSERT INTO users (role, isLocked, email) VALUES ('admin', NULL, 'null@example.com')");
        $db->exec("INSERT INTO users (role, isLocked, email) VALUES ('admin', 1, 'locked@example.com')");

        $liste = MeldungsZustellung::empfaengerWirksam($db);

        $this->assertSame(['null@example.com'], $liste);
    }

    private function newSqlitePdo(string $dsn): \PDO
    {
        return new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS system_einstellungen (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS erinnerung_settings (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, data TEXT NOT NULL DEFAULT '{}')");
        $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL DEFAULT '', isLocked INTEGER NOT NULL DEFAULT 0, email TEXT NOT NULL DEFAULT '')");
        $db->exec("
            CREATE TABLE IF NOT EXISTS job_status (
                job TEXT NOT NULL PRIMARY KEY,
                letzterLaufAm TEXT NOT NULL DEFAULT '',
                letzterErfolgAm TEXT NOT NULL DEFAULT '',
                letzterFehlerAm TEXT NOT NULL DEFAULT '',
                fehlerCode TEXT NOT NULL DEFAULT '',
                stoerungSeit TEXT NOT NULL DEFAULT '',
                laeuftSeit TEXT NOT NULL DEFAULT '',
                tageslaufDatum TEXT NOT NULL DEFAULT '',
                tageslaufStatus TEXT NOT NULL DEFAULT '',
                versuche INTEGER NOT NULL DEFAULT 0,
                naechsterVersuchAm TEXT NOT NULL DEFAULT '',
                beobachtetSeit TEXT NOT NULL DEFAULT ''
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS meldungen_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                schluessel TEXT NOT NULL DEFAULT '' UNIQUE,
                job TEXT NOT NULL DEFAULT '',
                typ TEXT NOT NULL DEFAULT '',
                daten TEXT NOT NULL DEFAULT '{}',
                erstelltAm TEXT NOT NULL DEFAULT '',
                gesendetAm TEXT NOT NULL DEFAULT '',
                versuche INTEGER NOT NULL DEFAULT 0,
                naechsterVersuchAm TEXT NOT NULL DEFAULT ''
            )
        ");
    }

    private function seedBasisdaten(\PDO $db): void
    {
        $db->exec("INSERT INTO system_einstellungen (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
        $db->exec("INSERT INTO erinnerung_settings (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
        $db->exec("INSERT INTO settings (id, data) VALUES (1, '{}') ON CONFLICT(id) DO NOTHING");
    }

    /** @param array<string,mixed> $werte */
    private function setUeberwachung(array $werte, ?\PDO $db = null): void
    {
        $db ??= $this->db;
        $cfg = array_replace([
            'aktiv' => true,
            'empfaenger' => [],
            'ausbleibenStunden' => 26,
            'speicherMinMb' => 1024,
        ], $werte);
        $data = [
            'sicherung' => ['uhrzeit' => '02:00', 'aufbewahrungTage' => 7],
            'ueberwachung' => $cfg,
        ];
        $stmt = $db->prepare(
            "INSERT INTO system_einstellungen (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data",
        );
        $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $werte */
    private function setErinnerung(array $werte): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO erinnerung_settings (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data",
        );
        $stmt->execute([json_encode($werte, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $werte */
    private function setSettings(array $werte): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO settings (id, data) VALUES (1, ?)
             ON CONFLICT(id) DO UPDATE SET data = excluded.data",
        );
        $stmt->execute([json_encode($werte, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $overrides */
    private function insertStatus(\PDO $db, string $job, array $overrides): void
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

        $stmt = $db->prepare(
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
        );
        $stmt->execute($row);
    }

    /** @param array<string,mixed> $daten */
    private function insertOutbox(\PDO $db, string $schluessel, string $job, string $typ, array $daten, string $erstelltAm): void
    {
        $stmt = $db->prepare(
            "INSERT INTO meldungen_outbox (schluessel, job, typ, daten, erstelltAm, gesendetAm, versuche, naechsterVersuchAm)
             VALUES (?, ?, ?, ?, ?, '', 0, '')",
        );
        $stmt->execute([$schluessel, $job, $typ, json_encode($daten, JSON_UNESCAPED_UNICODE), $erstelltAm]);
    }

    /** @return array<string,mixed> */
    private function statusRow(\PDO $db, string $job): array
    {
        $stmt = $db->prepare('SELECT * FROM job_status WHERE job = ?');
        $stmt->execute([$job]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }

    private function outboxCount(\PDO $db, ?string $job = null, ?string $typ = null): int
    {
        $where = [];
        $args = [];
        if ($job !== null) {
            $where[] = 'job = ?';
            $args[] = $job;
        }
        if ($typ !== null) {
            $where[] = 'typ = ?';
            $args[] = $typ;
        }
        $sql = 'SELECT COUNT(*) FROM meldungen_outbox';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function outboxRows(\PDO $db, string $job, string $typ): array
    {
        $stmt = $db->prepare('SELECT * FROM meldungen_outbox WHERE job = ? AND typ = ? ORDER BY id ASC');
        $stmt->execute([$job, $typ]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed> */
    private function outboxByKey(\PDO $db, string $key): array
    {
        $stmt = $db->prepare('SELECT * FROM meldungen_outbox WHERE schluessel = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : [];
    }
}
