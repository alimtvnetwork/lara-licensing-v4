#Requires -Version 7.0
<#
.SYNOPSIS
    Root release orchestrator: builds frontend + backend cPanel zips.

.DESCRIPTION
    Plan 09 step 75. Reads the version from package.json (single source of
    truth per check-version-sync.py), invokes:
        scripts/publish-frontend.ps1 -Version <v>
        scripts/publish-backend.ps1  -Version <v>
    in that order, then verifies both zips exist and prints SHA-256 for
    each. Emits a checksums.txt suitable for uploading as a release asset.

    Silent failure is banned: any child failure aborts with the child's
    exit code; missing artifacts abort with a distinct code.

.PARAMETER SkipFrontend
    Skip the frontend build (rezip / debug flow).

.PARAMETER SkipBackend
    Skip the backend build (rezip / debug flow).

.PARAMETER SkipInstall
    Passed through to publish-frontend.ps1 (bun install skipped).

.PARAMETER SkipComposerInstall
    Passed through to publish-backend.ps1.

.NOTES
    Exit codes:
      0  success
      30 ERR_ORCH_VERSION_MISSING
      31 ERR_ORCH_FRONTEND_FAILED (uses child code -> re-thrown)
      32 ERR_ORCH_BACKEND_FAILED  (uses child code -> re-thrown)
      33 ERR_ORCH_ARTIFACT_MISSING
#>

[CmdletBinding()]
param(
    [switch] $SkipFrontend,
    [switch] $SkipBackend,
    [switch] $SkipInstall,
    [switch] $SkipComposerInstall
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Err([int] $Code, [string] $Message) {
    Write-Host "ERROR [$Code]: $Message" -ForegroundColor Red
    exit $Code
}
function Write-Info([string] $Message) {
    Write-Host "[run] $Message" -ForegroundColor Cyan
}

$RepoRoot = $PSScriptRoot
$pkg = Get-Content (Join-Path $RepoRoot 'package.json') -Raw | ConvertFrom-Json
if (-not $pkg.version) { Write-Err 30 "package.json has no 'version' field" }
$Version = $pkg.version
Write-Info "Version from package.json: $Version"

$ReleaseDir = Join-Path $RepoRoot 'release'
New-Item -ItemType Directory -Path $ReleaseDir -Force | Out-Null

# ---- Frontend ---------------------------------------------------------------
if (-not $SkipFrontend) {
    Write-Info 'Invoking publish-frontend.ps1'
    $feArgs = @('-Version', $Version)
    if ($SkipInstall) { $feArgs += '-SkipInstall' }
    & (Join-Path $RepoRoot 'scripts/publish-frontend.ps1') @feArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Err $LASTEXITCODE "publish-frontend.ps1 failed"
    }
} else {
    Write-Info 'Skipping frontend (SkipFrontend)'
}

# ---- Backend ----------------------------------------------------------------
if (-not $SkipBackend) {
    Write-Info 'Invoking publish-backend.ps1'
    $beArgs = @('-Version', $Version)
    if ($SkipComposerInstall) { $beArgs += '-SkipComposerInstall' }
    & (Join-Path $RepoRoot 'scripts/publish-backend.ps1') @beArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Err $LASTEXITCODE "publish-backend.ps1 failed"
    }
} else {
    Write-Info 'Skipping backend (SkipBackend)'
}

# ---- Verify artifacts + checksums ------------------------------------------
$feZip = Join-Path $ReleaseDir "frontend-v$Version.zip"
$beZip = Join-Path $ReleaseDir "backend-v$Version.zip"
foreach ($p in @($feZip, $beZip)) {
    if (-not (Test-Path $p)) { Write-Err 33 "Missing artifact: $p" }
}

$feSha = (Get-FileHash $feZip -Algorithm SHA256).Hash.ToLowerInvariant()
$beSha = (Get-FileHash $beZip -Algorithm SHA256).Hash.ToLowerInvariant()

# ---- Combined bundle (frontend + backend + DEPLOY.md) ----------------------
$combinedZip = Join-Path $ReleaseDir "licensing-portal-v$Version.zip"
$combinedStage = Join-Path $ReleaseDir 'combined'
$deployMd = Join-Path $RepoRoot 'scripts/cpanel/DEPLOY.md'
if (-not (Test-Path $deployMd)) { Write-Err 33 "scripts/cpanel/DEPLOY.md missing" }
if (Test-Path $combinedStage) { Remove-Item $combinedStage -Recurse -Force }
New-Item -ItemType Directory -Path (Join-Path $combinedStage 'frontend') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $combinedStage 'backend')  -Force | Out-Null
$feStage = Join-Path $ReleaseDir 'frontend'
$beStage = Join-Path $ReleaseDir 'backend'
if (Test-Path $feStage) {
    Copy-Item (Join-Path $feStage '*') (Join-Path $combinedStage 'frontend') -Recurse -Force
} else {
    Expand-Archive -Path $feZip -DestinationPath (Join-Path $combinedStage 'frontend') -Force
}
if (Test-Path $beStage) {
    Copy-Item (Join-Path $beStage '*') (Join-Path $combinedStage 'backend') -Recurse -Force
} else {
    Expand-Archive -Path $beZip -DestinationPath (Join-Path $combinedStage 'backend') -Force
}
Copy-Item $deployMd (Join-Path $combinedStage 'DEPLOY.md') -Force
Set-Content -Path (Join-Path $combinedStage 'checksums.txt') -Encoding ASCII -Value @(
    "$feSha  frontend-v$Version.zip",
    "$beSha  backend-v$Version.zip"
)
if (Test-Path $combinedZip) { Remove-Item $combinedZip -Force }
Compress-Archive -Path (Join-Path $combinedStage '*') -DestinationPath $combinedZip -Force
if (-not (Test-Path $combinedZip)) { Write-Err 41 "Combined zip not produced at $combinedZip" }
$combinedSha = (Get-FileHash $combinedZip -Algorithm SHA256).Hash.ToLowerInvariant()

$checksumsPath = Join-Path $ReleaseDir 'checksums.txt'
Set-Content -Path $checksumsPath -Encoding ASCII -Value @(
    "$feSha  frontend-v$Version.zip",
    "$beSha  backend-v$Version.zip",
    "$combinedSha  licensing-portal-v$Version.zip"
)

Write-Info "OK -> $feZip"
Write-Info "     SHA-256: $feSha"
Write-Info "OK -> $beZip"
Write-Info "     SHA-256: $beSha"
Write-Info "OK -> $combinedZip"
Write-Info "     SHA-256: $combinedSha"
Write-Info "checksums.txt written to $checksumsPath"
Write-Output "VERSION=$Version"
Write-Output "FRONTEND=$feZip"
Write-Output "FRONTEND_SHA256=$feSha"
Write-Output "BACKEND=$beZip"
Write-Output "BACKEND_SHA256=$beSha"
Write-Output "COMBINED=$combinedZip"
Write-Output "COMBINED_SHA256=$combinedSha"
Write-Output "CHECKSUMS=$checksumsPath"
exit 0

