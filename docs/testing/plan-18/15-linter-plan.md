# Plan 18 · Step 15 · Linter Plan (Steps 176-180)

Status: draft (produced by Plan 18 Step 15).

Depends on: Steps 5 (controller skeleton), 6-7 (seeder + profiles),
8-9 (demo login), 10 (preview fixtures), 11 (error envelope),
12 (notification center), 13 (Pest), 14 (Playwright).

## 1. Ground truth

Linters live in `linter-scripts/`. Dispatch is a flat list in
`linter-scripts/run.sh` (Step 3 block, lines ~100-140). Existing linters
that Plan 18 either extends or must not duplicate:

- `check-preview-in-prod-bundle.py` — string scan of prod dist for
  `PREVIEW_` markers. **Extend** for `DEMO_LOGIN_PANEL_MARKER`.
- `check-preview-handler-coverage.py` — asserts every OperationId has a
  preview handler + Zod shape. **Extend** to lock the 5 future
  OperationIds queued in Step 10.
- `check-preview-branch-parity.py` — proves preview/real code paths
  match at the module-graph level. Untouched.
- `check-preview-store-key-shape.py` — Zustand store shape guard.
  Untouched.
- `check-endpoint-permission-parity.py` — FE/BE RBAC matrix. **Do not**
  duplicate for OperationId parity; new linter (§2.1) sits alongside it.
- `check-mws-error-codes.py` — legacy MWS taxonomy. Untouched;
  Plan 18's error linter (§2.3) is additive.
- `check-forbidden-strings.py` + `forbidden-strings.toml` — string
  denylist. **Extend** with two rules (§2.4).

## 2. New / extended linters

### 2.1 `check-endpoint-operation-parity.py` (NEW, Step 176)

Purpose: fail the build when any FE `OperationId` in
`src/generated/api/operations.ts` lacks a matching BE route in
`backend/routes/api.php` (or its included fragments), and vice versa.

Inputs:
- `src/generated/api/operations.lock.json` — FE side of truth
  (already maintained by Plan 17 Step 43 drift guard).
- `backend/routes/api.php` and `backend/routes/*.php` includes —
  parsed for `Route::{get,post,put,patch,delete}` with a
  `->name('operation.id')` chain.
- Waiver file `linter-scripts/check-endpoint-operation-parity.waivers.txt`
  (one OperationId per line, with justification comment) for parity
  gaps we knowingly ship (e.g. FE-only virtual ops).

Output: exit 0 on parity, exit 1 with a two-column diff listing
missing sides.

Wiring: append to `run.sh` right after `preview-branch-parity`.

### 2.2 Extend `check-preview-in-prod-bundle.py` (Step 177)

Add two new banned markers to the existing scan:

- `DEMO_LOGIN_PANEL_MARKER` — string constant re-exported by
  `src/lib/demo-identities.ts`. Must be absent from every file under
  `dist/` for a production build.
- `SEED_PROFILE_MARKER` — the debug string emitted by
  `src/lib/preview-fixtures/_shapes.ts` when running in dev.

Fail mode: same as existing — one line per offending bundle chunk.

### 2.3 `check-error-envelope-shape.py` (NEW, Step 178)

Purpose: enforce Step 11's error contract statically.

Rules:

1. Every subclass of `LaraException` under `backend/app/Exceptions/`
   MUST declare `errorCode`, `httpStatus`, and `category` as
   `protected` typed properties.
2. Every controller under `backend/app/Http/Controllers/` MUST NOT
   contain `new LaraException(` — only subclass factories are
   permitted. Regex probe with a waiver file.
3. Every FE call site of `LaraApiError` MUST access `.category`,
   `.errorId`, `.operationId` via the typed field, never via
   `err.attributes?.Category` string access. Guards against drift
   after Step 40 of Plan 17.

Wiring: append right after `mws-error-codes`.

### 2.4 Extend `check-forbidden-strings.py` (Step 179)

Add two entries to `forbidden-strings.toml`:

```toml
[[rules]]
name = "hardcoded-demo-password"
pattern = "Passw0rd!Demo"       # canonical demo password from Step 8
paths = ["src/**", "!src/lib/demo-identities.ts", "!tests/**"]
reason = "Demo password lives in one canonical constant; do not inline."

[[rules]]
name = "raw-x-error-id-header-access"
pattern = "headers\\.get\\(['\"]x-error-id"
paths = ["src/**", "!src/lib/lara-api-error.ts"]
reason = "Read X-Error-Id via lara-api-error.ts, not raw header access."
```

### 2.5 Extend `check-preview-handler-coverage.py` (Step 180)

Add the 5 future OperationIds from Step 10 (`auth.session.refresh`,
`admin.licenses.index`, `admin.serials.show`, `admin.users.destroy`,
`admin.features.index`) to the required set. Handler-missing yields a
loud red so BE codegen cannot ship without the matching preview
fixture landing in the same commit.

## 3. Step-to-file map (Steps 176-180)

| Step | Linter | File | Wiring |
|--:|---|---|---|
| 176 | endpoint-operation-parity | `linter-scripts/check-endpoint-operation-parity.py` (NEW) + waivers | append after `preview-branch-parity` in `run.sh` |
| 177 | preview-in-prod-bundle | extend `check-preview-in-prod-bundle.py` | no new dispatch line |
| 178 | error-envelope-shape | `linter-scripts/check-error-envelope-shape.py` (NEW) + waivers | append after `mws-error-codes` |
| 179 | forbidden-strings | extend `forbidden-strings.toml` (2 rules) | already wired |
| 180 | preview-handler-coverage | extend `check-preview-handler-coverage.py` | already wired |

## 4. Determinism and CI notes

- Every new linter exits 0 or 1, no warnings channel, matching the
  existing `run.sh` `run_linter` helper contract.
- All new linters read from `.lock.json` snapshots where possible so
  they run without network and without a live BE.
- Waiver files are text; each waiver row requires a `# reason:` inline
  comment or the linter itself rejects the waiver (self-linting).
- CI Step 183 will add the new linters to the `linter-matrix` job in
  the CI plan (Step 16). This document does NOT touch CI yaml; that
  is Step 16's responsibility.

## 5. Out of scope

- Runtime shape assertions (Plan 17 Step 42 already handles this via
  `assertPreviewShape`).
- Visual pixel threshold policy for Playwright baselines (Step 179's
  companion `check-visual-baseline.py` is not in Plan 18 budget; noted
  as follow-up in Step 17 risk doc).
- Backend Pint/PHPStan config (existing repo config unchanged).
