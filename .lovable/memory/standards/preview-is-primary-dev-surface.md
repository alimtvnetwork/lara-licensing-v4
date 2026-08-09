# Preview mode is the primary dev surface

**Rule:** Preview mode (Runtime = seed data, no backend) is the primary dev
and demo surface for the operator UI. Every top-level admin route MUST
render green under both the `default` seed and the `empty` seed. The `error`
seed is the only seed allowed to surface a route-scoped `RouteErrorState`
banner; nothing is ever allowed to bubble up the generic router
`StateError` ("Something went wrong on our side.") under `default` or
`empty`.

## Why

Plan 17 (`seed-mode-reliability-and-config-db`) made seed mode the fast,
deterministic default because Backend mode is not always reachable during
UI iteration (Lovable preview, offline, CI). If seed mode is not green,
the operator UI has no working dev surface at all.

## Enforcement

- `linter-scripts/check-dead-operations.py` — every OperationId in
  `src/generated/api/operations.ts` MUST have exactly one
  `registerPreviewHandler(...)` binding in `src/lib/preview-fixtures/**`.
  Dead-in-preview = CI fail.
- `src/lib/preview-fixtures/_shapes.ts` (`PREVIEW_RESPONSE_SHAPES`) —
  every handler response is Zod-asserted at dispatch; drift throws
  `PreviewFixtureShapeError` (INV-RM-05 at runtime).
- Playwright specs (see `docs/testing/coverage-matrix.md` -> "Seed-mode
  E2E rows"):
  - `preview-admin-routes.spec.ts` — `default` + `empty` seeds across all
    8 admin routes; no generic `StateError`.
  - `preview-admin-routes-error.spec.ts` — `error` seed across the same
    8 routes; scoped `RouteErrorState` with `operationId` + `requestId`,
    never the generic banner.
  - `preview-admin-tti-baseline.spec.ts` — cold/warm TTI ratchet vs
    `tests/e2e/baselines/preview-admin-tti.json`.
  - `route-error-correlation.spec.ts` — errorComponent surfaces
    `operationId` + `requestId` (Plan 11 error-contract axis).

## Invariants referenced

- INV-RM-04: every OperationId has a preview handler.
- INV-RM-05: preview handler response shape == live BE response shape.
- INV-RM-06: preview boot is gated; queries never fire before
  `dispatchPreviewSeed()` resolves.
- Plan 11 error-contract axis: `LaraApiError` carries `operationId` +
  `requestId`; route errorComponents surface both.

## Do not

- Do not add a route that only renders correctly with a live backend.
- Do not `try/catch` a broken handler into a friendly-looking empty state
  under `default`/`empty` — that hides a real drift.
- Do not skip the `empty` seed. "Zero rows" is a first-class rendering
  contract, not an edge case.
