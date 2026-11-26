# Alle PHPUnit-Tests mit Code-Coverage ausführen (PHPUnit-Prozess + Built-in-Server + CLI-Läufe),
# Windows-Gegenstück zu `make test-cov` (AP-20261119-testabdeckung-klicktests, B0).
# Aufruf: powershell -File scripts/test-cov.ps1 -Php <pfad\php.exe> [-Treiber pcov|xdebug] [-PhpUnitArgs '--testsuite','unit']
param(
    [string]$Php = 'php',
    [ValidateSet('pcov', 'xdebug')]
    [string]$Treiber = 'pcov',
    [string[]]$PhpUnitArgs = @()
)
# Windows PowerShell 5.1 wertet stderr nativer Befehle bei "Stop" als Abbruch – Exitcodes werden selbst geprüft.
$ErrorActionPreference = 'Continue'
$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

# ── Treiber real prüfen statt annehmen (E-026/E-095) – kein Teilbericht, wenn nicht ladbar ──
$pruefAusgabe = & $Php -d "extension=$Treiber" -r "echo extension_loaded('$Treiber') ? 'OK' : 'FEHLT';" 2>&1
if ($pruefAusgabe -notmatch 'OK') {
    throw @"
Coverage-Treiber '$Treiber' konnte nicht per '-d extension=$Treiber' geladen werden (Ausgabe: $pruefAusgabe).

Abhilfe:
  - pcov:   php_pcov-<version>-<phpversion>-<ts|nts>-<vs>-x64.zip von
            https://downloads.php.net/~windows/pecl/releases/pcov/ entpacken,
            php_pcov.dll nach <PHP-Verzeichnis>\ext kopieren. php.ini muss NICHT
            geändert werden – '-d extension=pcov' lädt die DLL ad hoc.
  - xdebug: passende Xdebug-DLL von https://xdebug.org/download nach
            <PHP-Verzeichnis>\ext kopieren.
  - Alternativ -Treiber auf den jeweils anderen Wert setzen, falls dieser installiert ist.
"@
}

# ── Coverage-Verzeichnis frisch anlegen ──────────────────────────────────────
$covDir = Join-Path $repoRoot 'coverage'
$partsDir = Join-Path $covDir 'parts'
if (Test-Path $covDir) { Remove-Item $covDir -Recurse -Force }
New-Item -ItemType Directory -Path $partsDir -Force | Out-Null

$prependPath = Join-Path $repoRoot 'tests\Support\coverage-prepend.php'
$phpunitCovFile = Join-Path $partsDir 'phpunit.cov'

$code = 1
try {
    $env:BK_COVERAGE_DIR = $partsDir
    $env:BK_TEST_PHP_ARGS = "-d extension=$Treiber -d auto_prepend_file=$prependPath"

    & $Php -d "extension=$Treiber" vendor/bin/phpunit --coverage-php $phpunitCovFile @PhpUnitArgs
    $code = $LASTEXITCODE

    $covParts = Get-ChildItem -Path $partsDir -Filter '*.cov' -ErrorAction SilentlyContinue
    if (-not $covParts) {
        throw "Keine .cov-Dateien in $partsDir gefunden - Coverage-Messung fehlgeschlagen."
    }

    & $Php vendor/bin/phpcov merge $partsDir --html (Join-Path $covDir 'html') --clover (Join-Path $covDir 'clover.xml') --text php://stdout
} finally {
    'BK_COVERAGE_DIR', 'BK_TEST_PHP_ARGS' | ForEach-Object { Remove-Item "Env:$_" -ErrorAction SilentlyContinue }
}
exit $code
