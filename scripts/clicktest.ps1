# Klicktests (Playwright) - Windows-Gegenstück zu `make clicktest`.
# AP-20261119-testabdeckung-klicktests, B6. Läuft NUR auf ausdrückliche Aufforderung
# (nicht automatisch, nicht pre-commit, nicht CI-Pflicht - siehe AGENTS.md).
#
# Aufruf: powershell -File scripts/clicktest.ps1 -Php <pfad\php.exe> `
#           [-Db sqlite|pgsql] [-Project chromium-desktop] [-Grep "Login"] [-Headed] [-Install]
param(
    [string]$Php = 'php',
    [ValidateSet('sqlite', 'pgsql')]
    [string]$Db = 'sqlite',
    [string[]]$Project = @(),
    [string]$Grep = '',
    [switch]$Headed,
    [switch]$Install,
    [int]$PgPort = 55433,
    [string]$PgImage = 'postgres:17-alpine'
)
# Windows PowerShell 5.1 wertet stderr nativer Befehle bei "Stop" als Abbruch – Exitcodes werden selbst geprüft.
$ErrorActionPreference = 'Continue'
$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

# ── Nie gegen das echte Datenverzeichnis des Repos testen ────────────────────
$echtesDatenverzeichnis = (Resolve-Path (Join-Path $repoRoot 'data') -ErrorAction SilentlyContinue).Path
if ($env:BK_DATA_DIR) {
    $angegeben = (Resolve-Path $env:BK_DATA_DIR -ErrorAction SilentlyContinue).Path
    if ($angegeben -and $echtesDatenverzeichnis -and $angegeben -eq $echtesDatenverzeichnis) {
        throw "BK_DATA_DIR zeigt auf das Produktiv-Datenverzeichnis ($echtesDatenverzeichnis) - Abbruch. Klicktests nutzen je Worker einen eigenen Temp-Ordner (tests/e2e/fixtures.mjs), BK_DATA_DIR darf hier nicht gesetzt sein."
    }
}

# ── Voraussetzungen prüfen ────────────────────────────────────────────────────
$phpGefunden = (Get-Command $Php -ErrorAction SilentlyContinue) -or (Test-Path $Php)
if (-not $phpGefunden) {
    throw "PHP nicht gefunden unter '$Php'. -Php <pfad\php.exe> angeben."
}
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    throw 'Node.js nicht gefunden (wird fuer Playwright benoetigt).'
}
if (-not (Test-Path (Join-Path $repoRoot 'node_modules\@playwright\test\package.json'))) {
    throw "@playwright/test nicht installiert - zuerst 'npm.cmd install' ausfuehren."
}

if ($Install) {
    npx.cmd playwright install chromium firefox webkit
    if ($LASTEXITCODE -ne 0) { throw 'Playwright-Browser-Installation fehlgeschlagen.' }
}

# ── Frontend bauen (public/dist/ ist gitignored, siehe .gitignore) ───────────
npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw 'npm run build fehlgeschlagen.' }

# ── script.min.js darf nicht veralten: keine stillen Abweichungen zwischen ───
# eingecheckter (script.min.js) und gebauter Datei (script.js) zulassen.
$scriptJs = Get-Item (Join-Path $repoRoot 'public\script.js')
$scriptMinJs = Get-Item (Join-Path $repoRoot 'public\script.min.js')
if ($scriptMinJs.LastWriteTime -lt $scriptJs.LastWriteTime) {
    throw "public/script.min.js ist aelter als public/script.js - 'npm run minify' ausfuehren und committen, bevor Klicktests laufen."
}

$pgContainerName = 'bk-pg-clicktest'
$code = 1
try {
    $env:BK_PHP = $Php

    if ($Db -eq 'pgsql') {
        # Nie gegen einen fremden Server testen: die Tests leeren das Schema (E-064).
        if (Get-NetTCPConnection -LocalPort $PgPort -State Listen -ErrorAction SilentlyContinue) {
            throw "Port $PgPort ist bereits belegt - Abbruch."
        }
        docker rm -f $pgContainerName 2>$null | Out-Null
        docker run -d --rm --name $pgContainerName -p "127.0.0.1:${PgPort}:5432" `
            -e POSTGRES_USER=bk -e POSTGRES_PASSWORD=bk-test-pw -e POSTGRES_DB=bk_test $PgImage | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL-Container konnte nicht gestartet werden.' }

        $bereit = $false
        for ($i = 0; $i -lt 60 -and -not $bereit; $i++) {
            docker exec $pgContainerName pg_isready -U bk -d bk_test 2>$null | Out-Null
            $bereit = ($LASTEXITCODE -eq 0)
            if (-not $bereit) { Start-Sleep -Seconds 1 }
        }
        if (-not $bereit) { throw 'PostgreSQL wurde nicht rechtzeitig bereit.' }

        $env:BK_DB_DRIVER = 'pgsql'
        $env:BK_DB_HOST = '127.0.0.1'
        $env:BK_DB_PORT = "$PgPort"
        $env:BK_DB_NAME = 'bk_test'
        $env:BK_DB_USER = 'bk'
        $env:BK_DB_PASSWORD = 'bk-test-pw'
        $env:BK_TEST_PHP_ARGS = '-d extension=pdo_pgsql'
    }

    $pwArgs = @()
    foreach ($p in $Project) { $pwArgs += @('--project', $p) }
    if ($Grep) { $pwArgs += @('--grep', $Grep) }
    if ($Headed) { $pwArgs += '--headed' }
    # Nur ein PostgreSQL-Schema vorhanden - Tests dürfen sich nicht gegenseitig überschreiben.
    if ($Db -eq 'pgsql') { $pwArgs += @('--workers', '1') }

    npx.cmd playwright test @pwArgs
    $code = $LASTEXITCODE
} finally {
    node scripts/clicktest-befunde.mjs

    if ($Db -eq 'pgsql') {
        docker rm -f $pgContainerName 2>$null | Out-Null
    }
    'BK_PHP', 'BK_DB_DRIVER', 'BK_DB_HOST', 'BK_DB_PORT', 'BK_DB_NAME', 'BK_DB_USER', 'BK_DB_PASSWORD', 'BK_TEST_PHP_ARGS' |
        ForEach-Object { Remove-Item "Env:$_" -ErrorAction SilentlyContinue }
}
exit $code
