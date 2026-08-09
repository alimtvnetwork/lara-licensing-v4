# Issue 04: Preview cannot exercise UI without a live backend

Status: resolved
Reported: 2026-07-20
Resolved: 2026-07-20
Resolved-By: Plan 16 (`.lovable/plans/completed/16-preview-production-runtime-typed-api.md`), v0.517.0 - v0.606.0


## Symptom

In Lovable preview, the app renders route shells but every data-driven screen
falls through to error/empty states because the Laravel backend is not
reachable from the preview iframe (no `VITE_LARA_API_BASE_URL`, no CORS to a
public dev host, no seeded fixtures). The user cannot visually test UI flows.

## Repro

1. Open Lovable preview at the project's `id-preview--*.lovable.app`.
2. Navigate to `/admin`, `/portal/updates`, `/admin/licenses`, etc.
3. Observe: KPI tiles show retry-after banners; tables show empty; forms POST
   fail with `Failed to fetch` in the console; auth cannot proceed.

## Expected vs actual

Expected: every screen renders with realistic seed data and mutations appear
to persist for the session, so the UI can be reviewed end-to-end inside
Lovable without a backend.

Actual: screens degrade to error states because `laraFetch` cannot reach the
backend and there is no in-app fixture layer.

## Related files

- `src/lib/lara-fetch.ts` (network entrypoint)
- `src/lib/lara-*.ts` (typed callers; some hand-written, drift risk)
- `src/routes/_authenticated/**` (data-driven pages)
- `README.md` (no `version.json` at repo root today)
- absent: `version.json`, `src/generated/api/`, `src/lib/runtime-mode.ts`,
  `src/lib/preview-fixtures/`

## Fix vehicle

Plan 16: preview vs production runtime, typed API, version.json.

## Resolution (Plan 16 Step 100)

Delivered a triple-mode runtime (`preview`, `dev`, `production`) with:

- `src/lib/runtime-mode.ts`, `src/lib/api-client.ts`, and `public/version.json` gating the mode at build + runtime with Cmd+Shift+D drawer override that survives reloads (verified in `tests/e2e/specs/runtime-toggle.spec.ts`).
- 26 preview handlers over IndexedDB with realistic seeded fixtures across all admin/portal screens (verified in `tests/e2e/specs/preview-seed-matrix.spec.ts`).
- Full CRUD + `If-Match` version replay in preview (verified in `tests/e2e/specs/preview-mutations-replay.spec.ts`).
- Backend `RuntimeConfigController.php` for prod mode discovery.
- 8-axis lint gate (UntypedFetch, AnyInApi, MagicEndpointStrings, PreviewInProdBundle, ScreenshotMatrixCoverage, OpenApiDrift, SchemaSymbolDrift, DeadOperations) plus consolidated coverage matrix at `docs/testing/coverage-matrix.{json,md}` and workflow `.github/workflows/coverage-matrix.yml`.
- Playwright sandbox blocker for Step 97 routed to CI and documented in `spec/25-app-audit/06-plan16-step97-playwright-sandbox-blocker.md`.

Preview iframe now renders every data-driven screen with seeded rows, mutations persist per session, and the user can review UI end-to-end without a live Laravel backend.
