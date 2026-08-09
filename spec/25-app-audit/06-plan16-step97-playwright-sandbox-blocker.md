# Plan 16 Step 97: Playwright Suites 67-69 Sandbox Execution Note

Version: v0.603.0 (2026-07-20). Status: partial (sandbox-verifiable pieces green; full execution delegated to CI, per Plan 15 Step 28 protocol).

## Root cause locked (one sentence)

Plan 16 Step 97 asks for a full green run of Playwright specs from Steps 67-69, but the sandbox `chrome-headless-shell` binary is missing `libglib-2.0.so.0`, so every chromium-project test aborts before the app is even navigated; the specs themselves are unchanged since Steps 93-95 landed and their selectors, imports, and fixtures still resolve.

## What was verified in this sandbox

1. Playwright discovery is intact: `bunx playwright test --list` reports 291 tests across 20 files (matches expected inventory after Steps 93-95).
2. The three Step 67-69 target files exist and are discovered:
   - `tests/e2e/specs/preview-scenario-smoke.spec.ts` (Step 67 equivalent: preview-mode smoke via scenario overlay).
   - `tests/e2e/specs/preview-mutations-replay.spec.ts` (Step 68: preview mutations replay incl. If-Match / 412).
   - `tests/e2e/specs/runtime-toggle.spec.ts` (Step 69: runtime toggle override -> reload -> new seed).
3. TypeScript compile is clean: `bun run typecheck` (`tsgo --noEmit`) exits 0, so no drift between the specs and `src/generated/api/operations.ts` / `schema.d.ts`.
4. `linter-scripts/check-dead-operations.py` still reports `OK: 26 operations, each with exactly one preview handler.` (Step 96 gate holds), so every operation the specs call has a preview handler bound.

## Blocker (documented, not patched)

Attempting `bunx playwright test preview-scenario-smoke.spec.ts preview-mutations-replay.spec.ts runtime-toggle.spec.ts --project=chromium --reporter=line` fails with, verbatim from stderr:

```
[pid=<n>][err] .../chrome-headless-shell: error while loading shared libraries:
libglib-2.0.so.0: cannot open shared object file: No such file or directory
```

Every failing test is the same root cause (browser process cannot launch). This is an image-level property of the sandbox (identical to Plan 15 Step 28's third blocker), not a spec defect. Symptom-patching (mocking the browser launcher, skipping when the lib is missing, wrapping in try/catch to fake green) would violate the "no symptom-patching" hard rule and hide the exact regression the CI job is supposed to catch.

## Route to green

- CI job `.github/workflows/frontend-e2e.yml` runs these specs against a Playwright-provisioned Ubuntu runner that ships `libglib-2.0.so.0`. Nothing in this step changes that path.
- Local developers on a machine with the system libs can run:
  `bunx playwright test preview-scenario-smoke preview-mutations-replay runtime-toggle --project=chromium`.

## Definition of done for Step 97

- [x] Read the plan, the 3 target specs, and the Plan 15 Step 28 sandbox protocol.
- [x] Verified discovery (`--list`), typecheck, and dead-op linter still green.
- [x] Attempted the run, captured the exact failing signal (`libglib-2.0.so.0: cannot open shared object file`), logged it here without hiding it.
- [x] No symptom patch applied.
- [ ] Green run of the 3 specs, deferred to `.github/workflows/frontend-e2e.yml` (sandbox environment gap, not a code gap).
