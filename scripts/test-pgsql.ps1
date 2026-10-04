# Alle PHPUnit-Tests gegen PostgreSQL im Wegwerf-Container – Windows-Gegenstück zu `make test-pgsql`.
# Aufruf: powershell -File scripts/test-pgsql.ps1 -Php <pfad\php.exe> [-PhpUnitArgs '--filter','Sollzeit']
param(
    [string]$Php = 'php',
    [int]$Port = 55432,
    [string]$Image = 'postgres:17-alpine',
    [string[]]$PhpUnitArgs = @()
)
# Windows PowerShell 5.1 wertet stderr nativer Befehle bei "Stop" als Abbruch – Exitcodes werden selbst geprüft.
$ErrorActionPreference = 'Continue'
$name = 'bk-pg-test'

# Nie gegen einen fremden Server testen: die Tests leeren das Schema (E-064).
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    throw "Port $Port ist bereits belegt - Abbruch."
}

docker rm -f $name 2>$null | Out-Null
docker run -d --rm --name $name -p "127.0.0.1:${Port}:5432" `
    -e POSTGRES_USER=bk -e POSTGRES_PASSWORD=bk-test-pw -e POSTGRES_DB=bk_test $Image | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL-Container konnte nicht gestartet werden.' }

$code = 1
try {
    $ready = $false
    for ($i = 0; $i -lt 60 -and -not $ready; $i++) {
        docker exec $name pg_isready -U bk -d bk_test 2>$null | Out-Null
        $ready = ($LASTEXITCODE -eq 0)
        if (-not $ready) { Start-Sleep -Seconds 1 }
    }
    if (-not $ready) { throw 'PostgreSQL wurde nicht rechtzeitig bereit.' }

    $env:BK_DB_DRIVER = 'pgsql'
    $env:BK_DB_HOST = '127.0.0.1'
    $env:BK_DB_PORT = "$Port"
    $env:BK_DB_NAME = 'bk_test'
    $env:BK_DB_USER = 'bk'
    $env:BK_DB_PASSWORD = 'bk-test-pw'
    $env:BK_TEST_PHP_ARGS = '-d extension=pdo_pgsql'
    & $Php -d extension=pdo_pgsql vendor/bin/phpunit @PhpUnitArgs
    $code = $LASTEXITCODE
} finally {
    docker rm -f $name 2>$null | Out-Null
    'BK_DB_DRIVER', 'BK_DB_HOST', 'BK_DB_PORT', 'BK_DB_NAME', 'BK_DB_USER', 'BK_DB_PASSWORD', 'BK_TEST_PHP_ARGS' |
        ForEach-Object { Remove-Item "Env:$_" -ErrorAction SilentlyContinue }
}
exit $code
