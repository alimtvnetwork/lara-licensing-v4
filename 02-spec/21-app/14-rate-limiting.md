# Rate Limiting, Throttling, and Abuse Prevention

**Version:** 1.2.0
**Status:** Normative for LaraLicensingV1.
**Related:** [`12-error-taxonomy.md`](./12-error-taxonomy.md), [`13-audit-logging.md`](./13-audit-logging.md), [`21-error-management-binding.md`](./21-error-management-binding.md), [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md), [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) (`RateLimitBuckets`).

This document fixes the closed set of rate-limit buckets, their windows, the key derivation, the response contract on rejection, and the abuse-prevention triggers. Implementation is Laravel middleware in a later phase; this file is the source of truth for its configuration.

Scope note: this policy governs the Laravel LaraLicensingV1 API. It is independent of the Lovable Cloud runtime, which does not ship a general-purpose rate-limit primitive.

---

## 1. Bucket Model

A bucket is `(BucketKey, WindowSeconds, MaxRequests)`. `BucketKey` is a stable string stored in `RateLimitBuckets.BucketKey` (see DB schema). Every rate-limited endpoint declares one or more buckets. A request is admitted only if every declared bucket has capacity in its current window.

### 1.1 Key derivation

| Key Prefix | Formula | Used when |
|------------|---------|-----------|
| `ip:{Ip}` | Peer IPv4 or IPv6 `/64` prefix. | Unauthenticated routes, coarse floor. |
| `actor:{ActorType}:{ActorId}` | `AuthActor` from JWT or OAuth. | Authenticated mutations. |
| `client:{ClientId}` | OAuth `ClientId`. | Machine-to-machine verify traffic. |
| `fp:{FingerprintHash}` | SHA-256 of the caller-supplied `Fingerprint` header, lowercase hex. | Verify routes, per device. |
| `email:{EmailHashLower}` | SHA-256 of lowercase email. | `POST /Auth/Token`, `POST /Auth/Password/Forgot`. |
| `serial:{SerialId}` | Bound serial primary key. | `POST /Verify/Final`. |

`Ip` is always combined with a route bucket to form a floor. Never key solely on `Ip` for authenticated routes; use `actor:` primary and `ip:` secondary.

### 1.2 Storage semantics

`RateLimitBuckets` is a fixed-window counter. A row per `(BucketKey, WindowStartAt)`. On admit: atomic `INCREMENT RequestCount` under a unique index on `(BucketKey, WindowStartAt)`. Rows older than the widest window plus one minute are pruned by a scheduled job. Sliding-window and token-bucket variants are out of scope for V1.

---

## 2. Endpoint Policy

Windows are `WindowSeconds:MaxRequests`. Multiple rows on one endpoint mean all buckets must admit.

### 2.1 Authentication

| Endpoint | Bucket | Window | Notes |
|---------|--------|--------|-------|
| `POST /Auth/Token` | `email:{h}` | 60:5 | Per email address. |
| `POST /Auth/Token` | `ip:{Ip}` | 60:30 | Per source IP floor. |
| `POST /Auth/Refresh` | `actor:User:{UserId}` | 60:20 | Prevents refresh loops. |
| `POST /Auth/Password/Forgot` | `email:{h}` | 3600:3 | Per email per hour. |
| `POST /Auth/Password/Forgot` | `ip:{Ip}` | 3600:20 | Per source IP per hour. |
| `POST /OAuth/Token` | `client:{ClientId}` | 60:60 | Client credentials grant. |
| `POST /OAuth/Token` | `ip:{Ip}` | 60:120 | Per source IP floor. |

### 2.2 Verify

| Endpoint | Bucket | Window | Notes |
|---------|--------|--------|-------|
| `POST /Verify/Hash` | `client:{ClientId}` | 60:120 | Primary machine bucket. |
| `POST /Verify/Hash` | `fp:{h}` | 60:20 | Per device, tighter. |
| `POST /Verify/Hash` | `ip:{Ip}` | 60:240 | Floor. |
| `POST /Verify/Final` | `serial:{SerialId}` | 60:10 | Prevents serial replay storm. |
| `POST /Verify/Final` | `client:{ClientId}` | 60:60 | Per integration. |
| `POST /Verify/Final` | `fp:{h}` | 60:10 | Per device. |

### 2.3 License management (admin, reseller)

| Endpoint pattern | Bucket | Window | Notes |
|------------------|--------|--------|-------|
| `POST /Licenses` and `POST /Licenses/{LicenseId}/Serials` | `actor:User:{UserId}` | 60:60 | Prevents bulk-issue accidents. |
| `POST /Licenses/{LicenseId}/Revoke` and `POST /Serials/{SerialId}/Revoke` | `actor:User:{UserId}` | 60:30 | Revoke storm cap. |
| `GET /Admin/*` list endpoints | `actor:User:{UserId}` | 60:120 | Console browsing. |
| `POST /Admin/Users/{UserId}/Roles` and `DELETE /Admin/Users/{UserId}/Roles/{Role}` | `actor:User:{UserId}` | 60:30 | Prevents role-churn storm; last-Admin guard is enforced separately as `AuthzLastAdminProtected`. |
| `POST /Licenses/{LicenseId}/Serials` (on `IdempotencyConflict` only) | `actor:User:{UserId}:IdempotencyConflict` | 60:20 | Trips when the same `Idempotency-Key` is replayed with a mutated body. On rejection, response is `RateLimited`, not `IdempotencyConflict`. |

### 2.4 Self-Update (`/App/*`)

Manifest reads are cache-friendly and high-fanout; asset reads are bandwidth-bounded and idempotent. Both are keyed on the caller when authenticated (Beta channel) and on IP for anonymous Stable traffic. Publish endpoints are keyed on the Admin actor.

| Endpoint | Bucket | Window | Notes |
|---------|--------|--------|-------|
| `GET /App/UpdateManifest` | `ip:{Ip}` | 60:120 | Anonymous Stable floor. Cache-friendly; CDN absorbs most load. |
| `GET /App/UpdateManifest` | `client:{ClientId}` | 60:60 | AppBuilder OAuth callers on Beta channel. |
| `HEAD /App/UpdateAsset/{Version}/{Platform}` | `ip:{Ip}` | 60:60 | Checksum probe; cheaper than GET. |
| `GET /App/UpdateAsset/{Version}/{Platform}` | `ip:{Ip}` | 3600:20 | Bandwidth cap: 20 full downloads per hour per IP. |
| `GET /App/UpdateAsset/{Version}/{Platform}` | `client:{ClientId}` | 3600:100 | Beta channel per OAuth client per hour. |
| `POST /Admin/AppUpdates/UploadTicket` | `actor:User:{UserId}` | 60:20 | Publish flow: ticket issuance capped, idempotent by `UploadToken`. |
| `POST /Admin/AppUpdates` | `actor:User:{UserId}` | 60:10 | Manifest publish; low volume by design. |

### 2.5 Global floors

Every request additionally passes an `ip:{Ip}` global bucket of 60:600 to cap absolute burst per source. Exceeding it returns `RateLimited` regardless of route.

---

## 3. Response Contract on Rejection

When any bucket rejects, the API responds with the error envelope from [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md):

```
HTTP/1.1 429 Too Many Requests
Retry-After: {Seconds}
X-RateLimit-Bucket: {BucketKey}
X-RateLimit-Limit: {MaxRequests}
X-RateLimit-Window: {WindowSeconds}
X-RateLimit-Reset: {UnixSecondsAtWindowEnd}
Content-Type: application/json
```

```json
{
  "Ok": false,
  "Error": {
    "Code": "RateLimited",
    "Message": "Too many requests.",
    "Details": {
      "RetryAfterSeconds": 27,
      "Bucket": "verify:client",
      "WindowSeconds": 60
    }
  },
  "Meta": { "RequestId": "...", "ServerTimeUtc": "..." }
}
```

Rules:

- `Retry-After` seconds equal `WindowStartAt + WindowSeconds - Now`, minimum 1.
- `Details.Bucket` reports the bucket family (`verify:client`, `auth:email`, ...), never the raw `BucketKey`. Raw keys leak fingerprints.
- Never include the offending IP, email, or fingerprint value in the response body.

---

## 4. Abuse Prevention

Abuse rules are stricter than rate limits and trigger `AbuseBlocked` (HTTP 403), not `RateLimited`. Once triggered, the actor is blocked for a fixed penalty window regardless of subsequent traffic volume.

### 4.1 Triggers (closed set for V1)

| Rule ID | Condition | Scope | Penalty | Action emitted |
|---------|-----------|-------|---------|----------------|
| `AR-VERIFY-INVALID-BURST` | 20 rejected `POST /Verify/Hash` (`HashKeyRejected`) in 60s. | `client:{ClientId}` | 600s block. | `AbuseBlocked` |
| `AR-VERIFY-SERIAL-FLOOD` | 30 distinct `SerialId` values on `POST /Verify/Final` from one fingerprint in 300s. | `fp:{h}` | 900s block. | `AbuseBlocked` |
| `AR-LOGIN-BRUTE` | 15 failed `POST /Auth/Token` (`AuthLoginFailed`) in 300s from one IP. | `ip:{Ip}` | 900s block. | `AbuseBlocked` |
| `AR-REFRESH-REUSE` | Any `AuthRefreshReused` audit event. | `actor:User:{UserId}` | Immediate session revoke plus 3600s block. | `AbuseBlocked` |
| `AR-OAUTH-UNKNOWN-CLIENT` | 10 `OAuthClientUnknown` on `POST /OAuth/Token` in 60s from one IP. | `ip:{Ip}` | 1800s block. | `AbuseBlocked` |

### 4.2 Block storage

Blocks are stored as `RateLimitBuckets` rows with `BucketKey` prefixed `block:{Rule}:{Key}` and `WindowStartAt` set to the block start, `RequestCount` set to 1. Middleware checks for a live block row before consulting quantitative buckets. A single dedicated column is not added in V1 to keep the schema thin.

### 4.3 Audit correlation

Every abuse rule fire emits an `AbuseBlocked` audit row per [`13-audit-logging.md`](./13-audit-logging.md) with `PayloadJson`:

```json
{ "Rule": "AR-VERIFY-INVALID-BURST", "WindowSeconds": 60, "PenaltySeconds": 600 }
```

Raw IPs, emails, and fingerprints are never written to `PayloadJson`; only the rule id, window, and penalty. `ActorType` and `ActorId` on the audit row identify the entity via existing FKs when known, else `AnonymousActor`.

---

## 5. Exemptions

- Health probes: `GET /Healthz` and `GET /Readyz` are exempt from all buckets.
- Admin break-glass: v1 has no `SuperAdmin` role (see [`04-roles.md`](./04-roles.md) §Role Enum). A single active `Admin` bypasses `actor:` buckets on admin routes only when the `X-Break-Glass: true` header is present AND the caller holds the `Admin` role; the request still passes global `ip:` floors and emits an `AdminBreakGlassUsed` audit row. Break-glass MUST NOT bypass abuse rules in §4.
- Internal cron: requests carrying a valid signed cron header from a stable Lovable URL bypass rate limits. Signature verification is mandatory; see the public-API guidance for signed cron endpoints.

No other exemptions. Support tools, dashboards, and internal scripts run through the standard buckets.

---

## 6. Observability

Every rejection increments Prometheus-style counters. Names are frozen here so dashboards do not drift:

- `laralicensing_ratelimit_rejections_total{Endpoint, Bucket}`
- `laralicensing_abuse_blocks_total{Rule}`
- `laralicensing_ratelimit_admissions_total{Endpoint, Bucket}`

Log lines on rejection MUST include `RequestId`, `Endpoint`, `Bucket` (family, not raw key), `RetryAfterSeconds`, and, for abuse, `Rule`. Silent rejection is a spec violation.

---

## 7. Acceptance Criteria

- AC-RL-001: Every endpoint listed in [`10-endpoints.md`](./10-endpoints.md) either appears in section 2 with explicit buckets or is documented as exempt in section 5. No third state.
- AC-RL-002: Every bucket key formula in section 1.1 has at least one endpoint in section 2 using it. Unused formulas are removed, not left dangling.
- AC-RL-003: Every abuse rule in section 4.1 has a matching `AbuseBlocked` audit payload contract and cites an existing `ErrorCode` or `Action` value; no new codes are introduced without updating [`12-error-taxonomy.md`](./12-error-taxonomy.md) and [`13-audit-logging.md`](./13-audit-logging.md) in the same change.
- AC-RL-004: The `RateLimited` response envelope in section 3 matches [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md) exactly; any change to the envelope updates both files.
- AC-RL-005: `Details.Bucket`, `PayloadJson`, and log lines never contain raw IP, email, fingerprint, or `BucketKey` values.
- AC-RL-006: `RateLimitBuckets` schema in [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) supports every key family in section 1.1 without new columns.
- AC-RL-007: `Details.Bucket` on every `RateLimited` response uses a value from the §8 closed set. Emitting any other string is a spec violation.
- AC-RL-008: The retry class advertised via `Retry-After` and `X-RateLimit-*` matches [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md): `RateLimited` -> `RetryAfter`; `AbuseBlocked` -> `NoRetry` (no `Retry-After` header emitted). The 403 abuse response MUST NOT carry `Retry-After`.

---

## 8. Bucket Family Closed Set

`Details.Bucket` in the §3 response body is drawn from this table only. Family names are stable; renames follow the same protocol as [`13-audit-logging.md`](./13-audit-logging.md) §"Renaming closed-set values" so historical log/metric dashboards do not silently break.

| Family | Key formula | Endpoints |
|--------|-------------|-----------|
| `auth:email` | `email:{h}` | `POST /Auth/Token`, `POST /Auth/Password/Forgot` |
| `auth:ip` | `ip:{Ip}` | `POST /Auth/Token`, `POST /Auth/Password/Forgot` |
| `auth:actor` | `actor:User:{UserId}` | `POST /Auth/Refresh` |
| `oauth:client` | `client:{ClientId}` | `POST /OAuth/Token` |
| `oauth:ip` | `ip:{Ip}` | `POST /OAuth/Token` |
| `verify:client` | `client:{ClientId}` | `POST /Verify/Hash`, `POST /Verify/Final` |
| `verify:fingerprint` | `fp:{h}` | `POST /Verify/Hash`, `POST /Verify/Final` |
| `verify:ip` | `ip:{Ip}` | `POST /Verify/Hash` |
| `verify:serial` | `serial:{SerialId}` | `POST /Verify/Final` |
| `licenses:actor` | `actor:User:{UserId}` | `POST /Licenses`, `POST /Licenses/{LicenseId}/Serials`, `POST /Licenses/{LicenseId}/Revoke`, `POST /Serials/{SerialId}/Revoke` |
| `admin:list` | `actor:User:{UserId}` | `GET /Admin/*` |
| `admin:roles` | `actor:User:{UserId}` | `POST /Admin/Users/{UserId}/Roles`, `DELETE /Admin/Users/{UserId}/Roles/{Role}` |
| `admin:idempotency-conflict` | `actor:User:{UserId}:IdempotencyConflict` | `POST /Licenses/{LicenseId}/Serials` on replay-with-mutation |
| `updates:manifest:ip` | `ip:{Ip}` | `GET /App/UpdateManifest` |
| `updates:manifest:client` | `client:{ClientId}` | `GET /App/UpdateManifest` |
| `updates:asset:ip` | `ip:{Ip}` | `HEAD /App/UpdateAsset/*`, `GET /App/UpdateAsset/*` |
| `updates:asset:client` | `client:{ClientId}` | `GET /App/UpdateAsset/*` |
| `updates:publish:ticket` | `actor:User:{UserId}` | `POST /Admin/AppUpdates/UploadTicket` |
| `updates:publish:manifest` | `actor:User:{UserId}` | `POST /Admin/AppUpdates` |
| `global:ip` | `ip:{Ip}` | Every request (§2.5 global floor) |

---

## 9. Retry Class Binding

This section is the single source of truth for how a caller should react to a rate-limit or abuse response. It binds every rejection outcome to a class in [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md) and a log level in [`21-error-management-binding.md`](./21-error-management-binding.md).

| Rejection | HTTP | `ErrorCode` | Retry class | Log level | Headers emitted |
|-----------|------|-------------|-------------|-----------|-----------------|
| Bucket exhausted (any §2 row) | 429 | `RateLimited` | `RetryAfter` | `Warn` | `Retry-After`, `X-RateLimit-*` |
| Global `global:ip` floor | 429 | `RateLimited` | `RetryAfter` | `Warn` | `Retry-After`, `X-RateLimit-*` |
| Idempotency-conflict bucket trip | 429 | `RateLimited` | `RetryAfter` | `Warn` | `Retry-After`, `X-RateLimit-*` |
| Abuse rule fire (§4.1) | 403 | `AbuseBlocked` | `NoRetry` | `Error` | none (no `Retry-After`) |

Callers MUST NOT retry a `NoRetry` response inside the same session; UI surfaces show a terminal error, not a countdown. The `<RetryAfterBanner>` component in the UI is bound to `RateLimited` only, per [`16-ui-surfaces.md`](./16-ui-surfaces.md).

---

## 10. Persistence and Redis Fallback

Rate-limit state is durable in `RateLimitBuckets` (see [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md) `RateLimitBuckets`). Redis is a hot-path cache, never a source of truth.

### 10.1 Read/write path

Order of operations on every rate-limited request:

1. Compute `BucketKey` and `WindowStartAt` per §1.
2. `INCR` Redis key `rl:{BucketKey}:{WindowStartAt}` with a `PEXPIRE` matching `ExpiresAt`. If the returned counter is `<= MaxRequests`, admit.
3. On `INCR` returning `> MaxRequests`, reject with `RateLimited` per §3. Do not touch the DB on the hot path.
4. A per-worker async flusher batches Redis counters to `RateLimitBuckets` at most every 5 seconds via `INSERT ... ON DUPLICATE KEY UPDATE RequestCount = GREATEST(RequestCount, VALUES(RequestCount)), SourceLastSyncedAt = NOW()`. Under the unique index (`BucketKey`, `WindowStartAt`) this is idempotent.

### 10.2 Fallback when Redis is unavailable

Redis outage MUST NOT drop rate limiting. On any Redis error (connection refused, timeout, `MOVED`/`ASK` with no cluster client, `OOM`):

1. Log at level `Warn` with `Component=ratelimit`, `RedisError={class}`, `RequestId`, and increment `laralicensing_ratelimit_redis_failures_total`.
2. Execute a DB-authoritative admission: `INSERT ... ON DUPLICATE KEY UPDATE RequestCount = RequestCount + 1` against `RateLimitBuckets` under the unique index; read back the row.
3. If `RequestCount > MaxRequests`, reject exactly as in §3; otherwise admit.
4. Block rules (§4.2) are always re-checked against the DB in fallback mode; a stale Redis view MUST NOT let a blocked actor through.

Fallback mode is a warning state, not a failure state; `GET /Readyz` MUST NOT flip red on Redis loss alone.

### 10.3 Consistency guarantees

- The DB row is the audit-of-record for compliance replays. Redis counters may be higher than DB (in-flight flush window) but MUST NOT be lower after a flush; the `GREATEST` clause enforces monotonic convergence.
- A window rollover creates a new `(BucketKey, WindowStartAt)` row; old rows are pruned per `ExpiresAt`, never mutated.
- `SourceLastSyncedAt` older than `WindowSeconds + 60s` on a live bucket is a flusher-lag alert, not a correctness bug.

### 10.4 Acceptance

- AC-RL-009: Redis outage produces DB-authoritative admissions and rejections; no request is silently admitted. Verified by a fault-injection test that kills Redis mid-run and asserts the response codes and counters in §10.2.
- AC-RL-010: `RateLimitBuckets` never records `RequestCount` lower than the value last observed in Redis for the same `(BucketKey, WindowStartAt)`. Verified by the flusher's `GREATEST` clause and a monotonicity test.
- AC-RL-011: `AuthSaltRotationFailed` and `AuthRefreshRaceLost` (see [`12-error-taxonomy.md`](./12-error-taxonomy.md)) are reserved codes; no rate-limit or abuse rule may emit them.
