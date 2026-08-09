#Requires -Version 7.0
<#
.SYNOPSIS
    Builds the Licensing Portal frontend and emits a cPanel-ready zip.

.DESCRIPTION
    Plan 09 step 72. Deterministic release pipeline:
      1. bun install --frozen-lockfile
      2. bun run build (Vite -> dist/)
      3. Copy dist/ + scripts/cpanel/.htaccess into release/frontend/
      4. Zip to release/frontend-v<Version>.zip
      5. Print SHA-256

    Silent failure is banned: every failure path logs
    ERROR [<code>]: <message> in red and exits with a non-zero code.

.PARAMETER Version
    Semver version. Must match package.json "version" (verified).

.PARAMETER SkipInstall
    Skip `bun install`. Use when the runner already restored node_modules.

.PARAMETER SkipBuild
    Skip `bun run build`. Only useful for rezipping an existing dist/.

.NOTES
    Exit codes:
      0  success
      10 ERR_FE_VERSION_MISMATCH   (package.json disagrees with -Version)
      11 ERR_FE_BUN_MISSING        (bun not on PATH)
      12 ERR_FE_INSTALL_FAILED
      13 ERR_FE_BUILD_FAILED
      14 ERR_FE_DIST_MISSING       (dist/ absent or empty after build)
      15 ERR_FE_HTACCESS_MISSING   (scripts/cpanel/.htaccess absent)
      16 ERR_FE_ZIP_FAILED
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$')]
    [string] $Version,

    [switch] $SkipInstall,
    [switch] $SkipBuild
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Err([int] $Code, [string] $Message) {
    Write-Host "ERROR [$Code]: $Message" -ForegroundColor Red
    exit $Code
}

function Write-Info([string] $Message) {
    Write-Host "[publish-frontend] $Message" -ForegroundColor Cyan
}

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Push-Location $RepoRoot
try {
    # ---- 1. Verify version matches package.json ----------------------------
    $pkgPath = Join-Path $RepoRoot 'package.json'
    $pkg = Get-Content $pkgPath -Raw | ConvertFrom-Json
    if ($pkg.version -ne $Version) {
        Write-Err 10 "package.json version '$($pkg.version)' does not match -Version '$Version'"
    }
    Write-Info "Version pinned: $Version"

    # ---- 2. bun on PATH ----------------------------------------------------
    if (-not (Get-Command bun -ErrorAction SilentlyContinue)) {
        Write-Err 11 "bun is not on PATH. Install from https://bun.sh"
    }

    # ---- 3. Install --------------------------------------------------------
    if (-not $SkipInstall) {
        Write-Info 'bun install --frozen-lockfile'
        & bun install --frozen-lockfile
        if ($LASTEXITCODE -ne 0) { Write-Err 12 "bun install failed (exit $LASTEXITCODE)" }
    } else {
        Write-Info 'Skipping install (SkipInstall)'
    }

    # ---- 4. Build ----------------------------------------------------------
    if (-not $SkipBuild) {
        Write-Info 'bun run build'
        & bun run build
        if ($LASTEXITCODE -ne 0) { Write-Err 13 "bun run build failed (exit $LASTEXITCODE)" }
    } else {
        Write-Info 'Skipping build (SkipBuild)'
    }

    $distDir = Join-Path $RepoRoot 'dist'
    if (-not (Test-Path $distDir)) { Write-Err 14 "dist/ missing after build" }
    if (-not (Get-ChildItem $distDir -Recurse -File | Select-Object -First 1)) {
        Write-Err 14 "dist/ is empty after build"
    }

    # ---- 5. Stage release/frontend/ ---------------------------------------
    $releaseRoot = Join-Path $RepoRoot 'release'
    $stageDir = Join-Path $releaseRoot 'frontend'
    if (Test-Path $stageDir) { Remove-Item $stageDir -Recurse -Force }
    New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

    Write-Info "Copying dist/ -> release/frontend/"
    Copy-Item (Join-Path $distDir '*') $stageDir -Recurse -Force

    $htaccessSrc = Join-Path $RepoRoot 'scripts/cpanel/.htaccess'
    if (-not (Test-Path $htaccessSrc)) { Write-Err 15 "scripts/cpanel/.htaccess not found" }
    Copy-Item $htaccessSrc (Join-Path $stageDir '.htaccess') -Force
    Write-Info "Copied .htaccess (SPA fallback + cache headers)"

    # ---- 6. Zip -----------------------------------------------------------
    $zipPath = Join-Path $releaseRoot "frontend-v$Version.zip"
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

    try {
        Compress-Archive -Path (Join-Path $stageDir '*') -DestinationPath $zipPath -Force
    } catch {
        Write-Err 16 "Compress-Archive failed: $($_.Exception.Message)"
    }
    if (-not (Test-Path $zipPath)) { Write-Err 16 "Zip not produced at $zipPath" }

    $sha = (Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $size = (Get-Item $zipPath).Length

    Write-Info "OK -> $zipPath"
    Write-Info "SHA-256: $sha"
    Write-Info "Size:    $size bytes"

    # Machine-readable trailer for CI parsing
    Write-Output "ARTIFACT=$zipPath"
    Write-Output "SHA256=$sha"
    Write-Output "SIZE=$size"
    exit 0
}
finally {
    Pop-Location
}
