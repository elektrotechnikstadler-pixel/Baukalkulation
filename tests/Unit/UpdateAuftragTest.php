<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdateAuftrag;
use App\Services\UpdatePruefung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Soll-Tests AP-20261001-update Stufe 2, Schritt 6 (2.5.5, 2.5.8): Update-Anforderung an den Sidecar
 * über das Austauschverzeichnis – ohne Netz (Fake-Abruf), feste Uhr, Temp-Verzeichnis.
 */
final class UpdateAuftragTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/update/github_releases.json';
    private const REPO = 'acme/baukalkulation';
    private const JETZT = '2026-10-01T10:00:00+02:00';

    private string $basis;
    private string $verzeichnis;
    private string $errorLogAlt;
    private int $abrufe = 0;
    /** Wird bei jedem Fake-Abruf ausgeführt (simuliert parallele Vorgänge zwischen Prüfung und Schreiben). */
    private ?\Closure $beimAbruf = null;

    protected function setUp(): void
    {
        $this->basis = sys_get_temp_dir() . '/bk-auftrag-' . bin2hex(random_bytes(6));
        $this->verzeichnis = $this->basis . '/update';
        // Leitstand Stufe 2 R2: `anforderung/` legt der Sidecar an (einziges von der App beschreibbares Verzeichnis).
        mkdir($this->verzeichnis . '/anforderung', 0777, true);
        $this->errorLogAlt = (string) ini_get('error_log');
        ini_set('error_log', $this->basis . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogAlt);
        self::entfernen($this->basis);
    }

    // ── updaterAktiv(): Heartbeat < 120 s ─────────────────────

    public function testFrischerHeartbeatIstAktiv(): void
    {
        $this->heartbeat(-119);

        $this->assertTrue($this->auftrag()->updaterAktiv());
    }

    public function testHeartbeatGenauZweiMinutenIstInaktiv(): void
    {
        $this->heartbeat(-120);

        $this->assertFalse($this->auftrag()->updaterAktiv());
    }

    public function testAlterHeartbeatIstInaktiv(): void
    {
        $this->heartbeat(-3600);

        $this->assertFalse($this->auftrag()->updaterAktiv());
    }

    public static function kaputteHeartbeats(): iterable
    {
        yield 'leer' => [''];
        yield 'kein JSON' => ['{ts: 1'];
        yield 'kein Objekt' => ['42'];
        yield 'ts fehlt' => ['{"updater_version":"1"}'];
        yield 'ts als Text' => ['{"ts":"gestern"}'];
    }

    #[DataProvider('kaputteHeartbeats')]
    public function testKaputterHeartbeatIstInaktiv(string $inhalt): void
    {
        file_put_contents($this->verzeichnis . '/updater.json', $inhalt);

        $this->assertFalse($this->auftrag()->updaterAktiv());
    }

    public function testOhneHeartbeatInaktiv(): void
    {
        $this->assertFalse($this->auftrag()->updaterAktiv());
    }

    public function testHeartbeatSechzigSekundenInDerZukunftNochAktiv(): void
    {
        $this->heartbeat(60);

        $this->assertTrue($this->auftrag()->updaterAktiv());
    }

    public static function zukuenftigeHeartbeats(): iterable
    {
        yield '61 s in der Zukunft' => [61];
        yield '1 h in der Zukunft' => [3600];
        yield '1 Jahr in der Zukunft' => [365 * 86400];
    }

    #[DataProvider('zukuenftigeHeartbeats')]
    public function testHeartbeatWeitInDerZukunftIstInaktiv(int $sekunden): void
    {
        $this->heartbeat($sekunden);

        $this->assertFalse($this->auftrag()->updaterAktiv());
        $this->assertSame(['ok' => false, 'code' => 'updater_inaktiv'], $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1));
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    // ── zustand(): nur bekannte Werte aus updater.json ──────────

    public static function bekannteZustaende(): iterable
    {
        yield 'bereit' => ['bereit'];
        yield 'nicht_konfiguriert' => ['nicht_konfiguriert'];
        yield 'nicht_unterstuetzt' => ['nicht_unterstuetzt'];
    }

    #[DataProvider('bekannteZustaende')]
    public function testZustandWirdGeliefert(string $zustand): void
    {
        $this->heartbeat(-5, ['zustand' => $zustand]);

        $this->assertSame($zustand, $this->auftrag()->zustand());
    }

    public static function unbekannteZustaende(): iterable
    {
        yield 'unbekannter Text' => [['zustand' => 'kaputt']];
        yield 'Großschreibung' => [['zustand' => 'BEREIT']];
        yield 'mit Leerzeichen' => [['zustand' => 'bereit ']];
        yield 'leer' => [['zustand' => '']];
        yield 'null' => [['zustand' => null]];
        yield 'Zahl' => [['zustand' => 1]];
        yield 'Liste' => [['zustand' => ['bereit']]];
        yield 'fehlt' => [[]];
    }

    #[DataProvider('unbekannteZustaende')]
    public function testUnbekannterZustandIstNull(array $zusatz): void
    {
        $this->heartbeat(-5, $zusatz);

        $this->assertNull($this->auftrag()->zustand());
    }

    public function testZustandOhneUpdaterDateiNull(): void
    {
        $this->assertNull($this->auftrag()->zustand());
    }

    public function testZustandBeiKaputterUpdaterDateiNull(): void
    {
        file_put_contents($this->verzeichnis . '/updater.json', '{"zustand": "bereit"');

        $this->assertNull($this->auftrag()->zustand());
    }

    public function testFehlendesVerzeichnisIstInaktivUndWartet(): void
    {
        $auftrag = $this->auftrag(verzeichnis: $this->basis . '/gibt-es-nicht');

        $this->assertFalse($auftrag->updaterAktiv());
        $this->assertSame('wartet', $auftrag->status()['phase']);
    }

    // ── status(): status.json tolerant lesen ─────────────────

    public function testOhneStatusDateiWartet(): void
    {
        $this->assertSame('wartet', $this->auftrag()->status()['phase']);
    }

    public static function kaputteStatusDateien(): iterable
    {
        yield 'leer' => [''];
        yield 'kein JSON' => ['{"phase": "lade"'];
        yield 'kein Objekt' => ['"lade"'];
        yield 'phase fehlt' => ['{"id":"0123456789abcdef"}'];
        yield 'phase kein Text' => ['{"phase":["lade"]}'];
    }

    #[DataProvider('kaputteStatusDateien')]
    public function testKaputteStatusDateiWartet(string $inhalt): void
    {
        file_put_contents($this->verzeichnis . '/status.json', $inhalt);

        $this->assertSame('wartet', $this->auftrag()->status()['phase']);
    }

    public function testStatusDateiWirdGeliefert(): void
    {
        $status = [
            'id'           => '0123456789abcdef',
            'phase'        => 'lade',
            'version_alt'  => '3.0.1',
            'version_ziel' => '3.1.0',
            'ergebnis'     => null,
            'meldung'      => 'Image wird geladen.',
            'ts'           => 1790000000,
        ];
        file_put_contents($this->verzeichnis . '/status.json', json_encode($status));

        $geliefert = $this->auftrag()->status();

        foreach ($status as $schluessel => $wert) {
            $this->assertArrayHasKey($schluessel, $geliefert);
            $this->assertSame($wert, $geliefert[$schluessel], $schluessel);
        }
    }

    // ── anfordern(): Gutfall ─────────────────────────────────

    public static function zulaessigeZiele(): iterable
    {
        yield 'neueste' => ['3.1.0'];
        yield 'neuer, aber nicht neueste' => ['3.0.10'];
    }

    #[DataProvider('zulaessigeZiele')]
    public function testAnforderungWirdGeschrieben(string $ziel): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(aktuell: '3.0.9')->anfordern($ziel, 7);

        $this->assertSame(['ok', 'id'], array_keys($antwort));
        $this->assertTrue($antwort['ok']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $antwort['id']);

        $anforderung = json_decode((string) file_get_contents($this->anforderungsDatei()), true);
        $this->assertSame([
            'id'           => $antwort['id'],
            'version'      => $ziel,
            'user_id'      => 7,
            'requested_at' => (new \DateTimeImmutable(self::JETZT))->format('c'),
        ], $anforderung);
    }

    public function testAnforderungHinterlaesstKeineTemporaerdateien(): void
    {
        $this->heartbeat(-5);

        $this->assertTrue($this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1)['ok']);

        $this->assertSame(['anforderung', 'updater.json'], self::inhalt($this->verzeichnis), 'App schreibt nichts in das Sidecar-Verzeichnis');
        $this->assertSame(['request.json'], self::inhalt($this->verzeichnis . '/anforderung'));
    }

    public function testAnforderungNurImAnforderungsverzeichnis(): void
    {
        $this->heartbeat(-5);

        $this->assertTrue($this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1)['ok']);

        $this->assertFileExists($this->anforderungsDatei());
        $this->assertFileDoesNotExist($this->verzeichnis . '/request.json', 'Alter Ort ist Sidecar-Eigentum');
    }

    public function testAnforderungRuftReleaseListeMitForceAb(): void
    {
        $this->heartbeat(-5);
        $pruefung = $this->pruefung(aktuell: '3.0.1');
        $pruefung->verfuegbareVersionen();
        $pruefung->verfuegbareVersionen();
        $this->assertSame(1, $this->abrufe, 'Vorbedingung: Erfolgs-Cache ist frisch');

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertTrue($antwort['ok']);
        $this->assertSame(2, $this->abrufe, 'update_start muss die Release-Liste trotz frischem Cache neu abrufen');
    }

    public function testIdIstJeAnforderungNeu(): void
    {
        $this->heartbeat(-5);
        $erste = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);
        unlink($this->anforderungsDatei());

        $zweite = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertTrue($zweite['ok']);
        $this->assertNotSame($erste['id'], $zweite['id']);
    }

    // ── anfordern(): Fehlercodes, nie eine Anforderungsdatei ─

    public function testOhneRepoNichtKonfiguriertOhneAbruf(): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(repo: null, aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'nicht_konfiguriert'], $antwort);
        $this->assertSame(0, $this->abrufe, 'Ohne Repo darf nicht abgerufen werden');
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testUngueltigesRepoNichtKonfiguriert(): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(repo: 'a/..', aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'nicht_konfiguriert'], $antwort);
        $this->assertSame(0, $this->abrufe);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testOhneRepoVorUpdaterInaktiv(): void
    {
        $antwort = $this->auftrag(repo: null, aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'nicht_konfiguriert'], $antwort);
    }

    public static function inaktiveHeartbeats(): iterable
    {
        yield 'kein Heartbeat' => [null];
        yield 'Heartbeat 2 min alt' => [-120];
        yield 'Heartbeat 1 h alt' => [-3600];
    }

    #[DataProvider('inaktiveHeartbeats')]
    public function testUpdaterInaktivOhneAbruf(?int $alter): void
    {
        if ($alter !== null) {
            $this->heartbeat($alter);
        }

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'updater_inaktiv'], $antwort);
        $this->assertSame(0, $this->abrufe, 'Ohne aktiven Updater kein Abruf (Rate-Limit)');
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testVorhandeneAnforderungLaeuftBereits(): void
    {
        $this->heartbeat(-5);
        $alt = '{"id":"aaaaaaaaaaaaaaaa","version":"3.0.10","user_id":2,"requested_at":"2026-10-01T09:59:00+02:00"}';
        file_put_contents($this->anforderungsDatei(), $alt);

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'laeuft_bereits'], $antwort);
        $this->assertSame($alt, file_get_contents($this->anforderungsDatei()), 'Vorhandene Anforderung bleibt unverändert');
    }

    public function testSperreLaeuftBereits(): void
    {
        $this->heartbeat(-5);
        mkdir($this->verzeichnis . '/lock');

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'laeuft_bereits'], $antwort);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testZweiteAnforderungLaeuftBereits(): void
    {
        $this->heartbeat(-5);
        $auftrag = $this->auftrag(aktuell: '3.0.1');
        $erste = $auftrag->anfordern('3.1.0', 1);
        $inhalt = file_get_contents($this->anforderungsDatei());

        $zweite = $auftrag->anfordern('3.0.10', 2);

        $this->assertTrue($erste['ok']);
        $this->assertSame(['ok' => false, 'code' => 'laeuft_bereits'], $zweite);
        $this->assertSame($inhalt, file_get_contents($this->anforderungsDatei()));
    }

    public function testZweiteAnforderungBehaeltIdDerErsten(): void
    {
        $this->heartbeat(-5);
        $erste = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $zweite = $this->auftrag(aktuell: '3.0.1')->anfordern('3.0.10', 2);

        $this->assertSame(['ok' => false, 'code' => 'laeuft_bereits'], $zweite);
        $gespeichert = json_decode((string) file_get_contents($this->anforderungsDatei()), true);
        $this->assertSame($erste['id'], $gespeichert['id'] ?? null);
        $this->assertSame('3.1.0', $gespeichert['version'] ?? null);
        $this->assertSame(1, $gespeichert['user_id'] ?? null);
    }

    /**
     * Review Stufe 2 R1 (TOCTOU): Ein paralleler Admin legt seine Anforderung zwischen Prüfung und Schreiben ab
     * (simuliert während des Release-Abrufs) – sie darf nicht still überschrieben werden.
     */
    public function testGleichzeitigeAnforderungWirdNichtUeberschrieben(): void
    {
        $this->heartbeat(-5);
        $parallel = '{"id":"bbbbbbbbbbbbbbbb","version":"3.0.10","user_id":2,"requested_at":"2026-10-01T10:00:00+02:00"}';
        $this->beimAbruf = function () use ($parallel): void {
            if (!is_file($this->anforderungsDatei())) {
                file_put_contents($this->anforderungsDatei(), $parallel);
            }
        };

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern('3.1.0', 1);

        $this->assertSame(['ok' => false, 'code' => 'laeuft_bereits'], $antwort);
        $this->assertSame($parallel, file_get_contents($this->anforderungsDatei()), 'Erste Anforderung bleibt unverändert');
        $this->assertSame(['request.json'], self::inhalt($this->verzeichnis . '/anforderung'), 'Keine Temp-Reste');
    }

    public static function ungueltigeVersionen(): iterable
    {
        yield 'leer' => [''];
        yield 'zweiteilig' => ['3.1'];
        yield 'mit v' => ['v3.1.0'];
        yield 'Prerelease-Suffix' => ['3.2.0-rc1'];
        yield 'Zeilenumbruch am Ende' => ["3.1.0\n"];
        yield 'Leerzeichen' => [' 3.1.0'];
        yield 'Pfad' => ['../3.1.0'];
        yield 'Befehl' => ['3.1.0; rm -rf /'];
        yield 'Tag-Name' => ['nightly'];
        yield 'nicht in Liste' => ['3.0.5'];
        yield 'Draft' => ['9.0.0'];
        yield 'Prerelease mit SemVer-Tag' => ['4.0.0'];
    }

    #[DataProvider('ungueltigeVersionen')]
    public function testUngueltigeVersion(string $version): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(aktuell: '3.0.1')->anfordern($version, 1);

        $this->assertSame(['ok' => false, 'code' => 'version_ungueltig'], $antwort);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public static function keineNeuerenVersionen(): iterable
    {
        yield 'gleich' => ['3.0.10', '3.0.10'];
        yield 'älter' => ['3.0.10', '3.0.9'];
        yield 'deutlich älter' => ['3.1.0', '2.10.99'];
    }

    #[DataProvider('keineNeuerenVersionen')]
    public function testKeinUpdate(string $aktuell, string $ziel): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(aktuell: $aktuell)->anfordern($ziel, 1);

        $this->assertSame(['ok' => false, 'code' => 'kein_update'], $antwort);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    public function testAbrufFehlerErgibtVersionUngueltig(): void
    {
        $this->heartbeat(-5);

        $antwort = $this->auftrag(aktuell: '3.0.1', antwort: null)->anfordern('3.1.0', 1);

        $this->assertFalse($antwort['ok']);
        $this->assertSame('version_ungueltig', $antwort['code']);
        $this->assertFileDoesNotExist($this->anforderungsDatei());
    }

    // ── Hilfen ───────────────────────────────────────────────

    private function auftrag(
        ?string $repo = self::REPO,
        string $aktuell = '3.0.1',
        ?string $antwort = 'fixture',
        ?string $verzeichnis = null,
    ): UpdateAuftrag {
        $jetzt = new \DateTimeImmutable(self::JETZT);
        $pruefung = $this->pruefung($repo, $aktuell, $antwort);
        return new UpdateAuftrag($verzeichnis ?? $this->verzeichnis, $pruefung, static fn(): \DateTimeImmutable => $jetzt);
    }

    /** Prüfung mit Fake-Abruf (zählt Aufrufe) und gemeinsamer Cache-Datei. */
    private function pruefung(?string $repo = self::REPO, string $aktuell = '3.0.1', ?string $antwort = 'fixture'): UpdatePruefung
    {
        $body = $antwort === 'fixture' ? (string) file_get_contents(self::FIXTURE) : $antwort;
        return new UpdatePruefung(
            $repo,
            $this->basis . '/update_check.json',
            function (string $url) use ($body): ?string {
                $this->abrufe++;
                if ($this->beimAbruf !== null) {
                    ($this->beimAbruf)();
                }
                return $body;
            },
            new \DateTimeImmutable(self::JETZT),
            $aktuell,
        );
    }

    /**
     * Heartbeat des Sidecars, `ts` als Unix-Zeit relativ zu JETZT.
     *
     * @param array<string, mixed> $zusatz
     */
    private function heartbeat(int $sekunden, array $zusatz = []): void
    {
        $ts = (new \DateTimeImmutable(self::JETZT))->getTimestamp() + $sekunden;
        file_put_contents(
            $this->verzeichnis . '/updater.json',
            json_encode(['ts' => $ts, 'updater_version' => '1', 'dry_run' => false] + $zusatz),
        );
    }

    private function anforderungsDatei(): string
    {
        return $this->verzeichnis . '/anforderung/request.json';
    }

    /** @return list<string> sortierte Einträge ohne `.`/`..` */
    private static function inhalt(string $verzeichnis): array
    {
        $eintraege = array_values(array_diff(scandir($verzeichnis) ?: [], ['.', '..']));
        sort($eintraege);
        return $eintraege;
    }

    private static function entfernen(string $pfad): void
    {
        if (!is_dir($pfad)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($pfad);
    }
}
