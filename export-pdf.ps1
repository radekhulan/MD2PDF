<#
.SYNOPSIS
    Markdown -> PDF export (md2pdf engine) — one-shot runner.

.DESCRIPTION
    Sdileny engine. Prevede zdrojove .md (dle 'glob' v configu) na PDF pomoci
    md2pdf.php (mPDF). Vsechny konstanty/texty/cesty si bere z configu predaneho
    pres -Config. Volitelne vyrenderuje nahledove PNG vsech stranek (GhostScript).

    Zdrojove .md jsou READ-ONLY (nemodifikuji se).

.PARAMETER Config
    Cesta k md2pdf.config.php daneho projektu. Kdyz neni zadana, pouzije se
    md2pdf.config.php vedle tohoto skriptu (pokud existuje).

.PARAMETER Preview
    Po exportu vyrenderuje PNG nahledy vsech stranek (GhostScript).

.PARAMETER Only
    Prevede jen jeden dokument (basename, napr. "MujDokument").

.PARAMETER Renderer
    Prebije renderer z configu: 'mpdf' (cisty PHP) nebo 'chrome' (headless
    Chrome/Edge + GhostScript, vektorovy mermaid). Kdyz neni zadan, pouzije se
    'renderer' z md2pdf.config.php.

.EXAMPLE
    pwsh -File export-pdf.ps1 -Config C:\projekt\tools\md2pdf.config.php
    pwsh -File export-pdf.ps1 -Config ...\md2pdf.config.php -Preview
    pwsh -File export-pdf.ps1 -Config ...\md2pdf.config.php -Only MujDokument
    pwsh -File export-pdf.ps1 -Config ...\md2pdf.config.php -Renderer chrome
#>
[CmdletBinding()]
param(
    [string]$Config,
    [switch]$Preview,
    [string]$Only,
    [ValidateSet('mpdf', 'chrome')]
    [string]$Renderer
)

$ErrorActionPreference = 'Stop'

# --- Cesty -----------------------------------------------------------------
$ToolsDir = $PSScriptRoot
$Script   = Join-Path $ToolsDir 'md2pdf.php'

if (-not (Test-Path $Script)) { throw "Chybi $Script" }

# --- Config ----------------------------------------------------------------
if (-not $Config) {
    $def = Join-Path $ToolsDir 'md2pdf.config.php'
    if (Test-Path $def) {
        $Config = $def
    } else {
        throw "Chybi -Config <cesta k md2pdf.config.php>. Vzor: $ToolsDir\md2pdf.config.sample.php"
    }
}
if (-not (Test-Path $Config)) { throw "Config nenalezen: $Config" }
$Config = (Resolve-Path $Config).Path

# --- Najdi php.exe ---------------------------------------------------------
$Php = $null
foreach ($cand in @('c:\inetpub\php\php.exe', 'C:\Program Files\PHP\php.exe', 'c:\php\php.exe')) {
    if (Test-Path $cand) { $Php = $cand; break }
}
if (-not $Php) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $Php = $cmd.Source }
}
if (-not $Php) { throw "php.exe nenalezen (zkuste c:\inetpub\php\php.exe)." }

# --- Zajisti vendor (mPDF) -------------------------------------------------
if (-not (Test-Path (Join-Path $ToolsDir 'vendor\autoload.php'))) {
    Write-Host "vendor\ chybi - spoustim composer install..." -ForegroundColor Yellow
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if (-not $composer) { throw "vendor\ chybi a composer neni v PATH. Spustte: composer install (v $ToolsDir)." }
    Push-Location $ToolsDir
    try { & $composer.Source install --no-interaction --no-progress --no-dev }
    finally { Pop-Location }
}

# --- Spust export ----------------------------------------------------------
Write-Host "PHP:    $Php"
Write-Host "Script: $Script"
Write-Host "Config: $Config"
Write-Host ""

$phpArgs = @($Script, "--config=$Config")
if ($Renderer) { $phpArgs += "--renderer=$Renderer" }
if ($Only)     { $phpArgs += $Only }

& $Php @phpArgs
$exit = $LASTEXITCODE
if ($exit -ne 0) { throw "md2pdf.php skoncil s chybou (exit $exit)." }

# --- Volitelne: render nahledu (GhostScript) -------------------------------
if ($Preview) {
    Write-Host ""
    Write-Host "Renderuji PNG nahledy..." -ForegroundColor Cyan

    # zjisti realny vystupni adresar z configu (respektuje source_dir/output_dir)
    $json = & $Php $Script "--config=$Config" '--print-config'
    if ($LASTEXITCODE -ne 0) { throw "Nelze nacist konfiguraci pres --print-config." }
    $cfg     = $json | ConvertFrom-Json
    $OutDir  = $cfg.output_dir
    $PrevDir = Join-Path $OutDir '_preview'

    $gs = $null
    foreach ($cand in @('C:\inetpub\GhostScript\bin\gswin64c.exe',
                        'C:\Program Files\gs\gs10.07.1\bin\gswin64c.exe')) {
        if (Test-Path $cand) { $gs = $cand; break }
    }
    if (-not $gs) {
        $cmd = Get-Command gswin64c -ErrorAction SilentlyContinue
        if ($cmd) { $gs = $cmd.Source }
    }
    if (-not $gs) {
        Write-Warning "GhostScript (gswin64c.exe) nenalezen - nahledy preskoceny."
    } else {
        New-Item -ItemType Directory -Force -Path $PrevDir | Out-Null
        # smaz stare nahledy
        Get-ChildItem $PrevDir -Filter '*.png' -ErrorAction SilentlyContinue | Remove-Item -Force
        $pdfs = Get-ChildItem $OutDir -Filter '*.pdf'
        if ($Only) { $pdfs = $pdfs | Where-Object { $_.BaseName -eq $Only } }
        foreach ($pdf in $pdfs) {
            $outPat = Join-Path $PrevDir ("{0}-p%02d.png" -f $pdf.BaseName)
            & $gs -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r110 `
                  -dTextAlphaBits=4 -dGraphicsAlphaBits=4 `
                  -o $outPat $pdf.FullName | Out-Null
            Write-Host ("  nahled: {0}" -f $pdf.BaseName)
        }
        $n = (Get-ChildItem $PrevDir -Filter '*.png').Count
        Write-Host ("Hotovo: {0} PNG v {1}" -f $n, $PrevDir) -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "HOTOVO." -ForegroundColor Green
