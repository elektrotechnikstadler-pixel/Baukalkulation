<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Wächter (B0.4, AP-20261119-testabdeckung-klicktests): Jede API-Aktion (public/api.php), jede
 * Modul-Unteraktion (modules/*\/backend/Module.php), jede DIN-1090-Unteraktion
 * (modules/din1090/Din1090Actions.php) und jeder CLI-Befehl (src/Console/Kernel.php) bzw.
 * cron_*.php-Skript muss entweder per Aufrufmuster in tests/ getestet sein ODER als bekannte
 * Lücke in tests/fixtures/endpunkt-luecken.json stehen.
 *
 * Die Lückenliste darf nur schrumpfen (analog phpstan-baseline.neon): ein Eintrag, der
 * inzwischen getestet ist, muss aus der Fixture entfernt werden - sonst schlägt dieser Test fehl.
 */
final class EndpunktInventarTest extends TestCase
{
    private const APP_ROOT = __DIR__ . '/../..';

    public function testAlleEndpunkteGetestetOderInLueckenliste(): void
    {
        $luecken = json_decode(
            (string) file_get_contents(self::APP_ROOT . '/tests/fixtures/endpunkt-luecken.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $testCode = $this->testQuelltext();
        $getestet = $this->apiGetesteteAktionen($testCode);

        $probleme = $this->vergleiche('api', $this->apiRoutenAusQuelltext(), $getestet, $luecken['api']);
        $probleme = array_merge($probleme, $this->vergleiche('vor_routen', $this->vorRoutenAusQuelltext(), $getestet, $luecken['vor_routen']));

        foreach ($this->moduleAusQuelltext() as $modul => $aktionen) {
            $modulGetestet = $this->moduleGetesteteAktionen($testCode, $modul);
            $probleme = array_merge($probleme, $this->vergleiche("module.{$modul}", $aktionen, $modulGetestet, $luecken['module'][$modul] ?? []));
        }

        $probleme = array_merge($probleme, $this->vergleiche('din1090', $this->din1090AusQuelltext(), $this->din1090GetesteteAktionen($testCode), $luecken['din1090']));
        $probleme = array_merge($probleme, $this->vergleiche('cli', $this->cliBefehleUndCrons(), $this->cliGetestet($testCode), $luecken['cli']));

        self::assertSame(
            [],
            $probleme,
            "Endpunkt-Inventar weicht von tests/fixtures/endpunkt-luecken.json ab:\n" . implode("\n", $probleme),
        );
    }

    public function testVergleicheMeldetNeueUndErledigteLuecken(): void
    {
        $methode = new \ReflectionMethod(self::class, 'vergleiche');
        $methode->setAccessible(true);

        /** @var list<string> $probleme */
        $probleme = $methode->invoke($this, 'api', ['neu_route', 'ok_route', 'alt_route'], ['ok_route', 'alt_route'], ['alt_route']);

        self::assertSame([
            "[api] 'neu_route' ist ungetestet und fehlt in endpunkt-luecken.json.",
            "[api] 'alt_route' ist inzwischen getestet - bitte aus endpunkt-luecken.json entfernen.",
        ], $probleme);
    }

    /**
     * @param list<string> $alle      alle bekannten Endpunkte/Aktionen eines Bereichs
     * @param list<string> $getestet  davon per Aufrufmuster als getestet erkannt
     * @param list<string> $luecken   bisherige Lückenliste (darf nur schrumpfen)
     *
     * @return list<string>
     */
    private function vergleiche(string $bereich, array $alle, array $getestet, array $luecken): array
    {
        $tatsaechlichUngetestet = array_values(array_diff($alle, $getestet));
        $probleme = [];
        foreach (array_diff($tatsaechlichUngetestet, $luecken) as $neu) {
            $probleme[] = "[{$bereich}] '{$neu}' ist ungetestet und fehlt in endpunkt-luecken.json.";
        }
        foreach (array_diff($luecken, $tatsaechlichUngetestet) as $erledigt) {
            $probleme[] = "[{$bereich}] '{$erledigt}' ist inzwischen getestet - bitte aus endpunkt-luecken.json entfernen.";
        }
        foreach (array_diff($luecken, $alle) as $unbekannt) {
            $probleme[] = "[{$bereich}] '{$unbekannt}' aus endpunkt-luecken.json existiert nicht (mehr) als Endpunkt.";
        }
        return $probleme;
    }

    /** Nur tests/Api und tests/Unit (echte Testklassen) - tests/Support enthält nur Helfer,
     *  deren interne Implementierung (z. B. ApiClient::module()) sonst fälschlich als „getestet“
     *  erkannt würde. */
    private function testQuelltext(): string
    {
        $buffer = '';
        foreach (['Api', 'Unit'] as $verzeichnis) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::APP_ROOT . '/tests/' . $verzeichnis, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $buffer .= "\n" . file_get_contents($f->getPathname());
                }
            }
        }
        return $buffer;
    }

    /** @return list<string> */
    private function apiRoutenAusQuelltext(): array
    {
        $src = (string) file_get_contents(self::APP_ROOT . '/public/api.php');
        $marker = '$routes = [';
        $start = strpos($src, $marker) + strlen($marker);
        $end = strpos($src, "\n];", $start);
        $block = substr($src, $start, $end - $start);
        preg_match_all("/'([a-z0-9_]+)'\\s*=>\\s*\\[/", $block, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function vorRoutenAusQuelltext(): array
    {
        $src = (string) file_get_contents(self::APP_ROOT . '/public/api.php');
        $vorTeil = substr($src, 0, strpos($src, '$routes = ['));
        preg_match_all("/\\\$action === '([a-z0-9_]+)'/", $vorTeil, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function apiGetesteteAktionen(string $testCode): array
    {
        preg_match_all("/->(?:get|post|upload)\\(\\s*[\"']([a-zA-Z0-9_]+)[\"']/", $testCode, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return array<string,list<string>> Modulname => Sub-Aktionen (aus dem dispatch()-switch) */
    private function moduleAusQuelltext(): array
    {
        $ergebnis = [];
        foreach (glob(self::APP_ROOT . '/modules/*/backend/Module.php') ?: [] as $datei) {
            $modul = basename(dirname($datei, 2));
            $src = (string) file_get_contents($datei);
            preg_match_all("/case\\s*'([a-z0-9_]+)'\\s*:/", $src, $m);
            $aktionen = array_values(array_unique($m[1]));
            if ($aktionen !== []) {
                // z. B. modules/din1090/backend/Module.php ist nur eine Brücke zu Din1090Actions
                // (keine eigenen case-Zweige) und wird daher nicht separat gelistet.
                $ergebnis[$modul] = $aktionen;
            }
        }
        return $ergebnis;
    }

    /** @return list<string> */
    private function moduleGetesteteAktionen(string $testCode, string $modul): array
    {
        $pattern = "/->module\\(\\s*[\"']" . preg_quote($modul, '/') . "[\"']\\s*,\\s*[\"']([a-zA-Z0-9_]+)[\"']/";
        preg_match_all($pattern, $testCode, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function din1090AusQuelltext(): array
    {
        $src = (string) file_get_contents(self::APP_ROOT . '/modules/din1090/Din1090Actions.php');
        $marker = '$map = [';
        $start = strpos($src, $marker) + strlen($marker);
        $end = strpos($src, '];', $start);
        $block = substr($src, $start, $end - $start);
        preg_match_all("/'([a-z0-9_]+)'\\s*=>\\s*'[a-zA-Z0-9]+'/", $block, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    private function din1090GetesteteAktionen(string $testCode): array
    {
        preg_match_all("/->din1090\\(\\s*[\"']([a-zA-Z0-9_]+)[\"']/", $testCode, $m1);
        preg_match_all("/[\"']sub[\"']\\s*=>\\s*[\"']([a-zA-Z0-9_]+)[\"']/", $testCode, $m2);
        return array_values(array_unique(array_merge($m1[1], $m2[1])));
    }

    /** @return list<string> Kernel-Befehlsnamen (ohne Symfony-Standardbefehle) + cron_*.php */
    private function cliBefehleUndCrons(): array
    {
        $builtin = array_keys((new \Symfony\Component\Console\Application())->all());
        $alle = array_keys((new \App\Console\Kernel())->all());
        $befehle = array_values(array_diff($alle, $builtin));

        $crons = [];
        foreach (glob(self::APP_ROOT . '/cron_*.php') ?: [] as $datei) {
            $crons[] = basename($datei);
        }
        return array_values(array_merge($befehle, $crons));
    }

    /** @return list<string> */
    private function cliGetestet(string $testCode): array
    {
        preg_match_all("/runCli\\(\\s*[\"']bin\\/console[\"']\\s*,\\s*\\[\\s*[\"']([a-zA-Z0-9:_\\-]+)[\"']/", $testCode, $m1);
        preg_match_all("/runCli\\(\\s*[\"'](cron_[a-zA-Z0-9_]+\\.php)[\"']/", $testCode, $m2);
        return array_values(array_unique(array_merge($m1[1], $m2[1])));
    }
}
