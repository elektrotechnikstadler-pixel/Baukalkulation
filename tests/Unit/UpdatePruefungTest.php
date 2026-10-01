<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdatePruefung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Soll-Tests AP-20261001-update Stufe 1, Schritt 1: Release-Abfrage ohne Netz
 * (Fake-Abruf mit Zähler, feste Uhrzeit, Cache-Datei im Temp-Verzeichnis).
 */
final class UpdatePruefungTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/update/github_releases.json';
    private const REPO = 'acme/baukalkulation';
    private const JETZT = '2026-10-01T10:00:00+02:00';

    private string $verzeichnis;
    private string $cacheDatei;
    private string $errorLogAlt;
    /** @var list<string> */
    private array $abgerufeneUrls = [];

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/bk-update-' . bin2hex(random_bytes(6));
        mkdir($this->verzeichnis);
        $this->cacheDatei = $this->verzeichnis . '/update_check.json';
        $this->errorLogAlt = (string) ini_get('error_log');
        ini_set('error_log', $this->verzeichnis . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogAlt);
        foreach (glob($this->verzeichnis . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->verzeichnis);
    }

    // ── Repo-Prüfung (Entscheidung G1, AK 9) ──────────────────

    public static function gueltigeRepos(): iterable
    {
        yield 'einfach' => ['acme/baukalkulation'];
        yield 'Großbuchstaben, Ziffern, Bindestrich' => ['Acme-1/Bau-Kalk2'];
        yield 'Punkt und Unterstrich im Namen' => ['acme/bau.kalk_ulation'];
    }

    #[DataProvider('gueltigeRepos')]
    public function testGueltigesRepo(string $repo): void
    {
        $this->assertTrue($this->pruefung(repo: $repo)->repoGueltig());
    }

    public static function ungueltigeRepos(): iterable
    {
        yield 'null' => [null];
        yield 'leer' => [''];
        yield 'nur Leerzeichen' => ['   '];
        yield 'ohne Name' => ['acme'];
        yield 'Name fehlt' => ['acme/'];
        yield 'Owner fehlt' => ['/baukalkulation'];
        yield 'Punkt-Punkt als Name' => ['a/..'];
        yield 'Punkt-Punkt im Namen' => ['acme/bau..kalk'];
        yield 'drei Teile' => ['a/b/c'];
        yield 'URL' => ['https://github.com/acme/baukalkulation'];
        yield 'Host vorangestellt' => ['github.com/acme/baukalkulation'];
        yield 'Punkt im Owner' => ['ac.me/baukalkulation'];
        yield 'Leerzeichen' => ['acme/bau kalkulation'];
        yield 'Zeilenumbruch am Ende' => ["acme/baukalkulation\n"];
        yield 'Query' => ['acme/baukalkulation?x=1'];
        yield 'Umlaut' => ['acme/baukalkülation'];
        yield 'Name beginnt mit Punkt (.git)' => ['a/.git'];
        yield 'Name nur Punkt' => ['a/.'];
        yield 'Owner beginnt mit Punkt' => ['.a/b'];
    }

    #[DataProvider('ungueltigeRepos')]
    public function testUngueltigesRepo(?string $repo): void
    {
        $this->assertFalse($this->pruefung(repo: $repo)->repoGueltig());
    }

    #[DataProvider('ungueltigeRepos')]
    public function testOhneGueltigesRepoKeinAbruf(?string $repo): void
    {
        $pruefung = $this->pruefung(repo: $repo);

        $ergebnis = $pruefung->pruefen(true);

        $this->assertSame([], $this->abgerufeneUrls, 'Ohne gültiges Repo darf nicht abgerufen werden');
        $this->assertSame('nicht_konfiguriert', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_NICHT_KONFIGURIERT, $ergebnis['meldung']);
        $this->assertNull($ergebnis['neueste_version']);
        $this->assertSame([], $pruefung->verfuegbareVersionen());
        $this->assertSame([], $this->abgerufeneUrls);
    }

    public function testEinrichtungshinweisNenntVariable(): void
    {
        $this->assertStringContainsString('BK_UPDATE_REPO', UpdatePruefung::MELDUNG_NICHT_KONFIGURIERT);
    }

    // ── Abruf und Parser (Schritt 1, AK 4) ───────────────────

    public function testRuftFesteGithubUrlMitPerPage30Ab(): void
    {
        $this->pruefung()->pruefen(false);

        $this->assertSame(['https://api.github.com/repos/acme/baukalkulation/releases?per_page=30'], $this->abgerufeneUrls);
    }

    public function testVerfuegbareVersionenOhneDraftsPrereleasesUndFremdeTagsAbsteigend(): void
    {
        $this->assertSame(
            ['3.1.0', '3.0.10', '3.0.9', '3.0.1', '2.10.99'],
            $this->pruefung()->verfuegbareVersionen(),
        );
    }

    public function testNeuereVersionErgibtUpdateVerfuegbar(): void
    {
        $ergebnis = $this->pruefung(aktuell: '3.0.1')->pruefen(false);

        $this->assertSame('update_verfuegbar', $ergebnis['status']);
        $this->assertSame('3.0.1', $ergebnis['aktuelle_version']);
        $this->assertSame('3.1.0', $ergebnis['neueste_version']);
        $this->assertSame('2026-09-30', $ergebnis['veroeffentlicht']);
        $this->assertSame('https://github.com/acme/baukalkulation/releases/tag/v3.1.0', $ergebnis['link']);
        $this->assertIsString($ergebnis['notes']);
        $this->assertStringContainsString('Update-Fenster in der Verwaltung', $ergebnis['notes']);
        $this->assertStringContainsString('Umlaute (ä, ö, ü, ß)', $ergebnis['notes']);
        $this->assertNull($ergebnis['meldung']);
        $this->assertSame((new \DateTimeImmutable(self::JETZT))->format('c'), $ergebnis['geprueft']);
    }

    public static function nichtNeuereVersionen(): iterable
    {
        yield 'gleich' => ['3.1.0'];
        yield 'neuer als Release' => ['3.2.0'];
        yield 'numerisch neuer (nicht Zeichenkette)' => ['3.10.0'];
    }

    #[DataProvider('nichtNeuereVersionen')]
    public function testGleicheOderNeuereVersionIstAktuell(string $aktuell): void
    {
        $ergebnis = $this->pruefung(aktuell: $aktuell)->pruefen(false);

        $this->assertSame('aktuell', $ergebnis['status']);
        $this->assertSame('3.1.0', $ergebnis['neueste_version']);
        $this->assertNull($ergebnis['meldung']);
    }

    public function testVersionsvergleichNumerisch(): void
    {
        // 3.0.10 > 3.0.9 – reiner Zeichenkettenvergleich wäre falsch.
        $ergebnis = $this->pruefung(antwort: self::releases(['v3.0.9', 'v3.0.10']), aktuell: '3.0.9')->pruefen(false);

        $this->assertSame('update_verfuegbar', $ergebnis['status']);
        $this->assertSame('3.0.10', $ergebnis['neueste_version']);
    }

    public function testDraftWirdNieAngeboten(): void
    {
        $versionen = $this->pruefung()->verfuegbareVersionen();

        $this->assertNotContains('9.0.0', $versionen);
        $this->assertNotContains('4.0.0', $versionen);
        $this->assertNotContains('3.2.0-rc1', $versionen);
        $this->assertNotContains('3.2.0', $versionen);
    }

    public function testNotesWerdenGekuerzt(): void
    {
        $lang = str_repeat('Änderung ä ö ü ß – ', 1000);
        $ergebnis = $this->pruefung(antwort: self::releases(['v3.1.0'], body: $lang), aktuell: '3.0.1')->pruefen(false);

        $this->assertIsString($ergebnis['notes']);
        $this->assertLessThanOrEqual(UpdatePruefung::NOTES_MAX, mb_strlen($ergebnis['notes']));
        $this->assertStringStartsWith('Änderung ä ö ü ß', $ergebnis['notes']);
        $this->assertTrue(mb_check_encoding($ergebnis['notes'], 'UTF-8'), 'Kürzung darf kein Multibyte-Zeichen zerschneiden');
    }

    public static function fremdeLinks(): iterable
    {
        yield 'fremder Host' => ['https://evil.example/acme/baukalkulation/releases/tag/v3.1.0'];
        yield 'anderes Repo mit gleichem Präfix' => ['https://github.com/acme/baukalkulation-evil/releases/tag/v3.1.0'];
        yield 'anderer Owner' => ['https://github.com/evil/baukalkulation/releases/tag/v3.1.0'];
        yield 'http statt https' => ['http://github.com/acme/baukalkulation/releases/tag/v3.1.0'];
        yield 'javascript' => ['javascript:alert(1)//https://github.com/acme/baukalkulation/releases/'];
        yield 'Subdomain-Trick' => ['https://github.com.evil.example/acme/baukalkulation/releases/tag/v3.1.0'];
        yield 'leer' => [''];
    }

    #[DataProvider('fremdeLinks')]
    public function testLinkNurMitErwartetemPraefix(string $url): void
    {
        $ergebnis = $this->pruefung(antwort: self::releases(['v3.1.0'], htmlUrl: $url), aktuell: '3.0.1')->pruefen(false);

        $this->assertSame('3.1.0', $ergebnis['neueste_version']);
        $this->assertNull($ergebnis['link']);
    }

    // ── Drosselung (6 h Erfolg, 30 min Fehlschlag, AK 5) ─────

    public function testErfolgWirdSechsStundenGecacht(): void
    {
        $erstes = $this->pruefung(aktuell: '3.0.1')->pruefen(false);
        $zweites = $this->pruefung(aktuell: '3.0.1', jetzt: '+5 hours 59 minutes')->pruefen(false);

        $this->assertCount(1, $this->abgerufeneUrls);
        $this->assertSame($erstes, $zweites);
        $this->assertSame(['3.1.0', '3.0.10', '3.0.9', '3.0.1', '2.10.99'], $this->pruefung(jetzt: '+1 hour')->verfuegbareVersionen());
        $this->assertCount(1, $this->abgerufeneUrls, 'verfuegbareVersionen() nutzt denselben Cache');
    }

    public function testNachSechsStundenNeuerAbruf(): void
    {
        $this->pruefung()->pruefen(false);
        $this->pruefung(jetzt: '+6 hours')->pruefen(false);

        $this->assertCount(2, $this->abgerufeneUrls);
    }

    public function testForceUmgehtErfolgsCache(): void
    {
        $this->pruefung()->pruefen(false);
        $this->pruefung(jetzt: '+1 minute')->pruefen(true);

        $this->assertCount(2, $this->abgerufeneUrls);
    }

    public function testManuellePruefungNutztCacheFuenfMinuten(): void
    {
        $this->pruefung()->pruefen(false);
        $this->pruefung(jetzt: '+4 minutes')->pruefen(false, manuell: true);
        $this->assertCount(1, $this->abgerufeneUrls);

        $ergebnis = $this->pruefung(jetzt: '+5 minutes', aktuell: '3.0.1')->pruefen(false, manuell: true);
        $this->assertCount(2, $this->abgerufeneUrls, 'Neues Release muss per Knopf nach 5 Minuten sichtbar sein');
        $this->assertSame('update_verfuegbar', $ergebnis['status']);
    }

    public function testManuellePruefungBeachtetFehlschlagSperre(): void
    {
        $this->pruefung(antwort: null)->pruefen(false);
        $this->pruefung(jetzt: '+10 minutes')->pruefen(false, manuell: true);

        $this->assertCount(1, $this->abgerufeneUrls);
    }

    public function testFehlschlagWirdDreissigMinutenGedrosselt(): void
    {
        $this->pruefung(antwort: null)->pruefen(false);
        $ergebnis = $this->pruefung(jetzt: '+29 minutes')->pruefen(false);

        $this->assertCount(1, $this->abgerufeneUrls);
        $this->assertSame('fehler', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_FEHLER, $ergebnis['meldung']);
    }

    public function testNachDreissigMinutenNeuerAbruf(): void
    {
        $this->pruefung(antwort: null)->pruefen(false);
        $ergebnis = $this->pruefung(jetzt: '+30 minutes', aktuell: '3.0.1')->pruefen(false);

        $this->assertCount(2, $this->abgerufeneUrls);
        $this->assertSame('update_verfuegbar', $ergebnis['status']);
    }

    public function testForceUmgehtFehlschlagSperre(): void
    {
        $this->pruefung(antwort: null)->pruefen(false);
        $this->pruefung(jetzt: '+1 minute')->pruefen(true);

        $this->assertCount(2, $this->abgerufeneUrls);
    }

    public function testRepoWechselMachtCacheUngueltig(): void
    {
        $this->pruefung()->pruefen(false);
        $this->pruefung(repo: 'other/projekt', jetzt: '+1 minute')->pruefen(false);

        $this->assertSame([
            'https://api.github.com/repos/acme/baukalkulation/releases?per_page=30',
            'https://api.github.com/repos/other/projekt/releases?per_page=30',
        ], $this->abgerufeneUrls);
    }

    public function testDefekteCacheDateiFuehrtZuAbruf(): void
    {
        file_put_contents($this->cacheDatei, '{kaputt');

        $ergebnis = $this->pruefung(aktuell: '3.0.1')->pruefen(false);

        $this->assertCount(1, $this->abgerufeneUrls);
        $this->assertSame('update_verfuegbar', $ergebnis['status']);
    }

    // ── Gecachter Stand ohne Abruf (GET update_check, Review Runde 1) ──

    public function testGecachterStandOhneCacheIstUngeprueftOhneAbruf(): void
    {
        $ergebnis = $this->pruefung()->gecachterStand();

        $this->assertSame([], $this->abgerufeneUrls, 'gecachterStand() darf nie abrufen');
        $this->assertSame('ungeprueft', $ergebnis['status']);
        $this->assertNull($ergebnis['meldung']);
        $this->assertNull($ergebnis['neueste_version']);
        $this->assertNull($ergebnis['geprueft']);
        $this->assertFileDoesNotExist($this->cacheDatei, 'gecachterStand() darf keine Datei schreiben');
    }

    public function testGecachterStandOhneRepoNichtKonfiguriert(): void
    {
        $ergebnis = $this->pruefung(repo: null)->gecachterStand();

        $this->assertSame([], $this->abgerufeneUrls);
        $this->assertSame('nicht_konfiguriert', $ergebnis['status']);
        $this->assertFileDoesNotExist($this->cacheDatei);
    }

    public static function cacheAlter(): iterable
    {
        yield 'frisch' => ['+1 minute'];
        yield 'Erfolg abgelaufen (6 h)' => ['+6 hours'];
        yield 'sehr alt' => ['+30 days'];
    }

    #[DataProvider('cacheAlter')]
    public function testGecachterStandLiefertErfolgUnabhaengigVomAlterOhneAbruf(string $alter): void
    {
        $erstes = $this->pruefung(aktuell: '3.0.1')->pruefen(false);
        $inhalt = (string) file_get_contents($this->cacheDatei);

        $stand = $this->pruefung(aktuell: '3.0.1', jetzt: $alter)->gecachterStand();

        $this->assertCount(1, $this->abgerufeneUrls, 'gecachterStand() darf auch bei abgelaufenem Cache nicht abrufen');
        $this->assertSame($erstes, $stand);
        $this->assertSame($inhalt, (string) file_get_contents($this->cacheDatei), 'gecachterStand() darf den Cache nicht verändern');
    }

    // ── Keine stabile Release (Festlegung Abschnitt 3) ───────

    public static function ohneStabileRelease(): iterable
    {
        yield 'leere Liste' => ['[]'];
        yield 'nur Prereleases und Drafts' => [(string) json_encode([
            ['tag_name' => 'v3.2.0-rc1', 'draft' => false, 'prerelease' => true, 'html_url' => 'https://github.com/acme/baukalkulation/releases/tag/v3.2.0-rc1', 'published_at' => '2026-09-30T08:00:00Z', 'body' => 'rc'],
            ['tag_name' => 'v4.0.0', 'draft' => false, 'prerelease' => true, 'html_url' => 'https://github.com/acme/baukalkulation/releases/tag/v4.0.0', 'published_at' => '2026-09-30T08:00:00Z', 'body' => 'pre'],
            ['tag_name' => 'v9.0.0', 'draft' => true, 'prerelease' => false, 'html_url' => 'https://github.com/acme/baukalkulation/releases/tag/v9.0.0', 'published_at' => null, 'body' => 'draft'],
        ])];
    }

    #[DataProvider('ohneStabileRelease')]
    public function testOhneStabileReleaseKeineReleases(string $antwort): void
    {
        $ergebnis = $this->pruefung(antwort: $antwort, aktuell: '3.0.1')->pruefen(false);

        $this->assertSame('keine_releases', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_KEINE_RELEASES, $ergebnis['meldung']);
        $this->assertNull($ergebnis['neueste_version']);
        $this->assertSame((new \DateTimeImmutable(self::JETZT))->format('c'), $ergebnis['geprueft']);
        $this->assertSame([], $this->pruefung(antwort: $antwort, jetzt: '+1 hour')->verfuegbareVersionen());
        $this->assertCount(1, $this->abgerufeneUrls, 'keine_releases wird wie Erfolg gecacht');
    }

    // ── Fehlerpfad (AK 5) ────────────────────────────────────

    public static function fehlerAntworten(): iterable
    {
        yield 'nicht erreichbar' => [null];
        yield 'leer' => [''];
        yield 'kein JSON' => ['<html>Rate limit</html>'];
        yield 'Objekt statt Liste' => ['{"message":"Not Found","documentation_url":"https://docs.github.com/rest"}'];
    }

    #[DataProvider('fehlerAntworten')]
    public function testUngueltigeAntwortErgibtFesteMeldung(?string $antwort): void
    {
        $ergebnis = $this->pruefung(antwort: $antwort)->pruefen(false);

        $this->assertSame('fehler', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_FEHLER, $ergebnis['meldung']);
        $this->assertNull($ergebnis['neueste_version']);
    }

    public function testExceptionWirdNichtNachAussenGegeben(): void
    {
        $pruefung = new UpdatePruefung(
            self::REPO,
            $this->cacheDatei,
            function (string $url): ?string {
                $this->abgerufeneUrls[] = $url;
                throw new \RuntimeException('curl: /var/www/html/geheim token=abc123');
            },
            new \DateTimeImmutable(self::JETZT),
            '3.0.1',
        );

        $ergebnis = $pruefung->pruefen(false);

        $this->assertSame('fehler', $ergebnis['status']);
        $this->assertSame(UpdatePruefung::MELDUNG_FEHLER, $ergebnis['meldung']);
        $json = (string) json_encode($ergebnis);
        $this->assertStringNotContainsString('geheim', $json);
        $this->assertStringNotContainsString('abc123', $json);
        $this->assertSame([], $pruefung->verfuegbareVersionen());
    }

    public function testRueckgabeHatFesteSchluessel(): void
    {
        foreach ([$this->pruefung(aktuell: '3.0.1'), $this->pruefung(repo: null)] as $pruefung) {
            $this->assertSame(
                ['status', 'aktuelle_version', 'neueste_version', 'veroeffentlicht', 'notes', 'link', 'geprueft', 'meldung'],
                array_keys($pruefung->pruefen(false)),
            );
        }
    }

    public function testOhneVersionsangabeGiltVersionDatei(): void
    {
        $pruefung = new UpdatePruefung(null, $this->cacheDatei);

        $this->assertSame(
            trim((string) file_get_contents(__DIR__ . '/../../VERSION')),
            $pruefung->pruefen(false)['aktuelle_version'],
        );
    }

    // ── Hilfen ───────────────────────────────────────────────

    private function pruefung(
        ?string $repo = self::REPO,
        ?string $antwort = 'fixture',
        string $aktuell = '3.1.0',
        string $jetzt = '+0 seconds',
    ): UpdatePruefung {
        $body = $antwort === 'fixture' ? (string) file_get_contents(self::FIXTURE) : $antwort;
        return new UpdatePruefung(
            $repo,
            $this->cacheDatei,
            function (string $url) use ($body): ?string {
                $this->abgerufeneUrls[] = $url;
                return $body;
            },
            (new \DateTimeImmutable(self::JETZT))->modify($jetzt),
            $aktuell,
        );
    }

    /** @param list<string> $tags */
    private static function releases(array $tags, string $body = 'Notes', ?string $htmlUrl = null): string
    {
        $liste = [];
        foreach ($tags as $i => $tag) {
            $liste[] = [
                'id' => 2000 + $i,
                'tag_name' => $tag,
                'name' => $tag,
                'draft' => false,
                'prerelease' => false,
                'html_url' => $htmlUrl ?? 'https://github.com/' . self::REPO . '/releases/tag/' . $tag,
                'published_at' => '2026-09-30T08:00:00Z',
                'body' => $body,
            ];
        }
        return (string) json_encode($liste);
    }
}
