# PowerShell Publishing (`publish-lara.ps1`)

**Version:** 1.0.0
**Updated:** 2026-07-16
**Status:** Current. Consumes `17-self-update-endpoint.md` v0.54.0; referenced by `16-ui-surfaces.md` v0.22.0 publish flow.

Contract for the Windows-native publish path that uploads Lara CLI assets to the host defined in `17-self-update-endpoint.md`. This file is the deliverable form of `spec/25-app-audit/18-powershell-publishing.md`; where the bridge showed reasoning, this file shows the frozen contract.

## Script identity

- Path: `scripts/publish-lara.ps1`.
- Runtime: PowerShell 7+ (uses `Get-FileHash -Algorithm SHA256`, `Invoke-RestMethod -SkipHttpErrorCheck`).
- Header (mandatory first two executable lines):

```powershell
$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest
```

Silent failure is banned per `spec/03-error-manage/`; every failure path MUST log `ERROR [<code>]` in red and `exit <top-level>`.

## Arguments

| Flag | Type | Required | Default | Purpose |
|------|------|:--------:|---------|---------|
| `-Version` | String (semver) | Y | | Release version. |
| `-Channel` | ValidateSet `Stable`,`Beta` | Y | | Release channel. |
| `-Platform` | ValidateSet `WindowsAmd64`,`LinuxAmd64`,`DarwinArm64`,`All` | N | `All` | Assets to publish. |
| `-Product` | String | N | `powershell.json` `Publish.Product` | Product slug. |
| `-MinRequiredVersion` | String (semver) | N | value of `-Version` | Force-upgrade floor. |
| `-ReleaseNotesUrl` | String | N | derived from git tag | Release notes URL. |
| `-DryRun` | Switch | N | off | Checksum + print payload, no network POST. |
| `-Verify` | Switch | N | off | After publish, GET manifest and assert LatestVersion + Sha256. |
| `-ApiBaseUrl` | String | N | env `LARA_API_BASE_URL` | Host base URL. |
| `-AdminTokenEnv` | String | N | `LARA_ADMIN_TOKEN` | Env var name holding admin JWT. |
| `-SignatureDir` | String | N | `powershell.json` `Publish.SignatureDir` | Directory with `.sig` sidecars. |

Common parameters (`-Verbose`, `-Debug`, `-ErrorAction`) are inherited from PowerShell and MUST NOT be redefined.

## Exit codes (reserved range 9560-9569)

| Code | Name | When | Top-level exit |
|------|------|------|:---:|
| 9560 | ERR_PUBLISH_CONFIG_MISSING | `powershell.json` `Publish` block missing/invalid. | 5 |
| 9561 | ERR_PUBLISH_TOKEN_MISSING | Admin token env var unset or empty. | 6 |
| 9562 | ERR_PUBLISH_ASSET_MISSING | Expected build artifact absent. | 7 |
| 9563 | ERR_PUBLISH_CHECKSUM_FAILED | `Get-FileHash` failed. | 8 |
| 9564 | ERR_PUBLISH_UPLOAD_FAILED | Asset PUT returned non-2xx. | 8 |
| 9565 | ERR_PUBLISH_MANIFEST_FAILED | `POST /Admin/AppUpdates` non-2xx (maps to `UPDATE_VERSION_DOWNGRADE_BLOCKED` / `AUTHZ_ROLE_DENIED`). | 4 |
| 9566 | ERR_PUBLISH_VERIFY_FAILED | `-Verify` manifest mismatch. | 3 |
| 9567 | ERR_PUBLISH_SIGNATURE_MISSING | `-SignatureDir` set but `*.sig` absent. | 7 |
| 9568 | ERR_PUBLISH_NETWORK | Transport error after retries. | 4 |
| 9569 | ERR_PUBLISH_ROLLBACK_FAILED | Cleanup after failure could not run. | 8 |

Log line format:

```powershell
Write-Host "ERROR [9564]: Asset upload failed: $($response.StatusCode)" -ForegroundColor Red
exit 8
```

The 9560-9569 range does not collide with 9500-9552 reserved by `spec/11-powershell-integration/`.

## Config schema (`powershell.json`)

```json
{
  "Publish": {
    "Product": "lara-cli",
    "ApiBaseUrl": "https://api.example.com",
    "AdminTokenEnv": "LARA_ADMIN_TOKEN",
    "PlatformMatrix": ["WindowsAmd64", "LinuxAmd64", "DarwinArm64"],
    "BuildOutputDir": "dist/publish",
    "SignatureDir": "dist/publish/sig",
    "SigningCertEnv": "LARA_SIGN_CERT_PATH"
  }
}
```

Secrets are env-var names only; literal keys/tokens in JSON are a spec violation.

## Publish sequence (idempotent)

1. Load `powershell.json`; if `Publish` missing → 9560.
2. Resolve admin token from `$env:$AdminTokenEnv`; empty → 9561.
3. For each platform in scope:
   1. Locate artifact under `BuildOutputDir/<Version>/<Platform>/`; missing → 9562.
   2. Compute `Sha256` via `Get-FileHash`; failure → 9563.
   3. `POST /Admin/AppUpdates/UploadTicket { Product, Version, Platform, SizeBytes, Sha256 }` (auth: admin JWT) → `{ UploadToken, UploadUrl, ExpiresAt }`.
   4. `PUT {UploadUrl}` with header `X-Sha256: <hash>` and binary body; non-2xx → 9564.
   5. If `-SignatureDir` set: `PUT` sidecar `.sig`; missing when required → 9567.
4. `POST /Admin/AppUpdates` with the full manifest body (see `17-self-update-endpoint.md` §`POST /Admin/AppUpdates`); non-2xx → 9565.
5. If `-Verify`: `GET /App/UpdateManifest?Product=...&Channel=...&Platform=<first>&CurrentVersion=0.0.0` and assert `LatestVersion == Version` and each `Sha256` matches local; mismatch → 9566.
6. On success, emit local success line: `SUCCESS Published $Version to $Channel across $Platforms`. The `UpdatePublished` audit event is emitted server-side by step 4.

`-DryRun` executes steps 1 through 3.ii, prints the manifest body that would be posted, and exits 0 without touching the network.

## HTTP retry policy

- Idempotent GET/HEAD: 3 attempts, exponential backoff (200 ms, 1 s, 5 s).
- POST/PUT: 1 attempt for `POST /Admin/AppUpdates`; 2 attempts for asset PUT (uploads are idempotent by `UploadToken`).
- After retries: 9568.

## Observability

- Every HTTP call logs `INFO <METHOD> <URL> -> <status> requestId=<X-Request-Id echo>`.
- Every failure logs `ERROR [<code>]: <message> requestId=<X-Request-Id>`.
- `-Verbose` additionally logs request bodies with the admin token redacted.

## Acceptance

Passes `AC-AUD-007`, `AC-AUD-021`, `AC-AUD-022`, `AC-AUD-023`; feeds `AC-AUD-024` when the retry/logging lines above are implemented.

## Cross-references

- Endpoints consumed: `spec/21-app/17-self-update-endpoint.md`.
- Exit-code space: `spec/11-powershell-integration/` §3.
- Error-management rules: `spec/03-error-manage/`.
- Bridge decisions: `spec/25-app-audit/18-powershell-publishing.md`.
- Consumed by plan steps 28 (endpoint registration), 29 (`UPDATE_*` error codes), 30 (`UpdatePublished` audit event), 40 (stress test).
