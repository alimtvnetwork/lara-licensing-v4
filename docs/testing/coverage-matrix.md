# Coverage Matrix

Generated: 2026-07-21T11:00:00Z

- Vitest files: 110
- Playwright specs: 24
- All linters green: True

## Linter axes

| Axis | Ok | Summary |
| --- | --- | --- |
| UntypedFetch | yes | check-untyped-fetch: OK (no raw fetch outside allowlisted transports) |
| AnyInApi | yes | check-any-in-api: OK (no `: any` / `as any` in lara-*.ts, api-client.ts, generated/api/**) |
| MagicEndpointStrings | yes | check-magic-endpoint-strings: OK (no inline /api/ literals outside allowlisted trees) |
| PreviewInProdBundle | yes | check-preview-in-prod-bundle: OK |
| ScreenshotMatrixCoverage | yes | check-screenshot-matrix-coverage: SKIP (manifest not found at tests/e2e/screenshots/preview-matrix/index.json; set SCREENSHOT_MATRIX_STRICT=1 to fail) |
| OpenApiDrift | yes | OK: OpenAPI drift check clean (26 operations) |
| SchemaSymbolDrift | yes | OK: schema symbol drift check clean (49 symbols) |
| DeadOperations | yes | OK: 26 operations, each with exactly one preview handler. |

## Playwright specs

- a11y-axe.spec.ts
- admin-dashboard.spec.ts
- admin-impersonation.spec.ts
- admin-license-crud.spec.ts
- admin-quota-approval.spec.ts
- auth-login.spec.ts
- auth-password-reset.spec.ts
- auth-register-bootstrap.spec.ts
- error-modal-a11y.spec.ts
- error-modal.spec.ts
- health.spec.ts
- portal-serial-lookup.spec.ts
- portal-update-download.spec.ts
- preview-admin-routes.spec.ts
- preview-admin-routes-error.spec.ts
- preview-admin-tti-baseline.spec.ts
- preview-mutations-replay.spec.ts
- preview-scenario-smoke.spec.ts
- preview-screenshot-matrix.spec.ts
- preview-seed-matrix.spec.ts
- reseller-dashboard.spec.ts
- route-error-correlation.spec.ts
- runtime-toggle.spec.ts
- visual-font-baseline.spec.ts

## Seed-mode E2E rows (Plan 17)

Preview runtime seed coverage. Each row names the spec, the seeds it drives,
the routes/handlers it exercises, and the invariant it protects.

| Spec | Seeds | Routes / surface | Invariant asserted |
| --- | --- | --- | --- |
| preview-seed-matrix.spec.ts | default, empty | 26 handlers via `apiClient.call<Op>` (LIC/QUOTA/AUDIT/USERS/FEATURES list counts) | INV-RM-05 (preview + live parity): default = shipped canonical counts, empty = auth-only |
| preview-admin-routes.spec.ts | default, empty | `/admin`, `/admin/resellers`, `/admin/users`, `/admin/audit`, `/admin/quotas`, `/admin/quota-requests`, `/admin/app-updates`, `/admin/serials` | INV-RM-04 + INV-RM-06: no route bubbles up the generic `StateError` banner under happy-path seeds |
| preview-admin-routes-error.spec.ts | error | same 8 admin routes | Plan 11 error-contract axis: every route fails loudly via scoped `RouteErrorState` (`data-testid="route-error"`) carrying `operationId` + `requestId`, never the generic banner |
| preview-admin-tti-baseline.spec.ts | default | admin routes cold + warm | Performance ratchet vs `tests/e2e/baselines/preview-admin-tti.json`; warm <= cold; both <= baseline * regressionFactor |
| route-error-correlation.spec.ts | default (+ runtime handler unregister) | `/admin/audit` | Plan 11 Step 39/40: route errorComponent surfaces `operationId` = `admin.audit.list` and non-empty `requestId` |
| preview-scenario-smoke.spec.ts | default (+ scenario overlays) | scenario-driven handlers | `applyPreviewScenario` overlay wiring, no scenario leaks between routes |
| preview-mutations-replay.spec.ts | default | mutation ops (create/update/delete) | Optimistic writes + replay against IndexedDB stay consistent with list reads |
| preview-screenshot-matrix.spec.ts | default | visual matrix (manifest) | Visual regression pinned; strict mode gated by `SCREENSHOT_MATRIX_STRICT=1` |
| runtime-toggle.spec.ts | default | `RuntimeModeSwitch` UI | Seed <-> Backend flip is gated by health probe + valid URL (Plan 17 Steps 35-38) |
