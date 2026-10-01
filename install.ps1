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
    BK_UPDATE_REPO       = ''
    APP_IMAGE_TAG        = ''
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
Write-Host 'Mehrere Instanzen auf einem Server brauchen je einen eigenen'
Write-Host 'Projektnamen und Port. Container-Namen werden daraus abgeleitet.'
Write-Host ''
# Liegt die App in "...\<mandant>\Baukalkulation", zählt der Mandantenordner.
$folder = Split-Path $PSScriptRoot -Leaf
if ($folder -ieq 'baukalkulation') { $folder = Split-Path (Split-Path $PSScriptRoot -Parent) -Leaf }
$slug = ($folder.ToLower() -replace '[^a-z0-9]+', '-').Trim('-')
$folderProject = if (-not $slug -or $slug.StartsWith('baukalkulation')) { if ($slug) { $slug } else { 'baukalkulation' } } else { "baukalkulation-$slug" }
$defProject = if ($defaults.COMPOSE_PROJECT_NAME) { $defaults.COMPOSE_PROJECT_NAME } else { $folderProject }
do {
    $COMPOSE_PROJECT_NAME = Read-WithDefault 'Projektname (Kleinbuchstaben, z.B. baukalkulation-es)' $defProject
} until ($COMPOSE_PROJECT_NAME -cmatch '^[a-z0-9][a-z0-9_-]*$')
$defAppName = if ($defaults.APP_NAME -and $defaults.APP_NAME.StartsWith($COMPOSE_PROJECT_NAME)) { $defaults.APP_NAME } else { "$COMPOSE_PROJECT_NAME-app" }
$APP_NAME = Read-WithDefault 'Container-Name' $defAppName
$AUTOHEAL_NAME = if ($defaults.AUTOHEAL_NAME -and $defaults.AUTOHEAL_NAME.StartsWith($COMPOSE_PROJECT_NAME)) { $defaults.AUTOHEAL_NAME } else { "$COMPOSE_PROJECT_NAME-autoheal" }
# Label darf nicht "autoheal" bleiben, sonst startet jeder Autoheal auch die Apps der anderen Instanzen neu.
$AUTOHEAL_LABEL = if ($defaults.AUTOHEAL_LABEL -and $defaults.AUTOHEAL_LABEL -ne 'autoheal') { $defaults.AUTOHEAL_LABEL } else { 'autoheal_' + ($COMPOSE_PROJECT_NAME -replace '-', '_') }
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

Write-Host ''
Write-Host '-- Update ---------------------------------------------'
Write-Host 'GitHub-Repository fuer Release-Pruefung und Update (owner/name), leer = aus.'
do {
    $BK_UPDATE_REPO = (Read-WithDefault 'Update-Repository' $defaults.BK_UPDATE_REPO).Trim().ToLower()
    $repoOk = (-not $BK_UPDATE_REPO) -or ($BK_UPDATE_REPO -cmatch '^[a-z0-9-]+/[a-z0-9_-][a-z0-9._-]*$' -and -not $BK_UPDATE_REPO.Contains('..'))
    if (-not $repoOk) { Write-Host "Format: owner/name (Buchstaben, Ziffern, '-', '_', '.')." }
} until ($repoOk)

# Tag nie unter einen bereits vom Updater gesetzten neueren Stand senken.
$APP_IMAGE_TAG = (Get-Content (Join-Path $PSScriptRoot 'VERSION') -Raw).Trim()
if ($defaults.APP_IMAGE_TAG -match '^\d+\.\d+\.\d+$' -and $APP_IMAGE_TAG -match '^\d+\.\d+\.\d+$' -and [version]$defaults.APP_IMAGE_TAG -gt [version]$APP_IMAGE_TAG) {
    $APP_IMAGE_TAG = $defaults.APP_IMAGE_TAG
}
$APP_IMAGE_VARIANT = if ($ENABLE_OCR -eq 'true') { '' } else { '-noocr' }
$BK_COMPOSE_DIR = $PSScriptRoot

# ── .env schreiben (übrige Einträge wie BK_*-Variablen bleiben erhalten) ──
$values = [ordered]@{
    COMPOSE_PROJECT_NAME = $COMPOSE_PROJECT_NAME
    APP_NAME             = $APP_NAME
    AUTOHEAL_NAME        = $AUTOHEAL_NAME
    AUTOHEAL_LABEL       = $AUTOHEAL_LABEL
    APP_PORT             = $APP_PORT
    WA_PORT              = $WA_PORT
    ENABLE_OCR           = $ENABLE_OCR
    ENABLE_WHATSAPP      = $ENABLE_WHATSAPP
    WA_API_TOKEN         = $WA_API_TOKEN
    BK_COMPOSE_DIR       = $BK_COMPOSE_DIR
    BK_UPDATE_REPO       = $BK_UPDATE_REPO
    APP_IMAGE_TAG        = $APP_IMAGE_TAG
    APP_IMAGE_VARIANT    = $APP_IMAGE_VARIANT
}
$lines = if (Test-Path $envFile) { @(Get-Content $envFile) } elseif (Test-Path (Join-Path $PSScriptRoot '.env.example')) { @(Get-Content (Join-Path $PSScriptRoot '.env.example')) } else { @() }
$seen = @{}
$lines = foreach ($line in $lines) {
    if ($line -match '^\s*([A-Z_][A-Z0-9_]*)\s*=' -and $values.Contains($matches[1])) {
        if (-not $seen[$matches[1]]) { "$($matches[1])=$($values[$matches[1]])" }
        $seen[$matches[1]] = $true
    } else { $line }
}
foreach ($key in $values.Keys) { if (-not $seen[$key]) { $lines += "$key=$($values[$key])" } }
[System.IO.File]::WriteAllLines($envFile, [string[]]$lines, (New-Object System.Text.UTF8Encoding $false))

Write-Host ''
Write-Host '-- Zusammenfassung ------------------------------------'
Write-Host "  Projektname:      $COMPOSE_PROJECT_NAME"
Write-Host "  Container-Name:   $APP_NAME"
Write-Host "  Autoheal:         $AUTOHEAL_NAME (Label $AUTOHEAL_LABEL)"
Write-Host "  App-Port:         $APP_PORT"
Write-Host "  OCR:              $ENABLE_OCR"
Write-Host "  WhatsApp:         $ENABLE_WHATSAPP"
if ($ENABLE_WHATSAPP -eq 'true') { Write-Host "  WhatsApp-Port:    $WA_PORT" }
Write-Host "  Update-Repo:      $(if ($BK_UPDATE_REPO) { $BK_UPDATE_REPO } else { '(aus)' })"
Write-Host "  App-Image-Tag:    $APP_IMAGE_TAG$APP_IMAGE_VARIANT"
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
