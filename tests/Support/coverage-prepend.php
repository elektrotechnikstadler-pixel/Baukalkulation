<?php

declare(strict_types=1);

/**
 * Aktiviert Code-Coverage-Messung für den PHP-Built-in-Server und CLI-Läufe während der Tests
 * (AP-20261119-testabdeckung-klicktests, B0). Nur aktiv, wenn BK_COVERAGE_DIR gesetzt ist UND
 * ein Coverage-Treiber (pcov/xdebug) geladen ist – sonst bleibt der Prozess unverändert.
 *
 * Einbindung über BK_TEST_PHP_ARGS: "-d auto_prepend_file=.../tests/Support/coverage-prepend.php"
 * (siehe scripts/test-cov.ps1 / Makefile test-cov); TestServer.php reicht BK_TEST_PHP_ARGS bereits
 * an Server **und** runCli() durch. Schreibt je Anfrage/CLI-Lauf eine eigene .cov-Datei nach
 * BK_COVERAGE_DIR; test-cov.ps1 führt alle Dateien per `vendor/bin/phpcov merge` zusammen.
 */

$bkCoverageDir = getenv('BK_COVERAGE_DIR');
if ($bkCoverageDir === false || $bkCoverageDir === '') {
    return;
}

$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/vendor/autoload.php';

$runtime = new \SebastianBergmann\Environment\Runtime();
if (!$runtime->hasPCOV() && !$runtime->hasXdebug()) {
    // Treiber nicht geladen (z. B. produktiver Lauf ohne Coverage) – nichts tun.
    return;
}

/** @return list<string> */
$phpFilesIn = static function (string $dir): array {
    $files = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    return $files;
};

$filter = new \SebastianBergmann\CodeCoverage\Filter();
foreach (['src', 'modules'] as $dir) {
    $path = $appRoot . DIRECTORY_SEPARATOR . $dir;
    if (is_dir($path)) {
        $filter->includeFiles($phpFilesIn($path));
    }
}

try {
    $driver = (new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter);
} catch (\Throwable $e) {
    // Treiber meldet sich als geladen, lässt sich aber nicht nutzen – Messung überspringen.
    return;
}

$coverage = new \SebastianBergmann\CodeCoverage\CodeCoverage($driver, $filter);
$coverage->start('bk-' . (PHP_SAPI === 'cli' ? 'cli' : 'http') . '-' . getmypid());

register_shutdown_function(static function () use ($coverage, $bkCoverageDir): void {
    $coverage->stop();
    // Race zwischen parallelen Shutdown-Funktionen moeglich (mehrere CLI-/Server-Prozesse):
    // mkdir() ohne @-Operator aufrufen, Fehlschlag aber nur melden, wenn das Verzeichnis
    // danach wirklich noch fehlt (E-063 – kein stiller Fehler, kein @).
    if (!is_dir($bkCoverageDir) && !mkdir($bkCoverageDir, 0777, true) && !is_dir($bkCoverageDir)) {
        throw new \RuntimeException(sprintf('Coverage-Verzeichnis "%s" konnte nicht angelegt werden.', $bkCoverageDir));
    }
    $target = rtrim($bkCoverageDir, '/\\') . DIRECTORY_SEPARATOR
        . 'bk-' . bin2hex(random_bytes(6)) . '.cov';
    (new \SebastianBergmann\CodeCoverage\Report\PHP())->process($coverage, $target);
});
