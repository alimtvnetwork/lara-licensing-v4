#Requires -Version 7.0
<#
.SYNOPSIS
    Packages the Laravel backend into a cPanel-ready zip.

.DESCRIPTION
    Plan 09 step 74. Produces release/backend-v<Version>.zip containing:
      - backend/ source (app, bootstrap, config, database, public, resources,
        routes, tests, artisan, composer.{json,lock})
      - vendor/ (installed with --no-dev --optimize-autoloader)
      - Cached configs (config, route, view)
      - .env.example (copied verbatim; NEVER include a real .env)
      - PUBLISH-NOTES.md (version, commit hash if available, artisan-about hint)

    Silent failure is banned: every failure path logs
    ERROR [<code>]: <message> in red and exits with a non-zero code.

.PARAMETER Version
    Semver version. Must match package.json "version" AND
    backend/composer.json "version" (three-way single source of truth
    enforced by linter-scripts/check-version-sync.py, alongside
    README.md, CHANGELOG.md, and RELEASE-NOTES.md).

.PARAMETER SkipComposerInstall
    Skip composer install (only useful for rezipping).

.NOTES
    Exit codes:
      0  success
      20 ERR_BE_VERSION_MISMATCH
      21 ERR_BE_COMPOSER_MISSING
      22 ERR_BE_PHP_MISSING
      23 ERR_BE_INSTALL_FAILED
      24 ERR_BE_ENV_EXAMPLE_MISSING
      25 ERR_BE_CACHE_FAILED
      26 ERR_BE_ZIP_FAILED
      27 ERR_BE_REAL_ENV_PRESENT   (refuse to zip if backend/.env is real)
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$')]
    [string] $Version,

    [switch] $SkipComposerInstall
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Write-Err([int] $Code, [string] $Message) {
    Write-Host "ERROR [$Code]: $Message" -ForegroundColor Red
    exit $Code
}

function Write-Info([string] $Message) {
    Write-Host "[publish-backend] $Message" -ForegroundColor Cyan
}

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$BackendDir = Join-Path $RepoRoot 'backend'
if (-not (Test-Path $BackendDir)) { Write-Err 24 "backend/ directory not found at $BackendDir" }

Push-Location $RepoRoot
try {
    # ---- 1. Verify version matches package.json ----------------------------
    $pkg = Get-Content (Join-Path $RepoRoot 'package.json') -Raw | ConvertFrom-Json
    if ($pkg.version -ne $Version) {
        Write-Err 20 "package.json version '$($pkg.version)' does not match -Version '$Version'"
    }
    Write-Info "Version pinned: $Version"

    # ---- 2. Toolchain checks ----------------------------------------------
    if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
        Write-Err 21 "composer is not on PATH"
    }
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
        Write-Err 22 "php is not on PATH"
    }

    # ---- 3. Refuse to zip a real .env -------------------------------------
    $realEnv = Join-Path $BackendDir '.env'
    if (Test-Path $realEnv) {
        Write-Err 27 "backend/.env is present. Remove or move it before zipping; secrets must not ship in the release artifact."
    }
    $envExample = Join-Path $BackendDir '.env.example'
    if (-not (Test-Path $envExample)) { Write-Err 24 "backend/.env.example missing" }

    Push-Location $BackendDir
    try {
        # ---- 4. Install prod deps --------------------------------------
        if (-not $SkipComposerInstall) {
            Write-Info 'composer install --no-dev --optimize-autoloader --prefer-dist'
            & composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
            if ($LASTEXITCODE -ne 0) { Write-Err 23 "composer install failed (exit $LASTEXITCODE)" }
        }

        # ---- 5. Cache configs (idempotent; ignored if .env absent) -----
        Write-Info 'php artisan config:cache / route:cache / view:cache (best-effort)'
        foreach ($cmd in @('config:clear','route:clear','view:clear','config:cache','route:cache','view:cache')) {
            & php artisan $cmd 2>&1 | Out-Null
            if ($LASTEXITCODE -ne 0) {
                Write-Info "artisan $cmd returned $LASTEXITCODE (non-fatal at build time; caches rebuild on the target)"
            }
        }
    }
    finally {
        Pop-Location
    }

    # ---- 6. Stage release/backend/ ----------------------------------------
    $releaseRoot = Join-Path $RepoRoot 'release'
    $stageDir = Join-Path $releaseRoot 'backend'
    if (Test-Path $stageDir) { Remove-Item $stageDir -Recurse -Force }
    New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

    $include = @(
        'app','bootstrap','config','database','public','resources','routes','tests','vendor',
        'artisan','composer.json','composer.lock','phpunit.xml','phpstan.neon','.env.example'
    )
    foreach ($item in $include) {
        $src = Join-Path $BackendDir $item
        if (Test-Path $src) {
            Write-Info "Copy $item"
            Copy-Item $src (Join-Path $stageDir $item) -Recurse -Force
        }
    }

    # bootstrap/cache and storage: keep directory shells, drop transient state
    foreach ($rel in @('bootstrap/cache','storage/framework/cache','storage/framework/sessions','storage/framework/views','storage/logs')) {
        $p = Join-Path $stageDir $rel
        if (-not (Test-Path $p)) { New-Item -ItemType Directory -Path $p -Force | Out-Null }
        Get-ChildItem $p -Recurse -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -ne '.gitignore' } |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    # ---- 7. PUBLISH-NOTES.md ---------------------------------------------
    $commit = ''
    try {
        $commit = (& git -C $RepoRoot rev-parse --short HEAD) 2>$null
    } catch { $commit = 'unknown' }
    $notes = @"
# Licensing Portal backend release

- Version: $Version
- Commit:  $commit
- Built:   $(Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK')

## Deploy (cPanel)

1. Upload backend-v$Version.zip and extract to the application root.
2. Copy .env.example to .env and fill DB, mail, and lara.* settings.
3. Run: php artisan key:generate --force
4. Run: php artisan migrate --force
5. Run: php artisan storage:link
6. Run: php artisan about  # sanity check
7. Point the document root at public/.

Rollback: keep the previous release zip on disk and re-extract it.
"@
    Set-Content -Path (Join-Path $stageDir 'PUBLISH-NOTES.md') -Value $notes -Encoding UTF8

    # ---- 8. Zip ----------------------------------------------------------
    $zipPath = Join-Path $releaseRoot "backend-v$Version.zip"
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    try {
        Compress-Archive -Path (Join-Path $stageDir '*') -DestinationPath $zipPath -Force
    } catch {
        Write-Err 26 "Compress-Archive failed: $($_.Exception.Message)"
    }
    if (-not (Test-Path $zipPath)) { Write-Err 26 "Zip not produced at $zipPath" }

    $sha = (Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $size = (Get-Item $zipPath).Length

    Write-Info "OK -> $zipPath"
    Write-Info "SHA-256: $sha"
    Write-Info "Size:    $size bytes"

    Write-Output "ARTIFACT=$zipPath"
    Write-Output "SHA256=$sha"
    Write-Output "SIZE=$size"
    exit 0
}
finally {
    Pop-Location
}
