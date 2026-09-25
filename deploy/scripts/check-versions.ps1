# ============================================================
# check-versions.ps1 – Cache-Busting-Versions-Abgleich (lokal)
# ============================================================
# PowerShell-Variante von scripts/check-versions.mjs für die
# lokale Windows-Umgebung (ohne Node.js). Gleiche Prüfung:
#   A) index.html <-> sw.js : style.css / script.js / dist/core.js ?v=
#   B) script.js  <-> mobile.html : modules/<mod>/<mod>.js ?v=
#   C) Info       : sw.js CACHE_VERSION
#
# Verhalten: standardmäßig nur WARNEN (Exit 0). Mit -Strict → Exit 1.
# Aufruf:    pwsh deploy\deploy\scripts\check-versions.ps1
#            (oder über die VS-Code-Task "Versions-Check")
# WICHTIG: Bei jeder Asset-Änderung ?v= in index.html UND sw.js
#          erhöhen und sw.js CACHE_VERSION bumpen.
# ============================================================
param([switch]$Strict)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot   # -> deploy\deploy
$problems = New-Object System.Collections.Generic.List[string]

function Read-File($rel) {
    $p = Join-Path $root $rel
    if (-not (Test-Path $p)) { $problems.Add("Datei nicht lesbar: $rel"); return '' }
    return Get-Content $p -Raw
}

function Get-Versions($text, $assetPath) {
    $esc = [regex]::Escape($assetPath)
    [regex]::Matches($text, "$esc\?v=(\d+)") | ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
}

$indexHtml  = Read-File 'index.html'
$swJs       = Read-File 'sw.js'
$scriptJs   = Read-File 'script.js'
$mobileHtml = Read-File 'mobile.html'

# ── A) Asset-Versionen: index.html <-> sw.js ────────────────
foreach ($asset in @('style.css', 'script.js', 'dist/core.js')) {
    $inIndex = @(Get-Versions $indexHtml $asset)
    $inSw    = @(Get-Versions $swJs $asset)

    if ($inIndex.Count -eq 0) { $problems.Add("A) ${asset}: keine ?v= in index.html gefunden."); continue }
    if ($inSw.Count -eq 0)    { $problems.Add("A) ${asset}: keine ?v= in sw.js (PRECACHE_URLS) gefunden."); continue }
    if ($inIndex.Count -gt 1) { $problems.Add("A) ${asset}: mehrere Versionen in index.html: $($inIndex -join ', ')") }
    if ($inSw.Count -gt 1)    { $problems.Add("A) ${asset}: mehrere Versionen in sw.js: $($inSw -join ', ')") }

    if ($inIndex[0] -and $inSw[0] -and $inIndex[0] -ne $inSw[0]) {
        $problems.Add("A) ${asset}: index.html=v$($inIndex[0]) <-> sw.js=v$($inSw[0]) (DRIFT! sw.js angleichen + CACHE_VERSION erhoehen)")
    }
}

# ── B) Modul-Versionen: script.js <-> mobile.html ───────────
function Get-ModuleVersions($text) {
    $map = @{}
    foreach ($mm in [regex]::Matches($text, 'modules/([a-z0-9_]+)/\1\.(js|css)\?v=(\d+)', 'IgnoreCase')) {
        $mod = $mm.Groups[1].Value; $ext = $mm.Groups[2].Value.ToLower(); $ver = $mm.Groups[3].Value
        if (-not $map.ContainsKey($mod)) { $map[$mod] = @{ js = @(); css = @() } }
        $map[$mod][$ext] = @($map[$mod][$ext] + $ver | Select-Object -Unique)
    }
    return $map
}

$modScript = Get-ModuleVersions $scriptJs
$modMobile = Get-ModuleVersions $mobileHtml
$allMods   = @($modScript.Keys + $modMobile.Keys | Select-Object -Unique | Sort-Object)

foreach ($mod in $allMods) {
    $s  = $modScript[$mod]
    $mo = $modMobile[$mod]

    foreach ($pair in @(@('script.js', $s), @('mobile.html', $mo))) {
        $label = $pair[0]; $src = $pair[1]
        if (-not $src) { continue }
        if ($src.js[0] -and $src.css[0] -and $src.js[0] -ne $src.css[0]) {
            $problems.Add("B) $mod ($label): js=v$($src.js[0]) <-> css=v$($src.css[0]) (js/css unterschiedlich)")
        }
    }

    if (-not $s)  { $problems.Add("B) ${mod}: nur in mobile.html, fehlt in script.js."); continue }
    if (-not $mo) { continue }  # evtl. nur Desktop – kein Fehler

    if ($s.js[0] -and $mo.js[0] -and $s.js[0] -ne $mo.js[0]) {
        $problems.Add("B) ${mod}: script.js=v$($s.js[0]) <-> mobile.html=v$($mo.js[0]) (DRIFT! Modul-Versionen angleichen)")
    }
}

# ── C) Info + Ausgabe ───────────────────────────────────────
$tag = '[check-versions]'
$cacheVer = [regex]::Match($swJs, "CACHE_VERSION\s*=\s*['""]([^'""]+)['""]").Groups[1].Value
if ($cacheVer) { Write-Host "$tag sw.js CACHE_VERSION = $cacheVer" }

if ($problems.Count -eq 0) {
    Write-Host "$tag OK - alle ?v=-Versionen sind konsistent." -ForegroundColor Green
    exit 0
}

$head = if ($Strict) { 'FEHLER' } else { 'WARNUNG' }
$color = if ($Strict) { 'Red' } else { 'Yellow' }
Write-Host "$tag ${head}: $($problems.Count) Versions-Problem(e) gefunden:" -ForegroundColor $color
foreach ($p in $problems) { Write-Host "  - $p" -ForegroundColor $color }
Write-Host "$tag Bei Asset-Aenderung: ?v= in index.html UND sw.js erhoehen und sw.js CACHE_VERSION bumpen."

if ($Strict) { exit 1 } else { exit 0 }
