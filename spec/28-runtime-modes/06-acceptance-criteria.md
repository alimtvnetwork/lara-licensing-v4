# Runtime Modes: Acceptance Criteria (AC-RM)

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`acceptance-criteria` · `ac-rm` · `testable` · `given-when-then` · `invariant-mapping` · `playwright` · `vitest` · `contract`

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

Enumerate the observable, testable acceptance criteria for runtime modes as `AC-RM-*` handles. Every criterion maps to at least one INV-RM-* invariant from `00-overview.md` and to at least one test in Plan 16 Steps 65..79. Tests MUST reference the AC handle they prove in their describe/it string so a failure names the criterion it violates.

## Format

Each criterion:

- **ID** `AC-RM-##`
- **Given / When / Then**
- **Maps to** invariants and specs
- **Verified by** Plan 16 step(s)

## Boot and Mode Resolution

### AC-RM-01. Missing `version.json` fails closed

- **Given** a build where `/version.json` returns 404.
- **When** the app boots.
- **Then** the root route renders `StateError` with code `RUNTIME_CONFIG_LOAD_FAILED`; no route mounts; no `fetch(/api/*)` is issued.
- **Maps to** INV-RM-01, INV-RM-02. Spec `02-mode-selection-precedence §P-01`.
- **Verified by** Plan 16 Step 17 (Vitest resolver), Step 65 (Playwright boot suite).

### AC-RM-02. Invalid `version.json` fails closed

- **Given** `/version.json` returns HTTP 200 with a body that violates the JSON Schema (extra key, wrong enum, missing required field).
- **When** the app boots.
- **Then** `StateError` with `RUNTIME_CONFIG_INVALID` renders; no route mounts.
- **Maps to** INV-RM-01, INV-RM-03. Spec `01-version-json-schema §Validation`.
- **Verified by** Step 17, Step 65.

### AC-RM-03. Precedence order

- **Given** all four sources (URL query, `sessionStorage`, `localStorage`, `version.json`) present.
- **When** boot resolves the mode.
- **Then** URL query wins, then `sessionStorage`, then `localStorage`, then `version.json`.
- **Maps to** INV-RM-02. Spec `02-mode-selection-precedence §P-01..P-04`.
- **Verified by** Step 17 (10-case matrix).

### AC-RM-04. Freeze at hydration

- **Given** the resolved mode is `preview`.
- **When** any component reads `useRuntimeMode()` during the same page lifetime.
- **Then** the value is stable, never renegotiated, until a full reload.
- **Maps to** INV-RM-03. Spec `02-mode-selection-precedence §F-01..F-04`.
- **Verified by** Step 66 (Playwright freeze suite), Step 71 (Vitest hook).

## Preview Transport

### AC-RM-05. Every `Operations` key has a handler

- **Given** the frontend build.
- **When** `preview-transport.ts` initializes.
- **Then** iterating `Object.keys(Operations)` finds a registered handler for every entry; a missing one throws `RUNTIME_PREVIEW_HANDLER_MISSING` at boot, not at first call.
- **Maps to** INV-RM-04. Spec `03-preview-fixture-contract §R-01..R-03`.
- **Verified by** Step 77 (contract test).

### AC-RM-06. Preview envelope parity

- **Given** any preview handler success or failure.
- **When** the transport returns.
- **Then** the envelope shape (PascalCase `Data`, `Attributes.RequestId`, `Attributes.ErrorId` on 5xx, `Attributes.ETag`, `Attributes.RetryAfterSeconds`) is byte-parity with the production envelope for the same `operationId`.
- **Maps to** INV-RM-05. Spec `03-preview-fixture-contract §Envelope Shape`.
- **Verified by** Step 77, Step 78 (Zod contract).

### AC-RM-07. `If-Match` optimistic concurrency in preview

- **Given** a preview mutation handler for a resource with an `ETag`.
- **When** the request omits `If-Match`.
- **Then** the handler throws `PRECONDITION_REQUIRED` (428). A mismatched `If-Match` throws `LICENSE_CONFLICT` (or the domain conflict code) with `Attributes.CurrentETag` set.
- **Maps to** INV-RM-05. Spec `03-preview-fixture-contract §CC-01..CC-03`.
- **Verified by** Step 77.

### AC-RM-08. Scenario hooks

- **Given** the URL query `?scenario=offline|slow|rate-limited` (or matching header).
- **When** any preview handler is invoked.
- **Then** the transport injects the scenario: offline throws `RUNTIME_PREVIEW_OFFLINE`; slow adds a 2000ms delay; rate-limited throws `RUNTIME_PREVIEW_RATE_LIMITED` with `Attributes.RetryAfterSeconds` >= 1.
- **Maps to** INV-RM-05. Spec `03-preview-fixture-contract §SC-01..SC-04`.
- **Verified by** Step 67 (Playwright scenarios), Step 88 (scenarios coverage linter).

### AC-RM-09. Abort propagation

- **Given** a preview handler mid-flight.
- **When** the caller aborts (React Query unmount, user navigation).
- **Then** the handler throws `RUNTIME_PREVIEW_ABORTED` and does NOT mutate the seed store.
- **Maps to** INV-RM-05. Spec `03-preview-fixture-contract §H-04, §ST-04`.
- **Verified by** Step 77.

## Typed API Surface

### AC-RM-10. Single typed entrypoint

- **Given** the frontend source tree.
- **When** the `check-untyped-fetch.py` linter runs.
- **Then** the only `fetch(` call sites outside `src/lib/lara-fetch.ts` and `src/lib/preview-transport.ts` are zero.
- **Maps to** INV-RM-04. Spec `04-generated-types-contract §I-03..I-04`.
- **Verified by** Step 73 (linter), Step 90 (final sweep).

### AC-RM-11. Generated types are byte-deterministic

- **Given** a clean checkout.
- **When** `php artisan lara:openapi:export` then `bun run generate:api-types` run twice.
- **Then** `git diff --exit-code backend/build/openapi.json src/generated/api/` is empty on the second run.
- **Maps to** INV-RM-04. Spec `04-generated-types-contract §Drift Gate`.
- **Verified by** Step 28 (CI drift gate), Step 90.

### AC-RM-12. Hand-edit ban

- **Given** a PR that edits any file under `src/generated/api/**` by hand.
- **When** CI runs.
- **Then** the drift gate fails with a message pointing at `spec/28-runtime-modes/04-generated-types-contract.md §Hand-Edit Ban`.
- **Maps to** INV-RM-04. Spec `04-generated-types-contract §G-01..G-03`.
- **Verified by** Step 28.

### AC-RM-13. No `any` in typed API

- **Given** the generated schema and any code that consumes `Operations`.
- **When** `check-any-in-api.py` runs.
- **Then** zero `: any` or `as any` occurrences under `src/generated/api/**`, `src/lib/api-client.ts`, `src/lib/preview-transport.ts`, `src/hooks/use-api.ts`.
- **Maps to** INV-RM-04. Spec `04-generated-types-contract §G-02`.
- **Verified by** Step 74.

## Admin Runtime Toggle

### AC-RM-14. Root-admin only

- **Given** an authenticated non-root-admin.
- **When** they call `PUT /api/admin/runtime-config`.
- **Then** the response is 403 `RUNTIME_CONFIG_FORBIDDEN`; no audit `runtime_config.updated` event is written; a `runtime_config.denied` event MAY be written at info level.
- **Maps to** INV-RM-08. Spec `05-admin-runtime-toggle §A-01..A-03`.
- **Verified by** Step 63 (Playwright), Step 64 (Vitest RBAC).

### AC-RM-15. `If-Match` required

- **Given** a valid body from a root-admin.
- **When** the request omits `If-Match`.
- **Then** 428 `PRECONDITION_REQUIRED`.
- **Maps to** INV-RM-06. Spec `05-admin-runtime-toggle §C-01..C-02`.
- **Verified by** Step 63.

### AC-RM-16. `If-Match` mismatch

- **Given** two tabs open on Admin > Runtime.
- **When** tab A submits after tab B has already submitted.
- **Then** 412 `RUNTIME_CONFIG_CONFLICT` with `Attributes.CurrentETag`; the UI refetches and shows a diff.
- **Maps to** INV-RM-06. Spec `05-admin-runtime-toggle §C-01..C-03, §U-03`.
- **Verified by** Step 63.

### AC-RM-17. Atomic write

- **Given** a successful `PUT /api/admin/runtime-config`.
- **When** the service writes `version.json`.
- **Then** no observer ever sees a partial file: either the pre-image or the full new document. Under a simulated `rename()` failure the pre-image is intact.
- **Maps to** INV-RM-06. Spec `05-admin-runtime-toggle §W-01..W-04`.
- **Verified by** Step 64 (Vitest service).

### AC-RM-18. Audit event on success

- **Given** a successful `PUT`.
- **When** the response returns 200.
- **Then** exactly one `runtime_config.updated` audit row exists with `ActorUserId`, `Before`, `After`, and `ChangedKeys` matching the diff.
- **Maps to** INV-RM-07. Spec `05-admin-runtime-toggle §AU-01..AU-04`.
- **Verified by** Step 61, Step 64.

### AC-RM-19. Deploy-only re-enable of toggle

- **Given** current `AllowRuntimeToggle = false`.
- **When** a root-admin submits `AllowRuntimeToggle = true`.
- **Then** 423 `RUNTIME_CONFIG_LOCKED`; no write occurs.
- **Maps to** INV-RM-08. Spec `05-admin-runtime-toggle §M-03, §S-03`.
- **Verified by** Step 63.

### AC-RM-20. Prod-to-preview safety rail

- **Given** the host env has NOT set `LARA_ALLOW_PROD_TO_PREVIEW=1`.
- **When** a root-admin submits `Mode: preview` while current is `production`.
- **Then** 403 `RUNTIME_CONFIG_FORBIDDEN` with `Attributes.Reason = "prod_to_preview_disabled"`.
- **Maps to** INV-RM-08. Spec `05-admin-runtime-toggle §S-01`.
- **Verified by** Step 63.

### AC-RM-21. `RequiresReload` on prod exit

- **Given** current `Mode = production`.
- **When** the mode is successfully changed to `preview` or `dev`.
- **Then** response body includes `Attributes.RequiresReload = true`; the FE forces a full page reload.
- **Maps to** INV-RM-08. Spec `05-admin-runtime-toggle §S-02`.
- **Verified by** Step 63.

## UX Surface

### AC-RM-22. RuntimeBanner visibility

- **Given** the resolved mode.
- **When** any authenticated page renders.
- **Then** `<RuntimeBanner>` shows "PREVIEW", "DEV", or is hidden in `production`. The banner is server-rendered and stable across hydration (no flicker).
- **Maps to** INV-RM-03, INV-RM-09. Spec `00-overview §Rendering`.
- **Verified by** Step 55 (RuntimeBanner impl), Step 68 (Playwright hydration).

### AC-RM-23. No `import.meta.env.MODE` outside allowlist

- **Given** the frontend source tree.
- **When** `check-import-meta-mode.py` runs.
- **Then** zero occurrences of `import.meta.env.MODE` outside `src/lib/runtime-mode.ts` and `vite.config.ts`.
- **Maps to** INV-RM-02. Spec `02-mode-selection-precedence §P-04`.
- **Verified by** Step 75.

### AC-RM-24. Debug drawer surfaces resolved config

- **Given** any non-production mode.
- **When** the developer opens the debug drawer.
- **Then** it shows resolved `Mode`, `ApiBaseUrl`, `PreviewSeed`, source of each (`url|session|local|version`), and current scenario.
- **Maps to** INV-RM-10. Spec `00-overview §Observability`.
- **Verified by** Step 86.

## Coverage

Total: 24 criteria. Every INV-RM-01..INV-RM-10 is covered by at least two AC handles (mapping tabulated in the coverage matrix appendix, generated by `linter-scripts/check-inv-rm-coverage.py` at Step 88).

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-01..INV-RM-10.
- `spec/28-runtime-modes/01-version-json-schema.md`, `02-mode-selection-precedence.md`, `03-preview-fixture-contract.md`, `04-generated-types-contract.md`, `05-admin-runtime-toggle.md`.
- Plan 16 Steps 17, 28, 55, 61, 63-79, 86-90.
