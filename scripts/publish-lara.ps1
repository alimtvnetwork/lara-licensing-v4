#Requires -Version 7.0
<#
.SYNOPSIS
    Publishes Lara CLI assets to the Lara self-update host.

.DESCRIPTION
    Deliverable form of spec/21-app/18-publishing-powershell.md. Implements the
    two-phase upload contract from spec/21-app/17-self-update-endpoint.md:
      1. POST /Admin/AppUpdates/UploadTicket per platform (reserves an upload
         slot, returns { UploadToken, UploadUrl, ExpiresAt }).
      2. PUT {UploadUrl} with header X-Sha256 for each asset.
      3. POST /Admin/AppUpdates to materialize the manifest row.
      4. Optional GET /App/UpdateManifest verification when -Verify is set.

    Silent failure is banned (spec/03-error-manage/); every failure path logs
    ERROR [<code>]: <message> in red and exits with the top-level code from
    the 9560-9569 reserved range.

.NOTES
    Exit code map (top-level in parentheses):
      9560 ERR_PUBLISH_CONFIG_MISSING    (5)
      9561 ERR_PUBLISH_TOKEN_MISSING     (6)
      9562 ERR_PUBLISH_ASSET_MISSING     (7)
      9563 ERR_PUBLISH_CHECKSUM_FAILED   (8)
      9564 ERR_PUBLISH_UPLOAD_FAILED     (8)
      9565 ERR_PUBLISH_MANIFEST_FAILED   (4)
      9566 ERR_PUBLISH_VERIFY_FAILED     (3)
      9567 ERR_PUBLISH_SIGNATURE_MISSING (7)
      9568 ERR_PUBLISH_NETWORK           (4)
      9569 ERR_PUBLISH_ROLLBACK_FAILED   (8)
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$')]
    [string] $Version,

    [Parameter(Mandatory = $true)]
    [ValidateSet('Stable', 'Beta')]
    [string] $Channel,

    [ValidateSet('WindowsAmd64', 'LinuxAmd64', 'DarwinArm64', 'All')]
    [string] $Platform = 'All',

    [string] $Product,
    [string] $MinRequiredVersion,
    [string] $ReleaseNotesUrl,
    [switch] $DryRun,
    [switch] $Verify,
    [string] $ApiBaseUrl,
    [string] $AdminTokenEnv,
    [string] $SignatureDir,
    [string] $ConfigPath = "$PSScriptRoot/../powershell.json"
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# ---- Exit-code constants -----------------------------------------------------
$script:ERR_PUBLISH_CONFIG_MISSING    = @{ Code = 9560; Exit = 5 }
$script:ERR_PUBLISH_TOKEN_MISSING     = @{ Code = 9561; Exit = 6 }
$script:ERR_PUBLISH_ASSET_MISSING     = @{ Code = 9562; Exit = 7 }
$script:ERR_PUBLISH_CHECKSUM_FAILED   = @{ Code = 9563; Exit = 8 }
$script:ERR_PUBLISH_UPLOAD_FAILED     = @{ Code = 9564; Exit = 8 }
$script:ERR_PUBLISH_MANIFEST_FAILED   = @{ Code = 9565; Exit = 4 }
$script:ERR_PUBLISH_VERIFY_FAILED     = @{ Code = 9566; Exit = 3 }
$script:ERR_PUBLISH_SIGNATURE_MISSING = @{ Code = 9567; Exit = 7 }
$script:ERR_PUBLISH_NETWORK           = @{ Code = 9568; Exit = 4 }
$script:ERR_PUBLISH_ROLLBACK_FAILED   = @{ Code = 9569; Exit = 8 }

function Write-PublishError {
    param([hashtable] $Category, [string] $Message, [string] $RequestId)
    $suffix = if ([string]::IsNullOrEmpty($RequestId)) { '' } else { " requestId=$RequestId" }
    Write-Host ("ERROR [{0}]: {1}{2}" -f $Category.Code, $Message, $suffix) -ForegroundColor Red
    exit $Category.Exit
}

function Write-PublishInfo {
    param([string] $Method, [string] $Url, [int] $Status, [string] $RequestId)
    Write-Host ("INFO {0} {1} -> {2} requestId={3}" -f $Method, $Url, $Status, $RequestId)
}

function Get-PublishConfig {
    param([string] $Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        Write-PublishError $script:ERR_PUBLISH_CONFIG_MISSING "powershell.json not found at $Path"
    }
    try {
        $raw = Get-Content -LiteralPath $Path -Raw
        $config = $raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        Write-PublishError $script:ERR_PUBLISH_CONFIG_MISSING "powershell.json parse failed: $($_.Exception.Message)"
    }
    if (-not $config.PSObject.Properties['Publish']) {
        Write-PublishError $script:ERR_PUBLISH_CONFIG_MISSING "powershell.json missing 'Publish' block"
    }
    return $config.Publish
}

function Resolve-AdminToken {
    param([string] $EnvName)
    $value = [Environment]::GetEnvironmentVariable($EnvName)
    if ([string]::IsNullOrWhiteSpace($value)) {
        Write-PublishError $script:ERR_PUBLISH_TOKEN_MISSING "Admin token env var '$EnvName' is unset or empty"
    }
    return $value
}

function Get-PlatformList {
    param([string] $Requested, [string[]] $Matrix)
    if ($Requested -eq 'All') { return $Matrix }
    return @($Requested)
}

function Get-ArtifactPath {
    param([string] $BuildDir, [string] $Version, [string] $Platform)
    $candidate = Join-Path -Path (Join-Path -Path $BuildDir -ChildPath $Version) -ChildPath $Platform
    if (-not (Test-Path -LiteralPath $candidate)) {
        Write-PublishError $script:ERR_PUBLISH_ASSET_MISSING "Expected artifact directory '$candidate' not found"
    }
    $file = Get-ChildItem -LiteralPath $candidate -File | Where-Object { $_.Extension -ne '.sig' } | Select-Object -First 1
    if ($null -eq $file) {
        Write-PublishError $script:ERR_PUBLISH_ASSET_MISSING "No artifact file under '$candidate'"
    }
    return $file.FullName
}

function Get-ArtifactHash {
    param([string] $Path)
    try {
        $result = Get-FileHash -LiteralPath $Path -Algorithm SHA256 -ErrorAction Stop
    } catch {
        Write-PublishError $script:ERR_PUBLISH_CHECKSUM_FAILED "Get-FileHash failed on '$Path': $($_.Exception.Message)"
    }
    return $result.Hash.ToLowerInvariant()
}

function New-LaraHeaders {
    param([string] $Token)
    return @{
        Authorization  = "Bearer $Token"
        Accept         = 'application/json'
        'X-Request-Id' = [guid]::NewGuid().ToString()
    }
}

function Assert-LaraHttpOk {
    param([int] $Status, [string] $ErrCode, [string] $Message, [string] $RequestId)
    if ($Status -lt 200 -or $Status -ge 300) {
        Write-PublishError $ErrCode $Message $RequestId
    }
}

function Invoke-LaraJson {
    param([string] $Method, [string] $Url, [string] $Token, [object] $Body)
    $headers = New-LaraHeaders -Token $Token
    $params = @{
        Method = $Method; Uri = $Url; Headers = $headers
        SkipHttpErrorCheck = $true; StatusCodeVariable = 'httpStatus'
    }
    if ($null -ne $Body) {
        $params.Body = ($Body | ConvertTo-Json -Depth 10 -Compress)
        $params.ContentType = 'application/json'
    }
    try { $response = Invoke-RestMethod @params }
    catch { Write-PublishError $script:ERR_PUBLISH_NETWORK "$Method $Url transport failure: $($_.Exception.Message)" $headers.'X-Request-Id' }
    Write-PublishInfo $Method $Url $httpStatus $headers.'X-Request-Id'
    return [pscustomobject]@{ Status = $httpStatus; Body = $response; RequestId = $headers.'X-Request-Id' }
}

function Request-UploadTicket {
    param([string] $BaseUrl, [string] $Token, [string] $Product, [string] $Version, [string] $Platform, [long] $Size, [string] $Sha256)
    $body = @{
        Product = $Product; Version = $Version; Platform = $Platform
        SizeBytes = $Size; Sha256 = $Sha256
    }
    $result = Invoke-LaraJson -Method 'POST' -Url "$BaseUrl/Admin/AppUpdates/UploadTicket" -Token $Token -Body $body
    Assert-LaraHttpOk $result.Status $script:ERR_PUBLISH_UPLOAD_FAILED "UploadTicket returned $($result.Status)" $result.RequestId
    return $result.Body.Results[0]
}

function Invoke-AssetPut {
    param([string] $UploadUrl, [string] $Sha256, [string] $ArtifactPath, [hashtable] $Headers)
    return Invoke-WebRequest -Method Put -Uri $UploadUrl -Headers $Headers `
        -InFile $ArtifactPath -ContentType 'application/octet-stream' `
        -SkipHttpErrorCheck -ErrorAction Stop
}

function Send-AssetBinary {
    param([string] $UploadUrl, [string] $Sha256, [string] $ArtifactPath, [int] $MaxAttempts = 2)
    for ($attempt = 1; $attempt -le $MaxAttempts; $attempt++) {
        $headers = @{ 'X-Sha256' = $Sha256; 'X-Request-Id' = [guid]::NewGuid().ToString() }
        try { $response = Invoke-AssetPut -UploadUrl $UploadUrl -Sha256 $Sha256 -ArtifactPath $ArtifactPath -Headers $headers }
        catch {
            if ($attempt -eq $MaxAttempts) { Write-PublishError $script:ERR_PUBLISH_NETWORK "Asset PUT transport failure after $attempt attempts: $($_.Exception.Message)" $headers.'X-Request-Id' }
            Start-Sleep -Milliseconds (200 * [math]::Pow(5, $attempt - 1)); continue
        }
        Write-PublishInfo 'PUT' $UploadUrl $response.StatusCode $headers.'X-Request-Id'
        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 300) { return }
        if ($attempt -eq $MaxAttempts) { Write-PublishError $script:ERR_PUBLISH_UPLOAD_FAILED "Asset PUT returned $($response.StatusCode)" $headers.'X-Request-Id' }
    }
}

function Send-SignatureSidecar {
    param([string] $UploadUrl, [string] $SigPath)
    if (-not (Test-Path -LiteralPath $SigPath)) { Write-PublishError $script:ERR_PUBLISH_SIGNATURE_MISSING "Signature file '$SigPath' not found" }
    $sigUrl = "$UploadUrl.sig"
    $headers = @{ 'X-Request-Id' = [guid]::NewGuid().ToString() }
    try {
        $response = Invoke-WebRequest -Method Put -Uri $sigUrl -Headers $headers `
            -InFile $SigPath -ContentType 'application/pgp-signature' `
            -SkipHttpErrorCheck -ErrorAction Stop
    } catch { Write-PublishError $script:ERR_PUBLISH_NETWORK "Signature PUT transport failure: $($_.Exception.Message)" $headers.'X-Request-Id' }
    Write-PublishInfo 'PUT' $sigUrl $response.StatusCode $headers.'X-Request-Id'
    Assert-LaraHttpOk $response.StatusCode $script:ERR_PUBLISH_UPLOAD_FAILED "Signature PUT returned $($response.StatusCode)" $headers.'X-Request-Id'
}

function Publish-Manifest {
    param([string] $BaseUrl, [string] $Token, [hashtable] $Manifest)
    $result = Invoke-LaraJson -Method 'POST' -Url "$BaseUrl/Admin/AppUpdates" -Token $Token -Body $Manifest
    Assert-LaraHttpOk $result.Status $script:ERR_PUBLISH_MANIFEST_FAILED "POST /Admin/AppUpdates returned $($result.Status)" $result.RequestId
    return $result.Body.Results[0]
}

function Assert-AssetMatch {
    param([array] $RemoteAssets, [object] $Local, [string] $RequestId)
    $match = $RemoteAssets | Where-Object { $_.Platform -eq $Local.Platform } | Select-Object -First 1
    if ($null -eq $match -or $match.Sha256 -ne $Local.Sha256) {
        Write-PublishError $script:ERR_PUBLISH_VERIFY_FAILED "Verify Sha256 mismatch for $($Local.Platform)" $RequestId
    }
}

function Assert-VerifyManifest {
    param([string] $BaseUrl, [string] $Token, [string] $Product, [string] $Channel, [string] $Version, [array] $Assets)
    $probePlatform = $Assets[0].Platform
    $url = "$BaseUrl/App/UpdateManifest?Product=$Product&Channel=$Channel&Platform=$probePlatform&CurrentVersion=0.0.0"
    $result = Invoke-LaraJson -Method 'GET' -Url $url -Token $Token -Body $null
    if ($result.Status -ne 200) { Write-PublishError $script:ERR_PUBLISH_VERIFY_FAILED "Verify GET returned $($result.Status)" $result.RequestId }
    $remote = $result.Body.Results[0]
    if ($remote.LatestVersion -ne $Version) { Write-PublishError $script:ERR_PUBLISH_VERIFY_FAILED "Verify mismatch: expected $Version, got $($remote.LatestVersion)" $result.RequestId }
    foreach ($local in $Assets) { Assert-AssetMatch -RemoteAssets $remote.Assets -Local $local -RequestId $result.RequestId }
}


# ---- Main --------------------------------------------------------------------
$config = Get-PublishConfig -Path $ConfigPath

$resolvedProduct       = if ([string]::IsNullOrEmpty($Product))            { $config.Product }       else { $Product }
$resolvedBaseUrl       = if ([string]::IsNullOrEmpty($ApiBaseUrl))         { if ([string]::IsNullOrEmpty($env:LARA_API_BASE_URL)) { $config.ApiBaseUrl } else { $env:LARA_API_BASE_URL } } else { $ApiBaseUrl }
$resolvedTokenEnv      = if ([string]::IsNullOrEmpty($AdminTokenEnv))      { $config.AdminTokenEnv } else { $AdminTokenEnv }
$resolvedMinRequired   = if ([string]::IsNullOrEmpty($MinRequiredVersion)) { $Version }              else { $MinRequiredVersion }
$resolvedSignatureDir  = if ([string]::IsNullOrEmpty($SignatureDir))       { $config.SignatureDir }  else { $SignatureDir }

if ([string]::IsNullOrEmpty($resolvedBaseUrl)) {
    Write-PublishError $script:ERR_PUBLISH_CONFIG_MISSING "ApiBaseUrl not resolved from flag, env, or config"
}

$token = if ($DryRun) { '' } else { Resolve-AdminToken -EnvName $resolvedTokenEnv }
$platforms = Get-PlatformList -Requested $Platform -Matrix $config.PlatformMatrix

$manifestAssets = @()
foreach ($plat in $platforms) {
    $artifact = Get-ArtifactPath -BuildDir $config.BuildOutputDir -Version $Version -Platform $plat
    $sha256   = Get-ArtifactHash -Path $artifact
    $size     = (Get-Item -LiteralPath $artifact).Length
    Write-Host ("INFO artifact platform={0} path={1} sha256={2} size={3}" -f $plat, $artifact, $sha256, $size)

    if ($DryRun) {
        $manifestAssets += @{ Platform = $plat; Sha256 = $sha256; SizeBytes = $size; UploadToken = 'dry-run' }
        continue
    }

    $ticket = Request-UploadTicket -BaseUrl $resolvedBaseUrl -Token $token -Product $resolvedProduct `
        -Version $Version -Platform $plat -Size $size -Sha256 $sha256
    Send-AssetBinary -UploadUrl $ticket.UploadUrl -Sha256 $sha256 -ArtifactPath $artifact

    if (-not [string]::IsNullOrEmpty($resolvedSignatureDir)) {
        $sig = Join-Path -Path $resolvedSignatureDir -ChildPath "$Version/$plat.sig"
        Send-SignatureSidecar -UploadUrl $ticket.UploadUrl -SigPath $sig
    }

    $manifestAssets += @{ Platform = $plat; Sha256 = $sha256; SizeBytes = $size; UploadToken = $ticket.UploadToken }
}

$manifestBody = @{
    Product            = $resolvedProduct
    Channel            = $Channel
    Version            = $Version
    MinRequiredVersion = $resolvedMinRequired
    Assets             = $manifestAssets
}
if (-not [string]::IsNullOrEmpty($ReleaseNotesUrl)) { $manifestBody.ReleaseNotesUrl = $ReleaseNotesUrl }

if ($DryRun) {
    Write-Host "DRY-RUN manifest body:"
    Write-Host ($manifestBody | ConvertTo-Json -Depth 10)
    exit 0
}

Publish-Manifest -BaseUrl $resolvedBaseUrl -Token $token -Manifest $manifestBody | Out-Null

if ($Verify) {
    Assert-VerifyManifest -BaseUrl $resolvedBaseUrl -Token $token -Product $resolvedProduct `
        -Channel $Channel -Version $Version -Assets $manifestAssets
}

Write-Host ("SUCCESS Published {0} to {1} across {2}" -f $Version, $Channel, ($platforms -join ', ')) -ForegroundColor Green
exit 0
