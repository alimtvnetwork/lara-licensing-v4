# Admin Runtime Toggle

**Version:** 1.1.0
**Updated:** 2026-07-21
**AI Confidence:** Stable
**Ambiguity:** Low

> **v1.1 note (Plan 17):** The `RuntimeModeSwitch` now health-gates every
> transition to `production` and every runtime backend override. The
> "Use backend" button is disabled until a valid URL is entered;
> `probeBackendHealth()` must return healthy before `saveMode()` commits.
> `lara.runtime.lastGoodBackendUrl.v1` is written only on success. All
> transitions emit `runtime.mode.switch` telemetry
> (`REQUESTED` / `COMMITTED` / `ABORTED{reason}`). See INV-RM-11, INV-RM-12
> in `00-overview.md`.

---

## Keywords

`admin` · `runtime-config` · `toggle` · `if-match` · `atomic-write` · `rbac` · `audit` · `version-json` · `precondition`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the contract for the runtime-config toggle surface (backend endpoint + Admin UI) that mutates the deployed `version.json` at runtime. Every mutation MUST go through this contract, MUST be gated by RBAC, MUST use optimistic concurrency on `UpdatedAt`, and MUST emit an audit event that is byte-parity with `spec/03-error-manage` conventions.

## Scope

- Backend endpoint: `PUT /api/admin/runtime-config` (Plan 16 Step 58).
- FormRequest, Policy, Service (Plan 16 Steps 59-60).
- Audit event `runtime_config.updated` (Step 61).
- Admin > Runtime page (Step 62).
- Playwright + Vitest RBAC coverage (Steps 63-64).

Out of scope: the shape of `version.json` itself (`01-version-json-schema.md`) and the client-side mode resolution (`02-mode-selection-precedence.md`).

## Actors and RBAC (INV-RM-08)

| Role | May read runtime-config | May write runtime-config |
|------|-------------------------|--------------------------|
| `root-admin` | ✅ | ✅ (all mutable fields) |
| `admin` | ✅ | ❌ (403 `RUNTIME_CONFIG_FORBIDDEN`) |
| `moderator`, `user`, unauthenticated | ❌ (404 to hide surface) | ❌ (404) |

Rules:

- **A-01.** Authorization checked in `RuntimeConfigPolicy` via `public.has_role(auth.uid(), 'root_admin')` (see project `user-roles` convention). No client-side check is trusted.
- **A-02.** The policy denial code MUST be `RUNTIME_CONFIG_FORBIDDEN`, mapped to HTTP 403 in the canonical envelope. Any non-root-admin who is authenticated hits 403, not 404, so audit + telemetry stay honest. Unauthenticated callers hit 401 `UNAUTHENTICATED`.
- **A-03.** No fallback to `service_role` or `supabaseAdmin` for authorization. Role check MUST be under RLS with the caller's own token.

## Mutable vs Deploy-Only Fields

From `01-version-json-schema.md`:

| Field | Mutable at runtime | Notes |
|-------|--------------------|-------|
| `Version` | ❌ | Sourced from `package.json` at deploy time. |
| `Mode` | ✅ | Enum in `{ preview, dev, production }`. |
| `ApiBaseUrl` | ✅ (conditional) | Required when `Mode = production`, forbidden otherwise. |
| `PreviewSeed` | ✅ (conditional) | Required when `Mode = preview`, forbidden otherwise. |
| `AllowRuntimeToggle` | ✅ | When `false`, the endpoint itself returns 423 `RUNTIME_CONFIG_LOCKED`. |
| `UpdatedAt` | server-only | Rewritten by the service on every successful write. |

Rules:

- **M-01.** Any request body key outside the mutable set fails validation with `RUNTIME_CONFIG_INVALID_FIELD` (422). Keys are PascalCase, matching `version.json`.
- **M-02.** Conditional required/forbidden pairs (`ApiBaseUrl`, `PreviewSeed`) are enforced in the FormRequest via a single conditional rule keyed on the incoming `Mode`. Cross-field failures return `RUNTIME_CONFIG_MODE_MISMATCH` (422).
- **M-03.** `AllowRuntimeToggle` may be set to `false` but NOT back to `true` by this endpoint. Re-enabling the toggle requires a deploy (writes `version.json` from the build pipeline). This prevents a compromised root-admin from silently re-enabling the surface.

## Optimistic Concurrency (INV-RM-06)

- **C-01.** Every response of `GET /api/admin/runtime-config` sets `ETag: "<sha256(UpdatedAt)>"`.
- **C-02.** Every `PUT` MUST send `If-Match: "<etag>"`. Missing header returns 428 `PRECONDITION_REQUIRED`. Mismatch returns 412 `RUNTIME_CONFIG_CONFLICT` with `Attributes.CurrentETag` in the envelope so the UI can re-fetch and merge.
- **C-03.** The service (Plan 16 Step 60) recomputes the current `UpdatedAt` inside a per-host advisory lock (file lock on `version.json.lock`) so parallel writers cannot skip the check.

## Atomic Write Discipline

Mirroring the write discipline pinned in `01-version-json-schema.md` and `04-generated-types-contract.md`:

- **W-01.** Write `version.json.tmp` with the full new document (all fields, sorted keys, LF, trailing newline), `fsync`, then `rename()` to `version.json`. Never partial-writes.
- **W-02.** Compute the new `UpdatedAt` inside the lock, after validation, using UTC RFC-3339 `Z`-only format.
- **W-03.** If the rename fails, the service throws `RUNTIME_CONFIG_WRITE_FAILED` (500) with the underlying errno in the log context. The stale `.tmp` is unlinked in a `finally` so retries succeed.
- **W-04.** On success the response body echoes the new document plus the new `ETag`. No cache header is set; the FE is expected to invalidate its React Query cache on the mutation's `onSuccess`.

## Audit Event (INV-RM-07)

Every successful write emits exactly one event `runtime_config.updated` with this shape (PascalCase, closed set):

```json
{
  "EventName": "runtime_config.updated",
  "ActorUserId": "<uuid>",
  "ActorIp": "<ip-or-null>",
  "OccurredAt": "2026-07-20T12:34:56Z",
  "RequestId": "<uuid>",
  "Before": { "Mode": "preview", "ApiBaseUrl": null, "PreviewSeed": "default", "AllowRuntimeToggle": true, "UpdatedAt": "..." },
  "After":  { "Mode": "production", "ApiBaseUrl": "https://api.example.com", "PreviewSeed": null, "AllowRuntimeToggle": true, "UpdatedAt": "..." },
  "ChangedKeys": ["Mode", "ApiBaseUrl", "PreviewSeed", "UpdatedAt"]
}
```

Rules:

- **AU-01.** Emitted from the same DB transaction that would persist the change (or, since `version.json` is on disk, immediately after a successful `rename()` but before the response is returned). If audit write fails, the request fails with `AUDIT_WRITE_FAILED` (500) and a compensating rewrite is attempted to restore `Before`.
- **AU-02.** `Before`/`After` are the full runtime-mutable subset even for one-field edits; `ChangedKeys` is the diff so query load is cheap.
- **AU-03.** `ActorUserId` is `auth.uid()` from the authenticated Supabase session. `ActorIp` is null when the header is unavailable.
- **AU-04.** Failed writes (validation, 412, 423) do NOT emit `runtime_config.updated`. They MAY emit `runtime_config.denied` at info level for security telemetry.

## Envelope Codes (closed set)

Extend the runtime-mode code set from `03-preview-fixture-contract.md`:

| Code | HTTP | Meaning |
|------|------|---------|
| `RUNTIME_CONFIG_FORBIDDEN` | 403 | Authenticated caller lacks `root-admin`. |
| `RUNTIME_CONFIG_LOCKED` | 423 | `AllowRuntimeToggle` is currently `false`. |
| `RUNTIME_CONFIG_CONFLICT` | 412 | `If-Match` mismatch. |
| `RUNTIME_CONFIG_INVALID_FIELD` | 422 | Body contains keys outside the mutable set. |
| `RUNTIME_CONFIG_MODE_MISMATCH` | 422 | Conditional required/forbidden field pair violated. |
| `RUNTIME_CONFIG_WRITE_FAILED` | 500 | `rename()` or `fsync` failed. |
| `PRECONDITION_REQUIRED` | 428 | `If-Match` header missing on `PUT`. |

Every code MUST be present in `spec/03-error-manage/03-error-code-registry` before Step 58 lands; failure to register fails the closed-set parity check.

## Safety Rails

- **S-01.** In non-development builds, `Mode` may be flipped to `preview` ONLY when the environment variable `LARA_ALLOW_PROD_TO_PREVIEW=1` is set on the host. The service returns `RUNTIME_CONFIG_FORBIDDEN` with `Attributes.Reason = "prod_to_preview_disabled"` otherwise. This prevents a live customer host from being switched to fixture mode by accident.
- **S-02.** When `Mode` transitions from `production` to any other value, the response body includes `Attributes.RequiresReload = true` so the FE forces a full page reload rather than mixing typed and fixture responses in the same session.
- **S-03.** `AllowRuntimeToggle = false` is the intended production-day-of-launch stance. Deploys MUST re-write `version.json` to re-enable the toggle; the endpoint itself cannot.

## Admin > Runtime Page (Plan 16 Step 62)

- **U-01.** Route: `src/routes/_authenticated/admin.runtime.tsx`.
- **U-02.** Guarded by an `_authenticated` layout + a client-side `has_role('root_admin')` check that hides the sidebar entry but does NOT act as an authorization boundary; the server 403 is authoritative.
- **U-03.** Uses `useApi("getAdminRuntimeConfig")` for read and `useApiMutation("putAdminRuntimeConfig")` for write. The mutation attaches `If-Match` from the last read; on 412 it toasts "Config changed, please review" and refetches.
- **U-04.** Displays a diff summary before submit ("You are switching Mode: preview -> production"); no submit without the confirmation checkbox.
- **U-05.** The page never mutates `AllowRuntimeToggle = true`; the control is read-only when the current value is `false`, and shows a note pointing to the deploy pipeline.

## Testing (Plan 16 Steps 63-64)

- **T-01.** Playwright `admin-runtime.spec.ts`: happy-path toggle, 412 conflict path (two tabs), 403 for non-root-admin, 423 when `AllowRuntimeToggle=false`, safety-rail S-01.
- **T-02.** Vitest RBAC unit: policy allows only root-admin; FormRequest enforces M-01..M-03; service enforces C-01..C-03 under an advisory lock.
- **T-03.** Contract test: envelope codes match `spec/03-error-manage/03-error-code-registry` (parity linter).

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-06, INV-RM-07, INV-RM-08.
- `spec/28-runtime-modes/01-version-json-schema.md`: mutable field surface, write discipline.
- `spec/28-runtime-modes/02-mode-selection-precedence.md`: how the FE re-resolves after a successful write.
- `spec/28-runtime-modes/04-generated-types-contract.md`: `Operations["putAdminRuntimeConfig"]` typing.
- `spec/03-error-manage/03-error-code-registry`: closed-set code additions.
- Plan 16 Steps 58-64.
