# Analytics Event Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI + Worker. Single normative source for event names, property schemas, PII rules, sampling, transport, and consent gating.
**Owner:** Analytics governance. Every runtime `analytics.track(...)` MUST cite one row here.
**Related:** [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/28-audit-action-enum.md`](../21-app/28-audit-action-enum.md), [`../21-app/35-security-events.md`](../21-app/35-security-events.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md), [`57-keyboard-shortcut-registry.md`](./57-keyboard-shortcut-registry.md).

---

## 1. Purpose and scope

Pins the closed set of product-analytics events so runtime telemetry stays consistent, privacy-safe, and useful for measuring UX outcomes (funnel completion, error rates, feature adoption). Distinct from three sibling streams:

- **Audit log** (`../21-app/28-audit-action-enum.md`): legally-defensible, append-only, per-tenant, retained 7 years. Records WHO did WHAT to WHICH resource. Never sampled, never PII-redacted at write.
- **Log lines** (`../21-app/22-log-line-contract.md`): structured Worker logs for debugging. Retention 30 days.
- **Security events** (`../21-app/35-security-events.md`): auth failures, rate limits, suspicious activity. Retention 90 days.
- **Analytics** (this document): aggregate product usage. Retention 13 months. Consent-gated. Never receives raw resource IDs, emails, or free-text.

An event that fits two streams MUST fire on both streams with the SAME name and MUST cite this contract; correlation via `RequestId` per §7.

## 2. Transport and privacy posture

- Transport: HTTPS POST to `/api/public/analytics/ingest` per `../21-app/17-self-update-endpoint.md` §2 style (auth-free public endpoint gated by the analytics-collector shared secret).
- Payload: batched (up to 50 events, up to 30 s hold, up to 16 KB, whichever hits first). Batch fires immediately on `visibilitychange` -> `hidden` via `navigator.sendBeacon`.
- Consent gate: `AnalyticsEnabled` bit stored per-user in the `Users.PreferencesJson` column, default `false` for EU / UK / EEA IPs (detected at first `/me` response), default `true` elsewhere. UI Preferences page has a Switch labelled `Send anonymous usage data` per `56-` §4 canonical labels (label added there in the same version bump). Toggling the Switch takes effect on the NEXT event fire; already-queued events are dropped on toggle-off.
- Do-Not-Track: `navigator.doNotTrack === "1"` forces `AnalyticsEnabled = false` regardless of the stored preference.
- Session ID: opaque UUIDv7 minted on first non-consented event, rotated on sign-out, rotated on 30-minute idle, MUST NOT be persisted to localStorage (in-memory only).
- User ID: NEVER included. The analytics stream is pseudonymous by design; joining to a `UserId` is possible only via server-side `RequestId` correlation with the audit log, gated by an admin-only join query per `../21-app/35-security-events.md` §5.
- IP address: truncated to `/24` (IPv4) or `/48` (IPv6) at ingest before write.

## 3. Property grammar

Every event has these three property groups:

### 3.1 Identity properties (auto-attached, never in the runtime call site)

| Property | Type | Source | PII |
|---|---|---|---|
| `SessionId` | UUIDv7 | in-memory per session | No (opaque) |
| `AnonymousId` | UUIDv7 | localStorage `lara.anon` (13-month lifetime) | No (opaque) |
| `RequestId` | UUIDv7 | echoed from the mutation that triggered the event | No |
| `AppVersion` | SemVer | `import.meta.env.VITE_APP_VERSION` | No |
| `Environment` | enum `Production`/`Staging`/`Development` | `import.meta.env.VITE_ENV` | No |
| `ReceivedAtUtc` | ISO 8601 UTC Z | server-assigned at ingest | No |

### 3.2 Context properties (auto-attached from the current route)

| Property | Type | Source | PII |
|---|---|---|---|
| `Route` | string (route pattern, NOT the resolved URL) | TanStack Router `useMatchRoute()` | No if pattern (`/admin/licenses/$licenseId`); YES if resolved (`/admin/licenses/8f...`) |
| `RoleScope` | enum from `../21-app/04-roles.md` | current session | No |
| `TenantScope` | enum `Admin`/`Reseller`/`Builder`/`EndUser`/`Anonymous` | current session | No |
| `Viewport` | enum from `29-responsive-matrix.md` breakpoints | `matchMedia` at fire time | No |
| `ReducedMotion` | boolean | `matchMedia('(prefers-reduced-motion: reduce)')` | No |
| `Locale` | BCP 47 tag | `navigator.language` (kept because it's coarse) | No |
| `Theme` | enum `Light`/`Dark`/`System` | current theme setting | No |

- `Route` MUST be the route pattern, never the resolved URL. Sending `/admin/licenses/8f2c...` leaks the LicenseId; sending `/admin/licenses/$licenseId` does not. Linter enforces per §11.

### 3.3 Event properties (per-event schema, defined in §4)

- Property names in `PascalCase` matching `../21-app/22-log-line-contract.md` §3.
- Property values from closed sets ONLY. Free-text properties (`Reason`, `Justification`, `Description`, `Email`, `Label`, `SearchQuery`) BANNED. Free-text properties are the primary PII leak vector.
- Numeric properties MUST be non-negative integers or buckets from §6. Raw amounts (money, hours, byte counts) BANNED; bucket them.
- Resource IDs (`LicenseId`, `SerialId`, `UserId`, `ResellerId`, `ClientId`, `FeatureKey`, `TenantId`, etc.) BANNED as properties. Correlation to a specific resource is via `RequestId` join per §2.
- `Duration` properties in milliseconds, integer, bucketed per §6.
- Boolean properties preferred over enum-with-two-values.
- Property count per event MUST NOT exceed 12. Bloated schemas signal event-splitting is needed.

## 4. Event registry (closed set)

Events are namespaced `Domain.Action.Outcome` in `PascalCase.PascalCase.PascalCase`. Adding a new event REQUIRES a new row here.

### 4.1 Navigation and shell

| Event | Trigger | Event properties |
|---|---|---|
| `Route.Viewed` | route match settled + loader resolved | `LoaderDurationBucket`, `IsInitialLoad` |
| `Route.Errored` | route error boundary rendered | `ErrorCode` (from `12-error-taxonomy.md`), `Boundary` (enum `Route`/`Root`) |
| `Route.NotFound` | 404 boundary rendered | `MatchedPrefix` (`/admin`, `/reseller`, `/builder`, `/me`, `/auth`, `unknown`) |
| `Nav.PrimaryClicked` | primary nav item clicked | `NavItem` (enum from `13-navigation-ia.md`) |
| `Nav.BreadcrumbClicked` | breadcrumb crumb clicked | `Depth` (0..6) |
| `CommandPalette.Opened` | `Mod+K` or Button opened Palette | `Trigger` (enum `Hotkey`/`Button`) |
| `CommandPalette.Executed` | Palette result selected | `Category` (enum `Route`/`Action`/`Recent`), `ResultRank` (0..49) |

### 4.2 Auth (aligns with `../21-app/35-security-events.md`)

| Event | Trigger | Event properties |
|---|---|---|
| `Auth.SignInSubmitted` | Sign in form submitted | (no properties beyond identity + context) |
| `Auth.SignInSucceeded` | session cookie established | `MfaUsed` (boolean) |
| `Auth.SignInFailed` | denial rendered | `ErrorCode` (always `AuthFailed`; never differentiate) |
| `Auth.SignedOut` | sign out completed | `Scope` (enum `Current`/`Everywhere`) |
| `Auth.SessionRefreshed` | silent token refresh succeeded | (none) |
| `Auth.SessionExpired` | 401 caught in the query layer | (none) |

### 4.3 List surfaces (Tables per `24-component-table.md`)

| Event | Trigger | Event properties |
|---|---|---|
| `List.Loaded` | first list response resolved for a route | `RouteFamily` (enum `Licenses`/`Serials`/`Users`/`Quotas`/`Features`/`Clients`/`Updates`/`Devices`/`Sessions`), `RowCountBucket`, `FilterCount` (0..N), `SortField` (from a per-family whitelist; `unset` if default), `SortDirection` (enum `Asc`/`Desc`), `Page` (1..N capped at 999) |
| `List.FilterApplied` | filter Field committed (debounced 300 ms) | `RouteFamily`, `FilterField` (from per-family whitelist), `FilterKind` (enum `Equals`/`Contains`/`Range`/`In`), `ResultCountBucket` |
| `List.FilterCleared` | reset-filters Button clicked | `RouteFamily`, `FilterCount` |
| `List.SortChanged` | column header toggled sort | `RouteFamily`, `SortField`, `SortDirection` |
| `List.PageChanged` | pagination clicked | `RouteFamily`, `Page`, `PageSize` (enum from `24-` §7) |
| `List.RowOpened` | row clicked / Enter pressed | `RouteFamily`, `RowRank` (0..49) |
| `List.ExportRequested` | CSV export triggered | `RouteFamily`, `RowCountBucket` |
| `List.ExportCompleted` | export download finished | `RouteFamily`, `RowCountBucket`, `DurationMsBucket` |
| `List.ExportFailed` | export request errored | `RouteFamily`, `ErrorCode` |

### 4.4 Mutations (aligns with `../21-app/28-audit-action-enum.md`)

Two events per mutation: `Started` and `Resolved`. `Resolved` carries `Outcome` (enum `Success`/`Failure`) and `ErrorCode` (from `12-error-taxonomy.md`, empty on Success).

| Event family | `Started` trigger | `Resolved` trigger | Event properties |
|---|---|---|---|
| `License.Issue` | Issue license Button submitted | mutation response received | `Outcome`, `ErrorCode`, `Tier`, `Environment`, `DurationMsBucket`, `FeatureCountBucket`, `MachineBindingCountBucket` |
| `License.Renew` | Renew Dialog submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `License.Revoke` | Revoke Dialog phrase-typed + submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `Serial.Issue` | Serial issue submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `Serial.Verify` | Verify handshake submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket`, `Source` (enum `AdminUi`/`ResellerUi`/`EndUserUi`/`ClientApp`) |
| `Serial.Revoke` | Revoke submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `Client.Register` | Register Client submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `Client.RotateSecret` | Rotate Dialog submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket` |
| `Update.Publish` | Publish update submitted | response received | `Outcome`, `ErrorCode`, `DurationMsBucket`, `Channel`, `SizeMbBucket` |
| `Update.Retract` | Retract Dialog submitted | response received | `Outcome`, `ErrorCode`, `Channel`, `DurationMsBucket` |
| `Quota.Requested` | quota request submitted (reseller portal) | response received | `Outcome`, `ErrorCode`, `DeltaBucket` |
| `Quota.Decided` | admin decision submitted (Approve/Adjust/Deny) | response received | `Outcome`, `ErrorCode`, `Decision` (enum), `DeltaBucket` (0 for Deny) |
| `User.Invited` | invite submitted | response received | `Outcome`, `ErrorCode`, `RoleCountBucket` |
| `User.RoleAssigned` | assign-role submitted | response received | `Outcome`, `ErrorCode`, `Role` (from `04-roles.md` enum) |
| `User.RoleRemoved` | remove-role submitted | response received | `Outcome`, `ErrorCode`, `Role` |
| `User.Disabled` | disable submitted | response received | `Outcome`, `ErrorCode` |
| `Feature.Added` | add feature submitted | response received | `Outcome`, `ErrorCode` |
| `Feature.Deprecated` | deprecate submitted | response received | `Outcome`, `ErrorCode` |
| `Feature.TierMatrixToggled` | Switch commit fired (300 ms debounce per `38-` §5) | response received | `Outcome`, `ErrorCode`, `Tier`, `TogglingTo` (boolean) |
| `Device.RebindRequested` | end-user rebind request submitted | response received | `Outcome`, `ErrorCode` |
| `Device.RebindDecided` | admin decision on rebind | response received | `Outcome`, `ErrorCode`, `Decision` (enum `Approve`/`Deny`) |
| `Session.RevokeEverywhere` | Sign out everywhere phrase-typed | response received | `Outcome`, `ErrorCode` |
| `Certificate.Downloaded` | PDF download completed | client-observed | `RouteFamily` (`License`/`Serial`), `DurationMsBucket` |
| `Product.InstallReveal` | end-user revealed opaque install command (once, gated) per `41-` §5; plaintext NEVER logged | response received | `Outcome`, `ErrorCode`, `LicenseIdFingerprint` (SHA-256 8-char) |
| `Product.Reverify` | end-user re-verified product entitlement per `41-` §5 | response received | `Outcome`, `ErrorCode` |


### 4.5 UX quality signals

| Event | Trigger | Event properties |
|---|---|---|
| `Loading.LongWait` | route or list crossed 2 s threshold (Banner per `54-` §3) | `Surface` (enum), `MaxWaitReached` (boolean; true when 8 s abort fired) |
| `Retry.Clicked` | user clicked Retry on an error surface | `ErrorCode`, `Attempt` (1..3) |
| `Form.ValidationFailed` | client-side validation blocked submit | `FormId` (from per-blueprint whitelist), `FieldCount` (1..12) |
| `Copy.ClipboardUsed` | clipboard copy Button clicked | `Target` (enum `ClientSecret`/`SerialValue`/`RequestId`/`ErrorId`/`CertificateLink`) |
| `Keyboard.ShortcutUsed` | any registered hotkey fired | `Shortcut` (enum from `57-` §5 / §6) |
| `A11y.ReducedMotionDetected` | first render under reduced motion | (none) |
| `Print.KeyboardShortcutsOpened` | `?` opened the Shortcuts Dialog | `Route` |

## 5. Correlation between streams

- Every mutation `Started` event MUST carry a `RequestId` that MATCHES the `X-Request-ID` header on the outbound HTTP request AND the audit log row's `RequestId` field.
- Every `Resolved` event MUST carry the SAME `RequestId` as its `Started` event.
- Server-side join between analytics and audit is done ONLY by admins via a JOIN on `RequestId`, gated by `../21-app/28-audit-action-enum.md` §7 access control. A regular analytics query never sees `UserId` or `LicenseId`.
- The audit log NEVER references analytics event properties; audit is legally-defensible ground truth, analytics is a derived signal.

## 6. Buckets (closed sets)

Raw numeric values BANNED for the following properties. Buckets pinned here:

- `RowCountBucket`: `0` / `1-10` / `11-100` / `101-1000` / `1001-10000` / `10001+`. Note: `55-print-export-stylesheet.md` §6 caps CSV export at `100000` rows, which falls inside the top `10001+` bucket; no bucket split needed (per audit CR-03 F18).
- `DurationMsBucket`: `0-50` / `51-200` / `201-500` / `501-1000` / `1001-2000` / `2001-5000` / `5001-10000` / `10001+`.
- `LoaderDurationBucket`: same as `DurationMsBucket`.
- `SizeMbBucket`: `0-1` / `1-5` / `5-25` / `25-100` / `101-500` / `501+`.
- `DeltaBucket`: `1` / `2-5` / `6-25` / `26-100` / `101-500` / `501+`.
- `FeatureCountBucket`: `0` / `1-3` / `4-10` / `11+`.
- `MachineBindingCountBucket`: `1` / `2-3` / `4-10` / `11+`.
- `RoleCountBucket`: `1` / `2-3` / `4+`.
- `ResultCountBucket`: same as `RowCountBucket`.
- `FilterCount`: raw integer 0..20 permitted (bounded).
- `Attempt`: raw integer 1..3.
- `ResultRank`: raw integer 0..49.
- `RowRank`: raw integer 0..49.

Bucketing math lives in `src/lib/analytics-buckets.ts` (single source, tested).

## 7. PII rules (BANNED as properties or values)

- `Email`, `UserName`, `Label`, `Description`, `Reason`, `Justification`, `SearchQuery`, `FeatureKey` (value), `SemVer` (as free text), `IpAddress`, `Fingerprint`, `MachineHash`, `SerialValue`, `ClientSecret`, `AccessToken`, `RefreshToken`, `RequestBody`, `ResponseBody`.
- Any UUID / opaque ID except `SessionId` / `AnonymousId` / `RequestId`.
- Any free-text field.
- Any user-controlled value.
- Any prompt content.

Linter enforces via §11.

## 8. Sampling

- No client-side sampling for mutation events (§4.4). Every mutation fires exactly one `Started` and one `Resolved`.
- 10% sampling for `Route.Viewed`, `List.Loaded`, `Keyboard.ShortcutUsed`, `Copy.ClipboardUsed`. Sampling decision made from `AnonymousId` hashed to `[0, 100)`, deterministic per user.
- 100% sampling for error events (`Route.Errored`, `List.ExportFailed`, any `Resolved` with `Outcome=Failure`, `Loading.LongWait`, `Retry.Clicked`, `Form.ValidationFailed`) so failure rates are exact.
- Sampling flag is included in the payload (`SamplingRate` in `[0.0, 1.0]`) so aggregation can scale-up correctly.

## 9. Consent and legal

- `AnalyticsEnabled = false` by default in EU / UK / EEA regions per §2.
- Preferences page includes a link to the privacy notice (`import.meta.env.VITE_PRIVACY_URL`) and a `Delete my analytics data` Button which fires `DELETE /api/analytics/anonymous/{AnonymousId}` (public endpoint gated by proof-of-cookie via a signed anonymous-id challenge).
- Consent is per-user (stored in `Users.PreferencesJson`), not per-tenant. A reseller cannot enable analytics for their end users.
- Retention: 13 months rolling. Older events deleted daily by a scheduled job.
- Data-subject access request (DSAR): admin export produces a per-`AnonymousId` CSV within 30 days.

## 10. Failure modes

- Ingest 429: retry batch after `Retry-After` with jitter, up to 3 attempts, then drop the batch and log a Worker warning (never a Toast; analytics failures are silent to the user per `56-` §11 no-reassurance-theatre rule for a system that failed non-materially).
- Ingest 5xx: same retry policy.
- Offline: batch queued in-memory (never persisted); `visibilitychange` -> `hidden` fires `sendBeacon` best-effort; loss on hard-close is accepted.
- Payload > 16 KB: split into 2+ batches; single event > 8 KB is a bug and the event is dropped with a Worker error log.
- Ingest endpoint down > 5 minutes: circuit breaker opens for 60 s; every event is dropped during the open state; a single circuit-breaker-open log line fires per state change.

## 11. Linter (`check-analytics-events.py`)

New linter under `linter-scripts/check-analytics-events.py`:

- Scans `src/**/*.ts(x)` for `analytics.track(...)` calls.
- Cross-references event names with §4 registry.
- Fails on: event name not in registry, property not in event's §4 schema, property in §7 PII list, raw number instead of §6 bucket, resource ID (any UUID) in properties, free-text property, property count > 12, `Route` resolved instead of pattern, missing `Started` counterpart for a `Resolved` event, sampling rate not in §8.
- Also greps `src/lib/copy.ts` for any strings sneaking into analytics payloads (a common leak vector during refactor).
- Runs in CI and via `./linter-scripts/run.sh check-analytics-events`.

## 12. Anti-patterns (BANNED)

1. Any property from §7.
2. Any resource UUID as a property.
3. Free-text property.
4. Raw numeric property outside §6 buckets.
5. Client-side sampling for mutation or error events.
6. Analytics failure surfaced to the user as a Toast or Banner.
7. Persisting `SessionId` to localStorage.
8. Reading DoNotTrack once at boot and caching indefinitely (must re-read on preference change).
9. Firing analytics before consent gate check.
10. Event property count > 12.
11. Two events for the same action with different names on different surfaces.
12. `Route` sent as resolved URL instead of route pattern.
13. `Started` event without a matching `Resolved` (or `Resolved` without matching `Started`).
14. `RequestId` on analytics event differing from the `X-Request-ID` header on the underlying HTTP request.
15. Reading `UserId` inside `analytics.track(...)` (identity is server-side join only).
16. Sending analytics from the Worker with a service-role identity attached.
17. Retention exceeding 13 months.
18. Sampling rate applied without including `SamplingRate` in payload.
19. Adding an event without adding a row to §4.
20. Storing analytics events in the audit log or vice versa.

## 13. Acceptance criteria

- AC-ANALYTICS-001: Every runtime `analytics.track(...)` cites a §4 row.
- AC-ANALYTICS-002: No property from §7 appears in any payload.
- AC-ANALYTICS-003: Numeric properties use §6 buckets exclusively.
- AC-ANALYTICS-004: Consent gate blocks all events for `AnalyticsEnabled=false` users.
- AC-ANALYTICS-005: `Route` is always the route pattern, never the resolved URL.
- AC-ANALYTICS-006: `RequestId` correlates 1:1 between analytics and audit log.
- AC-ANALYTICS-007: Sampling per §8 exact for mutations and errors, 10% for high-volume view events.
- AC-ANALYTICS-008: `check-analytics-events.py` passes.

## 14. Open items

- Server-side ingest schema and storage layout deferred to a coming `spec/22-worker/` document.
- DSAR export UI deferred; admin CLI is sufficient for v1.
- Sampling adjustments per environment (`Development` = 100% for debugging) deferred; single sampling profile in v1.
- Attribution / marketing analytics explicitly out of scope (no marketing surfaces in v1).
