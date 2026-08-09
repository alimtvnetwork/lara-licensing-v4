# UI Surfaces: LaraLicensingV1

**Version:** 1.1.0
**Updated:** 2026-07-16

Maps the four actors from `04-roles.md` to concrete UI routes, required role, and the contracts each screen consumes. Route paths use the TanStack Start flat file-based convention; role gating uses the `_authenticated` layout plus a role predicate resolved from `has_role(auth.uid(), Role)` (or its Laravel equivalent on the API target).

Contract references point at `spec/21-app/11-api-contracts/`. Error rendering follows `12-error-taxonomy.md`. Rate-limit headers surface via a shared toast per `14-rate-limiting.md` section 5. Every endpoint citation below carries a **Status** marker per [`24-vocabulary-normalization.md`](./24-vocabulary-normalization.md): `C` (canonical, listed in `10-endpoints.md`), `A` (alias for a canonical PascalCase path), or `D` (deferred to v1.1, screen renders an "Available in v1.1" placeholder in v1).

---

## 1. Actor Overview

| Actor | Landing Route | Access |
|-------|---------------|--------|
| `Admin` | `/admin` | Platform-wide. |
| `Reseller` | `/reseller` | Scoped to `AuthActor.ResellerId`. |
| `AppBuilder` | `/builder` | Scoped to own `ClientId`s. |
| `EndUser` | `/app` | Scoped to own `UserId`. |
| Public | `/`, `/auth/*`, `/verify` | No auth required. |

Role resolution runs on the server; unauthenticated users hit `/auth/sign-in`; authenticated users without the required role hit `/forbidden` and receive the `Forbidden` error envelope.

---

## 2. Public Surfaces

| Path | Purpose | Contract | Status |
|------|---------|----------|:------:|
| `/` | Marketing home, product summary, CTA to sign-in. | none | - |
| `/auth/sign-in` | Email/password sign-in, OAuth entry points. | `POST /Auth/Login`, `POST /Auth/OAuth/{Provider}/Start` | A |
| `/auth/sign-up` | Registration for `AppBuilder` and `EndUser` self-serve; `Admin` and `Reseller` seeded server-side. | `POST /Auth/Register` | A |
| `/auth/forgot` | Password reset request. | `POST /Auth/Password/Reset` | A |
| `/auth/callback/{provider}` | OAuth redirect target, exchanges code for session. | `POST /Auth/OAuth/{Provider}/Callback` | A |
| `/verify` | End-user-facing verification preview (read-only, no OAuth credential). | `POST /Verify/Hash` (public preview mode) | D |
| `/forbidden` | Rendered when a role check fails. | none | - |

---

## 3. Admin Surfaces (`Admin` role)

| Path | Purpose | Primary Contracts | Status |
|------|---------|-------------------|:------:|
| `/admin` | KPIs: licenses issued, active, expired, abuse blocks last 24h. | aggregate KPIs (no v1 endpoint) | D |
| `/admin/resellers` | List, create, deactivate resellers, manage `Prefixes`. | `GET/POST /Resellers`, `PATCH/DELETE /Resellers/{ResellerId}`, `GET/POST /Resellers/{ResellerId}/Prefixes`, `PATCH/DELETE /Prefixes/{PrefixId}` | C |
| `/admin/users` | Search users across tenants, assign roles via `UserRole`. | `GET/POST /Users`, `POST /Users/{UserId}/Roles`, `DELETE /Users/{UserId}/Roles`, `GET /Admin/Users/{UserId}/Roles` | C |
| `/admin/categories` | Manage `LicenseCategories` durations, `LicenseVariations`. | `LicenseCategories` and `LicenseVariations` mutation endpoints | D |
| `/admin/licenses` | Global license explorer, filter by state per `15-license-lifecycle.md`. | `GET /Licenses` | C |
| `/admin/licenses/$licenseId` | Detail with `Issue`, `Suspend`, `Reinstate`, `Renew`, `Revoke` actions. | `GET/PATCH/DELETE /Licenses/{LicenseId}`, `POST /Licenses/{LicenseId}/Serials` | C |
| `/admin/audit` | Audit log viewer with `RequestId` and `Action` filters. | `GET /AuditEvents` | A |
| `/admin/abuse` | Active `RateLimitBuckets` blocks and abuse rule hits. | `RateLimitBuckets` list endpoint | D |
| `/admin/app-updates` | List published manifests per `(Product, Platform, Channel)` with version, checksum, published-at, actor. Filter by channel. | `GET /App/UpdateManifest` (all channels), `GET /Admin/AppUpdates` | C |
| `/admin/app-updates/new` | Publish a new manifest: `Version`, `Channel`, per-platform artifact upload with client-computed `Sha256`, `ReleaseNotesUrl`, `MinRequiredVersion`. Dry-run preview mirrors `publish-lara.ps1 -DryRun` per [`18-publishing-powershell.md`](./18-publishing-powershell.md). Requires `has_role(Admin)`. | `POST /Admin/AppUpdates/UploadTicket`, `POST /Admin/AppUpdates` | C |
| `/admin/app-updates/$version` | Detail view: manifest JSON, per-platform asset table, verification history (`UpdateVerified` / `UpdateVerificationFailed` audit rows), rollback action. | `GET /AuditEvents?Action=Update*` | A |

---

## 3a. AppBuilder Update Surfaces (`AppBuilder` role)

The `AppBuilder` console consumes the same self-update contract as end-user desktop clients. `Beta` channel access requires `has_role(AppBuilder|Admin)` per [`17-self-update-endpoint.md`](./17-self-update-endpoint.md).

| Path | Purpose | Primary Contracts |
|------|---------|-------------------|
| `/builder/updates` | Available manifests for the caller's registered products, filterable by channel. Shows `UpdateAvailable` banner when the local `AppVersion` cookie or query param is behind `Version`. | `17-self-update-endpoint.md` `GET /App/UpdateManifest` |
| `/builder/updates/$version` | Manifest detail with per-platform asset `Sha256` and download button; `GET /App/UpdateAsset` link opens with `X-Request-Id` propagation per [`20-observability.md`](./20-observability.md). | `17-self-update-endpoint.md` `HEAD|GET /App/UpdateAsset` |

Cross-shell banner (renders inside `_authenticated` layout for `AppBuilder` and `EndUser`): reads `GET /App/UpdateManifest?Product={CurrentProduct}&Channel=Stable`, compares against `window.__LARA_APP_VERSION__` injected at SSR. When behind, shows a dismissible banner with `Version`, `ReleaseNotesUrl`, and a "View update" link routing to `/builder/updates/$version` (AppBuilder) or `/app/update` (EndUser). Dismissal persists per session, resets on new `Version`. Never renders for `Admin` or `Reseller` shells.


---

## 4. Reseller Surfaces (`Reseller` role)

Scoped to `Licenses.ResellerId = AuthActor.ResellerId`. Attempts to read foreign rows return `NotFound` (not `Forbidden`) per `12-error-taxonomy.md`.

| Path | Purpose | Primary Contracts | Status |
|------|---------|-------------------|:------:|
| `/reseller` | Own KPIs: active seats, expiring in 30 days, revenue placeholder. | aggregate KPIs (no v1 endpoint) | D |
| `/reseller/packages` | Create and edit own `LicensePackages`. | `LicensePackages` endpoints | D |
| `/reseller/licenses` | Own licenses list. | `GET /Licenses` (filter `ResellerId = AuthActor.ResellerId`) | C |
| `/reseller/licenses/new` | Issue a license (choose category, variation, package). | `POST /Licenses` | C |
| `/reseller/licenses/$licenseId` | `Suspend`, `Reinstate`, `Renew` for own licenses; `Revoke` disabled. | `GET/PATCH /Licenses/{LicenseId}`, `POST /Licenses/{LicenseId}/Serials` | C |
| `/reseller/serials` | Serial explorer, bind status per `15-license-lifecycle.md` section 2. | `GET /Serials/{SerialValue}` (single lookup); list endpoint deferred | D |

---

## 5. AppBuilder Surfaces (`AppBuilder` role)

Scoped to own `ClientId`s registered against the verification API.

| Path | Purpose | Primary Contracts | Status |
|------|---------|-------------------|:------:|
| `/builder` | Client apps overview: verify volume, error rates. | verify stats aggregate | D |
| `/builder/clients` | Register client apps, rotate `ClientSecret`. | AppBuilder client CRUD + rotate | D |
| `/builder/keys` | Manage `HashKey` and `VerifyKey` sets per `08-hash-key.md`, `09-verify-key.md`. | AppBuilder key management | D |
| `/builder/logs` | Recent verify calls (redacted per `13-audit-logging.md`). | `GET /AuditEvents?ActorType=AppBuilder&ActorId={self}` | A |

---

## 6. EndUser Surfaces (`EndUser` role)

Scoped to `AuthActor.UserId`. Sees only own bindings and own serials.

| Path | Purpose | Primary Contracts | Status |
|------|---------|-------------------|:------:|
| `/app` | Licensed products, seat status, expiry. | EndUser self-service license read | D |
| `/app/serials/$serialId` | Serial detail, bound devices from `Bindings`. | EndUser self-service serial read | D |
| `/app/devices` | List and revoke own bindings within `MachineCount` limit. | EndUser self-service bindings | D |
| `/app/profile` | Profile, password change, OAuth link/unlink. | EndUser profile + OAuth link | D |
| `/app/update` | Shows an available update for the caller's current product/platform when the SSR-injected `AppVersion` is behind manifest. Download and checksum-verify are optional; the banner in §3a is the entry point. | `GET /App/UpdateManifest`, `HEAD /App/UpdateAsset` | C |

---

## 7. Cross-Cutting Rules

- Every authenticated route lives under the `_authenticated` layout so SSR gating runs before the loader (`auth-protected-server-functions` in system knowledge).
- Loaders that call role-gated server functions must live under the role-scoped layout (e.g. `/admin` loader → `Admin` middleware); no protected fn is called from a public loader.
- Every mutation action shows the `ErrorCode` from `12-error-taxonomy.md` in a toast; `RateLimited` displays `Retry-After` from headers.
- Every list view supports `RequestId` disclosure in an "Advanced" panel for support (`13-audit-logging.md`).
- All PII rendered from `AuditLogs.PayloadJson` is already redacted server-side; the client never re-hydrates raw tokens.

---

## 8. Acceptance Criteria

- AC-UI-001: Every path in sections 2 to 6 resolves to a role-gated route or public route, no orphan links.
- AC-UI-002: Every route names at least one contract from `spec/21-app/11-api-contracts/` (or `none` for pure marketing).
- AC-UI-003: Reseller routes reject cross-tenant IDs with `NotFound`, not `Forbidden`.
- AC-UI-004: `EndUser` routes never expose another user's `SerialId`, `BindingId`, or `UserId`.
- AC-UI-005: `/forbidden` renders the `Forbidden` envelope verbatim with a `RequestId` for support.
- AC-UI-006: `/admin/app-updates/*` routes call `has_role(Admin)` on the loader; publish flow uses the two-step ticket + manifest sequence from [`17-self-update-endpoint.md`](./17-self-update-endpoint.md), never a single-shot upload.
- AC-UI-007: The update banner in §3a renders for `AppBuilder` and `EndUser` shells only, dismissal is per-session, and dismissal state is keyed on `Version` so a new release re-shows the banner.
- AC-UI-008: Every row in sections 2 to 6 carries a `Status` marker (`C`, `A`, `D`, or `-` for pure marketing). Rows marked `D` render an "Available in v1.1" placeholder and MUST NOT call an unimplemented endpoint.
- AC-UI-009: Rows marked `A` call the canonical PascalCase path from [`10-endpoints.md`](./10-endpoints.md), never the lowercase alias shown in prior drafts.
