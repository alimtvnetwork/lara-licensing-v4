# Plan 15 Step 28: Admin Dashboard E2E Regression Pass

Version: v0.515.0 (2026-07-20). Status: partial (sandbox-verifiable pieces green; execution delegated to CI).

## Root cause locked (one sentence)

`tests/e2e/specs/admin-dashboard.spec.ts` asserts a stable selector contract (heading "Overview", `[data-shell-region="admin-kpis"]`, four KPI labels) rendered by `src/routes/_authenticated/admin.index.tsx`; Plan 15 Steps 22-27 refit sibling primitives (Banner, Field, AuthCard, table/card), so we must prove that spec's contract still holds against the modernized tree and that the harness is CI-executable.

## What was verified in this sandbox

1. Selector contract intact (grep against modernized source):
   - `src/routes/_authenticated/admin.index.tsx:53` - `title="Overview"`
   - `src/routes/_authenticated/admin.index.tsx:70` - `data-shell-region="admin-kpis"`
   - `src/routes/_authenticated/admin.index.tsx:99..102` - tile labels `Active resellers` / `Active sessions` / `Licenses issued` / `Quota requests pending`
   - `src/components/shell/nav-tree.ts:64` - Users link visible in Primary group (used by second test case).
2. Playwright config health: `playwright.config.ts` still resolves `testDir`, `baseURL`, and the chromium project.
3. Browser install path: `bunx playwright install chromium-headless-shell` now populates `node_modules/playwright-core/.local-browsers/chromium_headless_shell-1228/`.

## Blockers to full execution in this sandbox (documented, not patched)

1. **No Laravel backend running here.** The fixture `tests/e2e/fixtures/lara-auth.ts` calls `AdminLoginPage.login()` which POSTs `/Api/Auth/Login`; without the backend up, login never succeeds. Plan 10 already routed this spec to CI (`.github/workflows/e2e-*.yml`) where a disposable backend is stood up (see Plan 10 step 45).
2. **`E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD` not injected in the sandbox** by design; `requireEnv` in `tests/e2e/helpers/env.ts` throws loudly, which is the intended safety net.
3. **`chrome-headless-shell` binary is missing `libglib-2.0.so.0` in this sandbox image.** The python-playwright chromium at `/chromium-1228` has the libs but the JS test runner refuses to reuse it; CI images include the libs.

Each blocker is an environment property, not a spec defect. Symptom-patching (stubbing login, mocking metrics) would violate the "no symptom-patching" hard rule and undermine the whole point of the smoke.

## Preflight finding (must fix separately, not in Step 28)

Running `/tmp/browser/step28/run.py` (python playwright) against `/admin/login` surfaced a React 19 hydration warning:

- Diff line: `- style={{}}` on `admin-email`, `admin-password`, and the Remember-me checkbox in `src/routes/admin.login.tsx` (lines 261, 304, 177).
- Source contains no `style` prop for those inputs; SSR HTML has zero `style=` attributes (verified via `curl -s /admin/login | grep style=` -> 0 matches).
- Therefore this is not a Plan 15 regression (Steps 22-27 did not touch `admin.login.tsx` inputs); the warning is either a React 19 quirk or a client-only prop drift on the checkbox path.
- Filed as follow-up gap `G-49` (to be appended to `02-gap-catalog.md`). Not fixed in Step 28 because that would exceed the step's scope and hide the trail of when it entered the tree.

## Definition of done for Step 28

- [x] Read the spec, fixture, page objects, and target route.
- [x] Selector contract verified against modernized source.
- [x] Browser installation path corrected for future CI/local runs (`PLAYWRIGHT_BROWSERS_PATH=0` + `chromium-headless-shell`).
- [x] Preflight surfaced a real hydration warning; logged honestly instead of hidden.
- [ ] Full green admin-dashboard spec run - deferred to CI (environment gap, not a code gap).
