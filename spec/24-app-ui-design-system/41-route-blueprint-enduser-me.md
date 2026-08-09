# Route Blueprint: End-user Me (`/me`, `/me/products`, `/me/products/:LicenseId`, `/me/devices`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Ninth route blueprint; extends the template established by `33-`..`40-`. Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the End-user self-service surface (own products / activations, bound devices, session posture).
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`21-component-dialog.md`](./21-component-dialog.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md), [`39-route-blueprint-reseller-portal.md`](./39-route-blueprint-reseller-portal.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/07-serial-generation.md`](../21-app/07-serial-generation.md), [`../21-app/09-verify-key.md`](../21-app/09-verify-key.md), [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md), [`../21-app/15-license-lifecycle.md`](../21-app/15-license-lifecycle.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/30-machine-bindings.md`](../21-app/30-machine-bindings.md), [`../21-app/31-auth-session-family.md`](../21-app/31-auth-session-family.md), [`../21-app/32-auth-session-retention.md`](../21-app/32-auth-session-retention.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

End-user role self-service surface. RLS-scoped to the caller's `UserId`; server MUST reject cross-user access with `404 LicenseNotFound` / `404 DeviceNotFound` (never `403`) per `12-error-taxonomy.md` scope-hiding, matching `39-` §2 and `40-` §2. UI MUST NEVER show a `UserId` selector.

Routes:

- `/me` overview (identity summary, active session list, quick links to products + devices).
- `/me/products` list of the caller's active licenses / activations.
- `/me/products/:LicenseId` detail (product metadata, install / re-verify actions, activation history).
- `/me/devices` list of bound devices with per-device rebind-request and revoke actions.

Out of scope: subscription / billing surfaces (deferred, not part of LaraLicensingV1 scope). Password / OAuth linkage changes (owned by `42-route-blueprint-auth-and-403-404-500.md`, next step).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/me` | `src/routes/_authenticated/me/index.tsx` | `Me.View` | `ensureQueryData(meOverviewQuery())` + `ensureQueryData(meSessionsQuery())` via `Promise.all` |
| `/me/products` | `src/routes/_authenticated/me/products/index.tsx` | `Me.Products.Read` | `ensureQueryData(meProductsListQuery(searchParams))` |
| `/me/products/:LicenseId` | `src/routes/_authenticated/me/products/$LicenseId.tsx` | `Me.Products.Read` | `ensureQueryData(meProductDetailQuery({ LicenseId }))` + `ensureQueryData(meProductActivationHistoryQuery({ LicenseId }))` |
| `/me/devices` | `src/routes/_authenticated/me/devices/index.tsx` | `Me.Devices.Read` | `ensureQueryData(meDevicesListQuery(searchParams))` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission keys per `40-permissions.md` §1 (Me.* set). Denial renders 403 terminal card per `16-route-shell-states.md` §4 (denial at end-user scope means the account is disabled or a required TOS acceptance is outstanding; copy MUST cite the specific reason from the error envelope per `12-error-taxonomy.md`, silent 403 BANNED).
- Cross-user license OR device lookup MUST return `404` (not `403`) so foreign IDs are indistinguishable from missing IDs.
- Invalid-format `LicenseId` (must be UUID v4) renders 404 card, NEVER 400.
- Head metadata: overview `title = "My account - Lara Licensing"`; products list `title = "My products - Lara Licensing"`; detail `title = \`${ProductLabel} - Lara Licensing\`` where `ProductLabel` is the license's product display name (`ShortId` fallback when `ProductLabel` is null); devices `title = "My devices - Lara Licensing"`; no `og:image`.
- Search params (products list, `validateSearch`): `q` (string ≤ 128 chars, server-side prefix match on ProductLabel), `Status` (enum from `15-license-lifecycle.md` closed set: `Active`/`Expired`/`Revoked`), `PageIndex`, `PageSize` closed set `{25, 50, 100}`, `Sort` closed set default `IssuedAtDesc`. Filters visible ONLY when caller has more than `PageSize` licenses; small-catalog optimisation per `27-content-voice.md` §6 to reduce chrome for the common case.
- Search params (devices list, `validateSearch`): `Status` (enum: `Active`/`Revoked`), `PageIndex`, `PageSize`, `Sort` closed set default `LastSeenAtDesc`.

## 3. Layout

Overview:

```
Shell > PageHeader (H1 "My account", subhead: DisplayName + email masked per 36- §5,
                    RightActions: Sign out everywhere...)
  > Three summary Cards (single-column at XS, three-column at MD+ container-query):
      Card A: Products (count + <Link> "View products")
      Card B: Devices (count + <Link> "View devices")
      Card C: Sessions (count + <Link> that scrolls to the sessions list below)
  > Sessions Table (compact, current-session row pinned with a "This device" Badge per 25-)
```

Products list:

```
Shell > PageHeader (H1 "My products", RightActions: Verify by serial...)
  > Filter bar (rendered only when count exceeds PageSize, see §2)
  > Table with columns: Product (Label + short-id stacked), Status, IssuedAt, ExpiresAt, Devices (count),
                        Actions
  > Empty-state card copy `You do not have any products yet. Verify a serial to activate one.`
    per 27- §6 empty-state voice; classified as [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 **First-run** variant with a primary CTA `Verify by serial...` (no permission gate for end-users on this route). Route-shell skeleton is Mode A per [`54-loading-state-catalog.md`](./54-loading-state-catalog.md) §2; the products Table skeleton is Mode B (List). Product detail below uses Mode A route-shell skeleton over cached Sidebar + App bar.

```

Product detail:

```
Shell > Breadcrumb (Me > Products > <ProductLabel>)
  > PageHeader (H1 "<ProductLabel>", subhead: Status Badge + IssuedAt + ExpiresAt,
                RightActions: Install..., Re-verify..., Rebind device...)
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: License metadata card (immutable Fields, all Admin-scope Fields hidden not rendered)
                Bound device card (per bound device: DeviceLabel, LastSeenAt, Rebind... action)
      Column B: Activation history Table (compact, end-user-visible subset only; server filters Admin rows)
```

Devices list:

```
Shell > PageHeader (H1 "My devices", RightActions: (none))
  > Table with columns: Device (Label + FingerprintPreview redacted per 35- §8), Status, LastSeenAt,
                        BoundProducts (count with Popover per 22-), Actions
```

## 4. PageHeader actions

- `Verify by serial...` (products list): opens the Verify Dialog. Permission `Me.Products.Verify`. Server POST to the end-user verify endpoint per the current verify-handshake decision (POST, per `spec/21-app/03-authentication-oauth.md` and Issue 03 closure); the flow MUST match `spec/21-app/diagrams/licensing-flow.mmd`.
- `Install...` (product detail): opens a Reveal Dialog that returns the opaque install command ONCE (never cached, never logged, mirroring `39-` §4 rules) and shows the mandatory `I have copied the install command` confirmation Checkbox before close, mirroring `40-` §4 secret-reveal pattern.
- `Re-verify...` (product detail): non-destructive Dialog that triggers a fresh verify handshake and updates the local session posture. Emits `ProductReverifyConfirmed` and `ProductReverifyExecuted`|`Failed`.
- `Rebind device...` (product detail): opens the Rebind Request Dialog. End-user Rebind is NOT a direct action; it CREATES a request row that Admin decides per `37-route-blueprint-admin-quota-approvals.md` §9 pattern (queue-and-approve). The Dialog copy MUST state `Your builder or Admin must approve the new binding. You will see the change here once approved.` Direct rebind at end-user scope BANNED (see §12 anti-pattern 3).
- `Sign out everywhere...` (overview): destructive per `21-component-dialog.md` §5 phrase-typing (`SIGN OUT`) with mandatory `Reason` OPTIONAL (Sign-out-everywhere is a security action; Reason encouraged but not required because the user might not know why, per `27-content-voice.md` §5 destructive triad; adding a mandatory Reason would cause abandonment). Emits `SessionRevokeAllConfirmed` and `SessionRevokeAllExecuted`|`Failed`. Current session is invalidated LAST so the confirmation Toast renders before the redirect to `/auth/signed-out`.
- `Revoke...` (per-session row Actions): destructive phrase-typing (session `Nickname` OR device fingerprint short-id). Emits `SessionRevokeConfirmed` and `SessionRevokeExecuted`|`Failed`.

All actions permission-hidden per `32-` §5; XS/SM collapse per `29-responsive-matrix.md`.

## 5. Table contracts

Products list: `Product` cell full-cell `<Link>` to `/me/products/:LicenseId`; whole-row click BANNED. Status Badge glyph-plus-color-plus-text per `25-` §5. Dates via `format-date.ts` UTC tooltip; relative-time-as-primary BANNED. `Devices` count is a plain integer (nested `<Link>` to `/me/devices?LicenseId=<LicenseId>` filter param is BANNED because devices list has no `LicenseId` filter; instead the count opens a Popover per `22-` §6 listing the bound device labels). Actions Menu: `Re-verify`, `Install...`, `Rebind device...`.

Devices list: `Device` cell is NOT a full-cell `<Link>` because there is no per-device detail route in v1 (deferred, §14); rendering an inert `<span>` styled as a `<Link>` is BANNED per `40-` §12 anti-pattern 12; `Device` cell is plain text with an Actions Menu. `FingerprintPreview` shows the last 8 chars of the SHA-256 fingerprint per `30-machine-bindings.md` with a Reveal action gated by a second-Dialog confirmation matching `35-` §8 hash-key redaction rules; full fingerprint VALUES NEVER logged.

Sessions Table (overview): current-session row pinned to top with `This device` Badge and Revoke disabled on that row (current-session revoke MUST route through `Sign out everywhere...` to guarantee a controlled redirect). Rows carry `SessionFamilyId` short-id, `IssuedAt`, `LastUsedAt`, `IpApproxRegion` (city-level or coarser, NEVER raw IP per `27-` §9 and `35-security-events.md`), `UserAgentSummary` (browser + OS family only, NEVER full UA string).

## 6. Filtering, search, sort

- SearchInput on products list renders only when count exceeds `PageSize`. Debounce 300 ms server-side match on ProductLabel prefix. Serial VALUES NEVER queried from URL params (verify path is a POST Dialog, not a URL search).
- Empty results render Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters` action).

## 7. Route states

Identical seven-row state table to `34-` §7. Partial failure on Overview: if `meSessionsQuery` fails while `meOverviewQuery` loads, Sessions Table renders its own Banner AND `Sign out everywhere...` DISABLES with helper text `Session list unavailable, retry before signing out.` (this is a security-sensitive action; signing out everywhere with an unknown session count is dangerous). Fallback-to-empty BANNED.

## 8. Data contract

- Query keys: `["Me.Overview"]`, `["Me.Sessions"]`, `["Me.Products.List", <serializedSearchParams>]`, `["Me.Products.Detail", LicenseId]`, `["Me.Products.ActivationHistory", LicenseId]`, `["Me.Devices.List", <serializedSearchParams>]`. NO key carries a `UserId` value (server RLS is authority).
- Sign-out-everywhere invalidates `["Me.Sessions"]`, `["Me.Overview"]` in one call, then triggers the redirect. Per-session Revoke invalidates the same set.
- Re-verify invalidates `["Me.Products.Detail", LicenseId]` and `["Me.Products.ActivationHistory", LicenseId]` in one call.
- Rebind-request invalidates `["Me.Products.Detail", LicenseId]` (to show the Pending request row) and DOES NOT invalidate any Admin-scope key (the Admin queue lives in `["Admin.Quotas"]` prefix at a different scope).
- `useSuspenseQuery` + `ensureQueryData`; `useQuery`+`isLoading` BANNED.
- Optimistic mutations BANNED, including Sign-out (session tokens must be confirmed invalidated server-side before the client discards them).
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open.
- Rate-limit `RetryAfterBanner` per `14-rate-limiting.md`; end-user verify + rebind-request buckets are TIGHTER than admin buckets and MUST surface Retry-After clearly.

## 9. Dialogs invoked

- `Verify by serial...` Dialog: form with `Serial` Field (masked-input per `18-component-input.md` §7 to prevent shoulder-surfing; format `XXXX-XXXX-XXXX-XXXX` per `07-serial-generation.md`), optional `DeviceLabel` Field ≤ 60 chars. Server POST verify handshake per Issue 03 closure. `Serial` VALUE NEVER logged (fingerprint-only per `27-` §9). Emits `SerialVerifyConfirmed` and `SerialVerifyExecuted`|`Failed`.
- `Install...` Dialog: Reveal card returns the opaque install command ONCE gated by mandatory `I have copied the install command` Checkbox; plaintext discarded on close and NEVER re-retrievable (Re-open triggers a fresh server call with a new `Idempotency-Key`, treated as a fresh reveal event). Emits `ProductInstallRevealConfirmed` and `ProductInstallRevealExecuted`|`Failed`.
- `Re-verify...` Dialog: non-destructive single-Button confirmation. Emits `ProductReverify*`.
- `Rebind device...` Dialog: form with target `DeviceLabel` + `Fingerprint` capture (client-side hash in a Worker, plaintext fingerprint NEVER sent to the server or logged; only the SHA-256 hash per `30-machine-bindings.md`), mandatory `Justification` Field ≤ 240 chars. Copy states approval is required per §4. Emits `RebindRequestConfirmed` and `RebindRequestExecuted`|`Failed`. `JustificationFingerprint` logged, VALUE never.
- `Sign out everywhere...` Dialog: destructive phrase-typing (`SIGN OUT`), optional Reason. Confirmation Toast renders BEFORE the redirect to `/auth/signed-out`; the current session token is discarded LAST and only after the server has confirmed all-session revocation. Emits `SessionRevokeAll*`.
- `Revoke...` per-session Dialog: destructive phrase-typing (session Nickname). Emits `SessionRevoke*`.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (overview): Sidebar > Breadcrumb > Sign out everywhere > Summary Cards Links > Sessions Table headers > rows.
- `Serial` Field carries `type="text"` with `autocomplete="off"` and `inputmode="none"` per `18-` §7 masked-input rules; assistive tech announces the format via `aria-describedby` in plain language (`Format: four groups of four alphanumeric characters separated by hyphens.`).
- Reveal cards (install command, fingerprint reveal) initial-focus on the Copy Button per `40-` §10 secret-reveal a11y rule.
- Current-session Revoke row carries `aria-disabled="true"` with a Tooltip explaining `Use Sign out everywhere to revoke the current session.`
- Rate-limit `RetryAfterBanner` `aria-live="polite"` countdown.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Me.Overview" | "Me.Products.List" | "Me.Products.Detail" | "Me.Devices.List"`, `A11yViolations: 0`, `LoadDurationMs`.
- `SerialVerify*`, `ProductInstallReveal*`, `ProductReverify*`, `RebindRequest*`, `SessionRevoke*`, `SessionRevokeAll*` with `LicenseId` / `SessionId` / `DeviceFingerprintHash` (SHA-256 8-char prefix), `IdempotencyKey`, `ErrorCode`, `RequestId`. `JustificationFingerprint` and `SerialFingerprint` (SHA-256 8-char) logged; Serial / DeviceFingerprint plaintext / install command / Justification / Reason / Session Nickname VALUES NEVER.
- Email logged only as masked form; raw email NEVER logged at end-user scope.
- Approximate region (city-level or coarser) logged for security events per `35-security-events.md`; raw IP NEVER.

## 12. Anti-patterns (BANNED)

1. Any UI element, query key, URL param, or log line carrying a `UserId` value.
2. Returning `403` for cross-user license OR device lookup (must be `404`).
3. Direct device rebind at end-user scope (must be a queued request approved by Admin or Builder).
4. Caching plaintext install command or Serial or raw device fingerprint in any query cache or Toast.
5. `Serial` value in a URL search param (verify path is a POST Dialog).
6. Whole-row click on Products or Devices list.
7. Inert `<span>` styled as `<Link>` on Devices list `Device` cell (must be plain text until per-device detail route exists).
8. Optimistic Sign-out or Revoke (server confirmation before client token discard).
9. `useQuery`+`isLoading` initial gate.
10. Silent 403 (denial reason MUST be surfaced) or silent 404 (invalid-format ID renders 404 card).
11. Raw IP or full User-Agent string in Sessions Table or telemetry.
12. Mandatory Reason on `Sign out everywhere...` (Reason is OPTIONAL to avoid abandonment on a security action).

## 13. Acceptance criteria

- AC-ROUTE-ME-001: All four routes render under `_authenticated`; permission denial renders 403 terminal card citing the specific reason from the error envelope.
- AC-ROUTE-ME-002: Cross-user license OR device lookup returns `404` and UI renders the 404 terminal card; invalid-format `LicenseId` renders 404 card.
- AC-ROUTE-ME-003: `Sign out everywhere...` phrase-typing (`SIGN OUT`) with OPTIONAL Reason; confirmation Toast renders BEFORE redirect; current session token discarded LAST after server confirmation.
- AC-ROUTE-ME-004: `Verify by serial...` posts (not GETs) per Issue 03 closure; Serial rendered in a masked Field with `aria-describedby` plain-language format helper; Serial VALUE NEVER in URL / query cache / log line.
- AC-ROUTE-ME-005: `Rebind device...` creates a queued request (never a direct mutation); Dialog copy states approval is required.
- AC-ROUTE-ME-006: When `meSessionsQuery` fails, `Sign out everywhere...` disables with helper text `Session list unavailable, retry before signing out.`; fallback-to-empty BANNED.
- AC-ROUTE-ME-007: Sessions Table displays approximate region (city-level or coarser) and browser + OS family only; raw IP and full UA string NEVER rendered or logged.
- AC-ROUTE-ME-008: Axe zero `serious`/`critical` at 360/768/1440; current-session Revoke row `aria-disabled="true"` with Tooltip explanation.

## 14. Open items (for follow-up commits)

- Per-device detail route (`/me/devices/:DeviceId`) deferred; when added, `Device` cell becomes a full-cell `<Link>`.
- End-user CSV export of Activation history deferred to v2 with mandatory `Idempotency-Key`.
- Passkey / hardware-key enrolment surface deferred; when built, MUST live under `/me/security` and NOT under `/me` overview to keep scope tight.
- Product tagging / labelling at end-user scope deferred; server writes only, no reseller-visible tag values in v1.
