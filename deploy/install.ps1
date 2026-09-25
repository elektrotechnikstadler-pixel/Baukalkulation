# ============================================================
# Baukalkulation – Interaktives Installations-/Update-Skript
# ============================================================
# Fragt die gewünschten Module ab, schreibt .env und baut die
# Container. Für Windows (PowerShell 5.1+ / 7+).
#
# Nutzung:
#   powershell -ExecutionPolicy Bypass -File .\install.ps1
# ============================================================

[CmdletBinding()]
param()

# Interaktivitaetspruefung – stdin darf nicht umgeleitet sein
if ([System.Console]::IsInputRedirected) {
    Write-Host ''
    Write-Host '❌ Fehler: Dieses Skript muss interaktiv in einem Terminal ausgefuehrt werden.' -ForegroundColor Red
    Write-Host '   Bitte direkt aufrufen: .\install.ps1' -ForegroundColor Red
    Write-Host '   Nicht via: Invoke-Expression, Task Scheduler oder nicht-interaktive Sitzungen.' -ForegroundColor Red
    Write-Host ''
    exit 1
}

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot
$envFile = Join-Path $PSScriptRoot '.env'

Write-Host ''
Write-Host '============================================================'
Write-Host '  Baukalkulation - Installation'
Write-Host '============================================================'
Write-Host ''

# ── Vorhandene .env einlesen ────────────────────────────────
$defaults = @{
    COMPOSE_PROJECT_NAME = ''
    APP_NAME             = ''
    APP_PORT             = '8081'
    WA_PORT              = '3002'
    ENABLE_OCR           = 'false'
    ENABLE_WHATSAPP      = 'false'
    WA_API_TOKEN         = ''
}

if (Test-Path $envFile) {
    Write-Host 'Bestehende .env gefunden - Werte werden als Vorgaben verwendet.'
    Write-Host ''
    Get-Content $envFile | ForEach-Object {
        if ($_ -match '^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$') {
            $defaults[$matches[1]] = $matches[2].Trim('"').Trim("'")
        }
    }
}

function Read-WithDefault([string]$prompt, [string]$default) {
    if ($default) { $ans = Read-Host "$prompt [$default]" } else { $ans = Read-Host $prompt }
    if ([string]::IsNullOrWhiteSpace($ans)) { return $default }
    return $ans
}

function Read-YesNo([string]$prompt, [bool]$default) {
    $label = if ($default) { 'J/n' } else { 'j/N' }
    $ans = Read-Host "$prompt [$label]"
    if ([string]::IsNullOrWhiteSpace($ans)) { return $default }
    return ($ans -match '^(j|J|y|Y|ja|yes|true|1)$')
}

# ── Abfrage ─────────────────────────────────────────────────
Write-Host '-- Instanz-Identifikation (pro Installation eindeutig!) --'
Write-Host 'Wenn mehrere Instanzen auf einem Server laufen, muessen'
Write-Host 'diese drei Werte fuer jede Instanz verschieden sein.'
Write-Host ''
$defProject = if ($defaults.COMPOSE_PROJECT_NAME) { $defaults.COMPOSE_PROJECT_NAME } else { 'baukalkulation' }
$defAppName = if ($defaults.APP_NAME) { $defaults.APP_NAME } else { 'baukalkulation-app' }
$COMPOSE_PROJECT_NAME = Read-WithDefault 'Projektname (z.B. baukalkulation-es)' $defProject
$APP_NAME             = Read-WithDefault 'Container-Name (z.B. baukalkulation-es-app)' $defAppName
Write-Host ''
Write-Host '-- Ports ----------------------------------------------'
$APP_PORT = Read-WithDefault 'App-Port (HTTP)' $defaults.APP_PORT

Write-Host ''
Write-Host '-- Module ---------------------------------------------'
Write-Host 'OCR = Tesseract + poppler. Erkennt Text in Scans/Fotos.'
Write-Host '     Kosten: ~+200 MB Image, ~+200 MB RAM, hohe CPU-Last.'
Write-Host '     Fuer NAS < 4 GB RAM: nicht empfohlen.'
$ENABLE_OCR = (Read-YesNo 'OCR aktivieren?' ($defaults.ENABLE_OCR -eq 'true')).ToString().ToLower()

Write-Host ''
Write-Host 'WhatsApp-Bridge = Benachrichtigungen via WhatsApp.'
Write-Host '     Kosten: ~400-500 MB RAM dauerhaft (eigener Container).'
$ENABLE_WHATSAPP = (Read-YesNo 'WhatsApp-Bridge aktivieren?' ($defaults.ENABLE_WHATSAPP -eq 'true')).ToString().ToLower()

$WA_PORT      = $defaults.WA_PORT
$WA_API_TOKEN = $defaults.WA_API_TOKEN
if ($ENABLE_WHATSAPP -eq 'true') {
    $WA_PORT = Read-WithDefault 'WhatsApp-Port' $defaults.WA_PORT
    if ([string]::IsNullOrEmpty($WA_API_TOKEN) -or $WA_API_TOKEN -eq 'change-me') {
        $bytes = [byte[]]::new(16)
        [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        $WA_API_TOKEN = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
    }
    $WA_API_TOKEN = Read-WithDefault 'WhatsApp-API-Token' $WA_API_TOKEN
}

# ── .env schreiben ──────────────────────────────────────────
$envContent = @"
# Automatisch erzeugt von install.ps1 am $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
APP_NAME=$APP_NAME
APP_PORT=$APP_PORT
WA_PORT=$WA_PORT
ENABLE_OCR=$ENABLE_OCR
ENABLE_WHATSAPP=$ENABLE_WHATSAPP
WA_API_TOKEN=$WA_API_TOKEN
"@
Set-Content -Path $envFile -Value $envContent -Encoding UTF8

Write-Host ''
Write-Host '-- Zusammenfassung ------------------------------------'
Write-Host "  Projektname:      $COMPOSE_PROJECT_NAME"
Write-Host "  Container-Name:   $APP_NAME"
Write-Host "  App-Port:         $APP_PORT"
Write-Host "  OCR:              $ENABLE_OCR"
Write-Host "  WhatsApp:         $ENABLE_WHATSAPP"
if ($ENABLE_WHATSAPP -eq 'true') { Write-Host "  WhatsApp-Port:    $WA_PORT" }
Write-Host "  .env geschrieben: $envFile"
Write-Host ''

# ── Docker pruefen ──────────────────────────────────────────
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "'docker' nicht gefunden. Bitte Docker Desktop installieren."
    exit 1
}

$startNow = Read-YesNo 'Jetzt bauen und starten?' $true
if (-not $startNow) {
    Write-Host 'Abbruch. Spaeter starten mit:'
    Write-Host '  docker compose up -d --build'
    if ($ENABLE_WHATSAPP -eq 'true') {
        Write-Host '  docker compose --profile whatsapp up -d --build'
    }
    exit 0
}

Write-Host ''
Write-Host '-- Build laeuft ---------------------------------------'
if ($ENABLE_WHATSAPP -eq 'true') {
    docker compose --profile whatsapp up -d --build
} else {
    docker compose up -d --build
}

Write-Host ''
Write-Host '============================================================'
Write-Host '  Fertig. App erreichbar unter:'
Write-Host "    http://<server-ip>:$APP_PORT"
Write-Host '  Standard-Login: admin / admin  (bitte sofort aendern!)'
Write-Host '============================================================'
