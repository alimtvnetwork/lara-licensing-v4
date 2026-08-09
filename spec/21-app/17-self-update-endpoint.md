# Self-Update Endpoints

**Version:** 1.5.0
**Updated:** 2026-07-16
**Status:** Current. Consumers: `10-endpoints.md` v0.2.0, `12-error-taxonomy.md` v1.2.0, `13-audit-logging.md` v0.2.0, `14-rate-limiting.md` v1.2.0, `16-ui-surfaces.md` v0.22.0, `18-publishing-powershell.md`, `20-observability.md`, `../23-app-db/01-schema.md` v0.9.0.

Contract for the CLI self-update flow. Read from `spec/25-app-audit/17-self-update-integration-plan.md` (merged plan) and `spec/14-update/` (client obligations). Every JSON key is PascalCase; every path segment is PascalCase per `10-endpoints.md` conventions. Every `ErrorCode` string is PascalCase per `12-error-taxonomy.md` v1.2.0; no `SCREAMING_SNAKE_CASE` alias is exposed.

## v1.0 rollout policy (normative)

v1.0 ships the `Stable` channel only. The `Channel` enum keeps `Beta` reserved so enabling it later is a policy change (auth gate plus UI toggle), not a wire-contract change; no schema, enum, or endpoint migration is required to turn Beta on post-v1.0.

Consequences for v1.0:

1. Every UI call site MUST pass `Channel=Stable`. `UpdateBanner` (`src/components/update-banner.tsx`) does not expose a `channel` prop in v1.0; the constant is inlined.
2. `publish-lara.ps1 -Channel Beta` remains valid for internal dogfooding; no end-user UI surfaces Beta.
3. The v1.0 acceptance sweep excludes Beta-channel auth paths and the uncached-manifest rate-limit variant. Those ACs stay in the spec marked `Deferred: post-v1.0`.
4. Enabling Beta post-v1.0 requires: (a) settings-screen toggle bound to a per-user `UpdateChannel` column, (b) `UpdateChannelChanged` audit event in `13-audit-logging.md`, (c) server-side gate `has_role(AppBuilder|Admin)`, (d) reintroducing the `channel` prop on `UpdateBanner`.

## Endpoints

| Endpoint | Method | Auth | Notes |
|----------|--------|------|-------|
| `/App/UpdateManifest` | GET | none for `Stable`, `has_role(AppBuilder|Admin)` for `Beta` | Returns latest available version and per-platform asset descriptors. |
| `/App/UpdateAsset/{Version}/{Platform}` | HEAD | same as GET | Probe: returns `Content-Length`, `ETag`, `X-Sha256` without body. |
| `/App/UpdateAsset/{Version}/{Platform}` | GET | same channel policy | Streams the binary; asset headers echo the manifest checksum. |
| `/Admin/AppUpdates/UploadTicket` | POST | `has_role(Admin)` | Reserves an upload slot for `publish-lara.ps1`; returns `UploadToken`, `UploadUrl`. |
| `/Admin/AppUpdates` | POST | `has_role(Admin)` | Materializes the manifest row after all platform assets uploaded. |

Path segments follow the winner of `AF-CX-101` (PascalCase); no lowercase alias is exposed.

## Platform enum (canonical set, single source of truth)

The `Platform` enum is normative for `/App/UpdateManifest`, `/App/UpdateAsset/{Version}/{Platform}`, `/Admin/AppUpdates/UploadTicket`, `/Admin/AppUpdates`, the `AppUpdateAssets.Platform` column in `../23-app-db/01-schema.md`, and every `Update*` audit event in `13-audit-logging.md`.

| Member | Description | Binary suffix (client) |
|--------|-------------|------------------------|
| `WindowsAmd64` | Windows 10/11 x64 | `.exe` |
| `LinuxAmd64` | Linux x86_64 glibc | (none) |
| `DarwinArm64` | macOS 12+ Apple Silicon | (none) |

Rules:

1. The enum is closed. Adding a member requires a minor version bump of this file AND `../23-app-db/01-schema.md`.
2. No alias is accepted (no `win64`, `linux`, `mac`). Unknown values return `ValidationInputInvalid` (400) with `Rule: "PlatformEnum"`.
3. Case is strict PascalCase. `windowsamd64` and `WINDOWSAMD64` both fail with `ValidationInputInvalid`.
4. Every published `AppUpdates` row MUST carry at least one `AppUpdateAssets` row per platform the manifest advertises; a manifest response MUST NOT list a platform whose asset row is missing or non-finalized (see §Publish state machine).


## `GET /App/UpdateManifest`

### Request

Query parameters, all required:

| Name | Type | Notes |
|------|------|-------|
| `Product` | string | Product slug (`lara-cli`). |
| `Channel` | enum `Stable`, `Beta` | See auth policy below. |
| `CurrentVersion` | semver | Caller's installed version. |
| `Platform` | enum `WindowsAmd64`, `LinuxAmd64`, `DarwinArm64` | Filters `Assets`. |

Missing or unknown values return `UpdateChannelUnknown` (400) for `Channel`, otherwise `ValidationInputInvalid` (400) with `Rule` naming the offending field.

### Response 200

```json
{
  "Status": { "IsSuccess": true, "Code": 200, "Message": "OK" },
  "Attributes": { "RequestId": "01HXYZ..." },
  "Results": [{
    "Product": "lara-cli",
    "Channel": "Stable",
    "LatestVersion": "1.4.2",
    "MinRequiredVersion": "1.0.0",
    "PublishedAt": "2026-07-16T10:00:00Z",
    "Assets": [
      {
        "Platform": "WindowsAmd64",
        "Url": "/App/UpdateAsset/1.4.2/WindowsAmd64",
        "SizeBytes": 12345678,
        "Sha256": "hex-lowercase-64",
        "SignatureUrl": "/App/UpdateAsset/1.4.2/WindowsAmd64.sig"
      }
    ],
    "ReleaseNotesUrl": "https://.../releases/1.4.2"
  }]
}
```

`Stable` responses SHOULD carry `Cache-Control: public, max-age=30`. `Beta` responses MUST carry `Cache-Control: no-store`.

### Errors

| Error code | HTTP | When |
|-----------|:----:|------|
| `UpdateManifestUnavailable` | 503 | Storage/manifest source unavailable. |
| `UpdateChannelUnknown` | 400 | `Channel` not in enum. |
| `UpdateVersionDowngradeBlocked` | 409 | `CurrentVersion > LatestVersion`. |
| `ValidationInputInvalid` | 400 | Any query field fails shape or enum check (including `Platform`). |
| `AuthzRoleDenied` | 403 | Beta channel and caller lacks `AppBuilder`/`Admin` role. |

## `HEAD /App/UpdateAsset/{Version}/{Platform}` and `GET`

### Request

Path parameters:

- `Version`: exact semver from a prior manifest response.
- `Platform`: same enum as manifest.

Headers:

- `Authorization: Bearer ...` when the target manifest was `Beta`.

### Response 200

Headers on both `HEAD` and `GET`:

- `Content-Length`: byte size, MUST equal `SizeBytes` from manifest.
- `ETag`: strong ETag bound to `Sha256`.
- `X-Sha256`: lowercase hex, MUST equal manifest `Sha256`.
- `Content-Type: application/octet-stream` (GET only).

Body: binary asset (GET) or empty (HEAD).

### Errors

| Error code | HTTP | When |
|-----------|:----:|------|
| `UpdateAssetNotFound` | 404 | Version/platform tuple has no finalized asset. |
| `AuthzRoleDenied` | 403 | Beta channel and caller lacks role. |

### Client-side verification (MUST)

After `GET`, the client MUST:

1. Stream the response body to a temporary file (never the final deploy path).
2. Compute SHA-256 of the temporary file.
3. Compare the digest to the `X-Sha256` response header (case-insensitive hex equality).
4. On match: emit `UpdateVerified` audit event, then proceed with the rename-first-deploy sequence per `spec/14-update/03-rename-first-deploy.md`, then emit `UpdateDownloaded`.
5. On mismatch: proceed with the MUST-abort sequence in §MUST-abort conditions row A1.

### MUST-abort conditions (normative)

Each row below is a spec violation if the client continues to `rename-first-deploy` after the trigger fires. Every abort MUST: (a) delete the temporary file, (b) leave the currently-installed binary untouched, (c) emit the listed audit event with `RequestId` propagated from the failing `GET`, (d) return a non-zero exit code from the CLI update path.

| ID | Trigger | Audit event | Retryable |
|----|---------|-------------|:---------:|
| A1 | SHA-256 of temp file != `X-Sha256` response header | `UpdateVerificationFailed { ExpectedSha256, ActualSha256 }` | once (transient); second mismatch is terminal for the version |
| A2 | `Content-Length` header != manifest `SizeBytes` | `UpdateVerificationFailed { ExpectedSizeBytes, ActualSizeBytes }` | once |
| A3 | `X-Sha256` header missing, empty, or not 64 lowercase hex chars | `UpdateVerificationFailed { Reason: "ShaHeaderMissing" }` | no |
| A4 | Response status != 200 after redirects | `UpdateDownloadFailed { HttpStatus, ErrorCode }` | per §Retry class of the error code |
| A5 | Transport not TLS (plain `http://`) at any hop | `UpdateVerificationFailed { Reason: "InsecureTransport" }` | no |
| A6 | Server-advertised `MinRequiredVersion` > running client version AND channel = `Stable` and no interactive operator | `UpdateBlockedForcedUpgradeRequired` | no; operator must consent |
| A7 | Manifest `LatestVersion` < running client version (downgrade) unless `--allow-downgrade` opt-in | `UpdateVersionDowngradeBlocked` | no |
| A8 | Signature verification enabled and `SignatureUrl` payload fails (see §Signature verification) | `UpdateSignatureInvalid` | no |
| A9 | Temp file cannot be created, or free disk < `SizeBytes` * 2 | `UpdateDeployPreflightFailed { Reason }` | manual retry after operator remediation |
| A10 | Rename-first-deploy step itself fails after verification (source binary already moved) | `UpdateDeployFailed { Stage }` per `spec/14-update/03-rename-first-deploy.md` rollback | no; MUST restore backup |

"Log and continue" is a spec violation for every row above. An implementation that deploys after any A1..A10 trigger is non-conforming and fails audit ST-04 / ST-08.

### Signature verification (optional but pinned when enabled)

If the manifest asset carries a non-null `SignatureUrl`, the client MUST fetch the signature and MUST verify it against the pinned publisher key before rename-first-deploy. The pinned key is shipped with the client binary (config file per `spec/14-update/`); it is never fetched from the update server. A verification failure fires MUST-abort row A8. Clients that ship without a pinned key MUST treat `SignatureUrl` as advisory and MUST document that they operate in "checksum-only" mode. Operators cannot silently upgrade a checksum-only client to signature-required: the mode is a compile-time property of the binary.




## `POST /Admin/AppUpdates/UploadTicket`

Reserves an upload slot per platform. Called by `publish-lara.ps1` before uploading each asset.

### Request

```json
{
  "Product": "lara-cli",
  "Version": "1.4.2",
  "Platform": "WindowsAmd64",
  "SizeBytes": 12345678,
  "Sha256": "hex-lowercase-64"
}
```

### Response 201

```json
{
  "Status": { "IsSuccess": true, "Code": 201, "Message": "Created" },
  "Attributes": { "RequestId": "..." },
  "Results": [{
    "UploadToken": "opaque",
    "UploadUrl": "https://storage.../assets/1.4.2/WindowsAmd64?token=...",
    "ExpiresAt": "2026-07-16T11:00:00Z"
  }]
}
```

### Errors

`AUTHZ_ROLE_DENIED` (403), `VALIDATION_INVALID_VERSION` (400), `UPDATE_VERSION_DOWNGRADE_BLOCKED` (409), `UPDATE_ASSET_UPLOAD_FAILED` (502).

## `POST /Admin/AppUpdates`

Materializes the manifest row after all per-platform assets have been uploaded via `PUT {UploadUrl}` with header `X-Sha256: <hash>`.

### Request

```json
{
  "Product": "lara-cli",
  "Channel": "Stable",
  "Version": "1.4.2",
  "MinRequiredVersion": "1.0.0",
  "Assets": [
    { "Platform": "WindowsAmd64", "Sha256": "...", "SizeBytes": 12345678, "UploadToken": "..." }
  ],
  "ReleaseNotesUrl": "https://.../releases/1.4.2"
}
```

### Response 201

Returns the same shape as `GET /App/UpdateManifest` §Response 200 `Results[0]`.

### Errors

`AuthzRoleDenied` (403), `ValidationInvalidVersion` (400), `UpdateVersionDowngradeBlocked` (409), `UpdateAssetUploadFailed` (502).

## Auth policy summary

| Channel | Read manifest | Read asset | Publish | Yank |
|---------|:-------------:|:----------:|:-------:|:----:|
| `Stable` | anonymous | anonymous | `has_role(Admin)` | `has_role(Admin)` |
| `Beta` | `has_role(AppBuilder|Admin)` | same as manifest | `has_role(Admin)` | `has_role(Admin)` |

## Admin invariants (normative)

Every write endpoint under `/Admin/AppUpdates/*` MUST satisfy each rule below. Violation of any single rule is a spec break and fails audit ST-04 / ST-08.

1. **Role gate.** The caller MUST hold `Admin` at request time, verified via `has_role(auth.uid(), 'admin')` per `04-roles.md`. `AppBuilder` MAY read `Beta` manifests but MUST NOT publish, upload, or yank.
2. **Session integrity.** The caller's refresh token MUST NOT be in a reused chain (`AuthRefreshReused` cascade per `31-auth-session-family.md`); a family with any revoked ancestor MUST be rejected with `AuthSessionRevoked` (401) before the role check runs.
3. **Actor stamping.** Every row written to `AppUpdates` and `AppUpdateAssets` MUST carry `PublishedByUserId = auth.uid()`; every `UpdatePublished`, `UpdateYanked`, and `UpdateAssetUploaded` audit event MUST carry the same `ActorUserId`. The server MUST reject client-supplied `PublishedByUserId` values.
4. **Idempotency scope.** `POST /Admin/AppUpdates/UploadTicket` and `POST /Admin/AppUpdates` are mutating and MUST honor `Idempotency-Key` per `11-api-contracts/08-idempotency-envelope-hardening.md`. Replayed `POST /Admin/AppUpdates` returns the original `Results[0]` unchanged.
5. **Version monotonicity.** `POST /Admin/AppUpdates` MUST reject `Version <= max(LatestVersion)` for the same `(Product, Channel)` with `UpdateVersionDowngradeBlocked` (409); admins cannot backfill or overwrite.
6. **Platform coverage.** `POST /Admin/AppUpdates` MUST reject requests whose `Assets[]` set does not equal the declared coverage set for the `Channel` (see `spec/21-app/16-ui-surfaces.md` §Publishing). Partial-platform publishes are a spec break; admins publish all supported platforms atomically.
7. **Storage authority.** The publish endpoint MUST NOT accept an asset whose upload was not verified server-side (`Content-Length` and streamed SHA-256 both matched). A client-supplied `Sha256` value alone is not sufficient.
8. **Rate limit exemption denied.** Admin publish paths are subject to the write-mutation bucket in `14-rate-limiting.md`. There is no admin bypass; `RateLimited` (429) with `Retry-After` applies to every write.
9. **Audit precedence.** Every admin action MUST emit its audit event inside the same DB transaction as the mutation. A rollback MUST also discard the audit event; a committed audit event with no matching row is a spec break.
10. **Yank irreversibility.** `POST /Admin/AppUpdates/{Version}/Yank` MUST be rejected (`ValidationConflict` 409) if `IsYanked = 1` already. There is no "un-yank" endpoint in v1.


## Audit events (declared in `13-audit-logging.md`)

- `UpdatePublished { ActorUserId, Product, Channel, Version, Platforms, RequestId }` on `POST /Admin/AppUpdates` success.
- `UpdateDownloaded { UserId, Product, Version, Platform, RequestId }` on `GET /App/UpdateAsset/...` success.
- `UpdateVerified { UserId, Product, Version, Platform, Sha256, RequestId }` when client reports successful verification (`POST /App/UpdateVerified`, gated behind auth if user session exists; may be dropped for anonymous Stable).
- `UpdateVerificationFailed { UserId, Product, Version, Platform, ExpectedSha256, ActualSha256, RequestId }` on client-reported mismatch.

## Rate limits (declared in `14-rate-limiting.md`)

- Manifest bucket: 600 req/min/IP, cache-friendly.
- Asset bucket: 20 req/min/IP, bandwidth-bounded, no cache.

## Cross-references

- Client obligations: `spec/14-update/` (probe, checksum, rename-first-deploy, handoff, config-file).
- Publish path: `spec/21-app/18-publishing-powershell.md` (plan step 24, next).
- Envelope: `spec/21-app/11-api-contracts/00-overview.md`.
- Error taxonomy: `spec/21-app/12-error-taxonomy.md` (plan step 29 adds five `UPDATE_*` codes).
- Bridge spec: `spec/25-app-audit/17-self-update-integration-plan.md`.
- Storage schema: [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §"Self-Update" (`AppUpdates`, `AppUpdateAssets`).

## Database bindings

Normative storage: `AppUpdates` and `AppUpdateAssets` in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) §"Self-Update".

### Publish state machine

Every manifest row transitions through three named states, in order. Skipping a state is a spec violation. Named states are normative: downstream controllers, migrations, JsonResources, Pest assertions, and audit events MUST reference them by name (`Draft`, `Staged`, `Published`), not by wire step number. See `spec/25-app-audit/A1-diff.md` §3 for the reconciliation that introduced these names.

State definitions (canonical):

| State | Definition (row predicate) | Observable to `GET /App/UpdateManifest`? |
|---|---|:---:|
| `Draft` | `AppUpdateAssets` row exists with `IsFinalized = 0` AND `UploadTicketExpiresAt > NOW()`. No `AppUpdates` row references it yet. | no |
| `Staged` | Every platform's `PUT {UploadUrl}` succeeded (storage verified `Content-Length` and streamed SHA-256). Rows remain `IsFinalized = 0`. Computed state, not a column. | no |
| `Published` | `AppUpdates` row exists with `IsYanked = 0` AND every referenced `AppUpdateAssets.IsFinalized = 1`. | yes (per §Endpoints filter) |
| `Yanked` | `AppUpdates.IsYanked = 1`. Terminal. | no |

Transitions:

```text
(nothing)
  -> Draft       via POST /Admin/AppUpdates/UploadTicket per platform
                    INSERT AppUpdateAssets with IsFinalized = 0, UploadTicketExpiresAt = NOW() + 60min.
                    Row is invisible to GET /App/UpdateManifest; GET /App/UpdateAsset returns UpdateAssetNotFound.

  -> Staged      via PUT {UploadUrl} with header X-Sha256: <hash>
                    Storage verifies Content-Length matches ticket SizeBytes AND streamed SHA-256 matches ticket Sha256.
                    On mismatch: storage returns 422; app writes UpdateVerificationFailed audit (id 42) and leaves the row IsFinalized = 0 (still Draft, not Staged).
                    On match: no DB write. IsFinalized remains 0. State is Staged (computed) once every platform in the intended manifest has matched.

  -> Published   via POST /Admin/AppUpdates with the full Assets[] array
                    In a single transaction: INSERT AppUpdates, then UPDATE every referenced AppUpdateAssets row SET IsFinalized = 1 WHERE AppUpdateId = <new> AND Sha256 = <supplied> AND IsFinalized = 0.
                    Rows-affected MUST equal Assets.length. Any lower count -> ROLLBACK, return UpdateAssetUploadFailed (502).
                    Emit UpdatePublished (audit id 39). COMMIT.

  -> Yanked      via POST /Admin/AppUpdates/{Version}/Yank (Admin only)
                    Sets IsYanked = 1, YankedAt = NOW(), YankedByUserId = auth.uid(). Emit UpdateYanked audit. Terminal in v1 (no un-yank endpoint).
```

Invariants:

1. A `Draft` row whose `UploadTicketExpiresAt < NOW()` MUST be deleted by the retention job (see §Upload ticket expiry). It never reaches `Staged`.
2. `Staged` is computed at `POST /Admin/AppUpdates` time; there is no `Staged` column and no client can observe it via any endpoint.
3. `Draft -> Published` MUST NOT skip storage verification: a `POST /Admin/AppUpdates` referencing a row whose `IsFinalized = 0` and whose ticket `Sha256` was never matched by an upload MUST be rejected with `UpdateAssetUploadFailed` (502).
4. `Published -> Yanked` is atomic and one-way. Existing in-flight `GET /App/UpdateAsset` streams are not aborted.
5. `Yanked` rows never re-enter `Published`; a new `(Product, Channel, Version)` with a higher semver MUST be published to supersede.

Rule: `GET /App/UpdateManifest` MUST filter `AppUpdates.IsYanked = 0` AND every `AppUpdateAssets.IsFinalized = 1` for the target platform. Non-`Published` rows (any `Draft`, any `Staged`, any `Yanked`) never appear in a manifest even for the publisher.

### Client update sequence (probe, download, verify, rename, handoff)

Normative wire-level sequence for a conforming CLI (`lara update`) once the server has a `Published` row for `(Product, Channel, Version)`. Named states in participant notes refer to the server-side `AppUpdates` row state defined in §"Publish state machine"; the CLI never observes `Draft` or `Staged`. Cross-refs: client-side strategies live in `spec/14-update/01-self-update-overview.md`, rename-first-deploy details in `spec/14-update/03-rename-first-deploy.md`, handoff mechanism in `spec/14-update/05-handoff-mechanism.md`, MUST-abort rows A1..A10 in this file §"MUST-abort table".

```mermaid
sequenceDiagram
    autonumber
    participant CLI as lara CLI (running binary)
    participant Portal as Portal API (/App/*)
    participant Storage as Asset Storage
    participant FS as Local Filesystem
    participant New as lara CLI (new binary)

    Note over Portal: Only `Published` rows are visible.<br/>`Draft` / `Staged` / `Yanked` never returned.

    CLI->>Portal: GET /App/UpdateManifest?Product&Channel&CurrentVersion&Platform
    Portal-->>CLI: 200 { LatestVersion, Assets[{Url, SizeBytes, Sha256}] }
    Note right of CLI: skip-if-current: if LatestVersion <= CurrentVersion, exit 0 (14/01 §version comparison).

    rect rgba(200,220,255,0.25)
    Note over CLI,Storage: Probe (HEAD)
    CLI->>Portal: HEAD /App/UpdateAsset/{Version}/{Platform}
    Portal-->>CLI: 200 Content-Length, ETag, X-Sha256
    Note right of CLI: A5: refuse non-TLS hop. A9: require free disk >= SizeBytes * 2, else abort.
    end

    rect rgba(200,255,220,0.25)
    Note over CLI,Storage: Download (GET, streamed)
    CLI->>Storage: GET {Url} (follows redirects, TLS enforced)
    Storage-->>CLI: 200 stream (Content-Length matches probe)
    Note right of CLI: A4: non-200 after redirects -> UpdateDownloadFailed, leave installed binary untouched.
    end

    rect rgba(255,235,200,0.35)
    Note over CLI,FS: Verify (SHA-256 pinned to manifest)
    CLI->>CLI: sha256(tempfile) == manifest.Sha256 ?
    alt mismatch (A3/A7)
        CLI->>FS: delete tempfile
        CLI-->>Portal: audit UpdateVerificationFailed (id 42) + RequestId
        Note right of CLI: exit non-zero; installed binary untouched.
    else match (+ optional signature A8)
        CLI-->>Portal: audit UpdateVerified + RequestId
    end
    end

    rect rgba(230,215,255,0.35)
    Note over CLI,FS: Rename-first deploy (14/03)
    CLI->>FS: rename current binary -> lara.old (same volume, atomic)
    CLI->>FS: rename tempfile -> current binary path
    Note right of CLI: A6/A10: on any rename failure, restore lara.old and abort.
    end

    rect rgba(255,220,220,0.35)
    Note over CLI,New: Handoff (14/05)
    CLI->>New: exec new binary with `--post-update` sentinel
    New-->>Portal: audit UpdateDownloaded (id 40) + RequestId
    Note over CLI: old process exits after handoff; lara.old cleanup on next launch (14/06).
    end
```

Sequence-diagram invariants (normative):

1. Steps 1 and 2 are the only calls the CLI makes against the Portal for a check-only run. If step 2 returns no newer version, the CLI MUST exit without performing steps 3 through 5.
2. Steps 3 (HEAD probe) and 4 (GET download) MUST both target the same `Url` returned by the manifest. A CLI MUST NOT rewrite host, scheme, or path between probe and download; drift is A5 (`InsecureTransport`) or A4 (`UpdateDownloadFailed`).
3. The verify step consumes only the `Sha256` returned by `GET /App/UpdateManifest` for that `(Version, Platform)` row. The `X-Sha256` response header on HEAD/GET is a redundant echo for observability, not an alternative source of truth. Divergence between manifest `Sha256` and `X-Sha256` MUST abort with `UpdateAssetVerificationFailed` (A7).
4. The rename step MUST be a single filesystem `rename(2)` on the same volume so the swap is atomic; a copy fallback is forbidden by A6/A10.
5. The handoff step MUST NOT run before verify + rename both succeed; a conforming CLI never `exec`s an unverified binary.
6. Every audit event emitted by the CLI (`UpdateVerified`, `UpdateVerificationFailed`, `UpdateDownloaded`) MUST carry the `RequestId` propagated from the manifest response `Attributes.RequestId`, per `13-audit-logging.md`.

### Yank flow



`POST /Admin/AppUpdates/{Version}/Yank` (Admin only) sets `IsYanked = 1`, `YankedAt = NOW()`, `YankedByUserId = <caller>`. Effect is immediate: manifest hides the row, asset GETs return `UpdateAssetNotFound`. Existing downloads in flight are NOT aborted. Yank is irreversible in v1 (a new version MUST be published to supersede). Emits `UpdateYanked` (reserved audit id, see §Audit events).

### Upload ticket expiry

A pending `AppUpdateAssets` row with `IsFinalized = 0` and `UploadTicketExpiresAt < NOW()` MUST be deleted by the retention job, freeing the `(AppUpdateId, Platform)` uniqueness slot. A retry then calls `POST /Admin/AppUpdates/UploadTicket` again and receives a new opaque `UploadToken`.

### Concurrency

Two admins publishing the same `(Product, Channel, Version)` are serialized by the `AppUpdates` unique index; the loser receives `409 ValidationInvalidVersion` (duplicate version). Two admins uploading different platforms for the same version proceed in parallel; the `(AppUpdateId, Platform)` unique index rejects duplicate platform uploads with `409 ValidationInvalidVersion`.

## Acceptance

Passes `AC-AUD-006`, `AC-AUD-014`, `AC-AUD-015`; contributes to `AC-AUD-016`, `AC-AUD-017` when the error-taxonomy and audit-log files ship the referenced codes and events.

- AC-SU-DB-001: A `GET /App/UpdateManifest` response never contains a row where any listed asset has `IsFinalized = 0`.
- AC-SU-DB-002: `POST /Admin/AppUpdates` is atomic across `AppUpdates` INSERT + `AppUpdateAssets` finalization + `UpdatePublished` audit write.
- AC-SU-DB-003: A yanked row is invisible to manifest reads within the same request the yank COMMIT completes.
- AC-SU-DB-004: An expired upload ticket cannot be finalized; `POST /Admin/AppUpdates` referencing it returns `UpdateAssetUploadFailed` (502).
- AC-SU-ABORT-001: For each MUST-abort row A1..A10, a conforming client leaves the currently-installed binary untouched, deletes the temporary file, and emits the listed audit event with `RequestId` propagated.
- AC-SU-ABORT-002: A non-TLS hop (A5) or missing/invalid `X-Sha256` header (A3) fires abort even when the byte stream would otherwise verify against the manifest.
- AC-SU-ABORT-003: A signature-required client (A8) MUST NOT deploy an asset whose `SignatureUrl` payload fails or is absent; a checksum-only client MUST NOT be reconfigured at runtime into signature-required mode.
- AC-SU-ABORT-004: A conforming client MUST raise `UpdateDownloadFailed` (not `UpdateAssetVerificationFailed`) when the asset HTTP status is not 200 after redirects (A4). `Attributes.Error.Details` MUST include `{HttpStatus, ErrorCode}` and the client MUST propagate the server's `X-Request-Id` header if present.
- AC-SU-PLAT-001: The `Platform` enum is closed to `{WindowsAmd64, LinuxAmd64, DarwinArm64}`; any other value on manifest, asset, upload-ticket, or publish returns `ValidationInputInvalid` (400) with `Rule: "PlatformEnum"`.
- AC-SU-ADMIN-001: Every write under `/Admin/AppUpdates/*` requires `has_role(Admin)`; `AppBuilder` on a write returns `AuthzRoleDenied` (403).
- AC-SU-ADMIN-002: Every `AppUpdates`, `AppUpdateAssets` row and every `Update*` audit event carries `PublishedByUserId = auth.uid()`; client-supplied `PublishedByUserId` values are stripped by the server.
- AC-SU-ADMIN-003: `POST /Admin/AppUpdates` with `Version <= max(LatestVersion)` for the same `(Product, Channel)` returns `UpdateVersionDowngradeBlocked` (409) and writes no row.
- AC-SU-ADMIN-004: `POST /Admin/AppUpdates` with an `Assets[]` set that does not cover every platform declared for the `Channel` is rejected with `ValidationInputInvalid` (400) `Rule: "PlatformCoverage"` and writes no row.
- AC-SU-ADMIN-005: A replayed `POST /Admin/AppUpdates` under the same `Idempotency-Key` returns the original `Results[0]` byte-for-byte and does not emit a second `UpdatePublished` audit event.
- AC-SU-ADMIN-006: A yank against an already-yanked version returns `ValidationConflict` (409); there is no un-yank endpoint.

