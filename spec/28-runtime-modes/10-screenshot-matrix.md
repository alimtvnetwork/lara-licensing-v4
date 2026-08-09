# Preview Screenshot Matrix Pipeline

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** High
**Ambiguity:** Low

---

## Keywords

`screenshot-matrix` · `preview-scenario` · `preview-seed` · `playwright` · `INV-RM-04` · `INV-PS-01..12` · `artifact-layout`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | (cross-ref) |
| AI Confidence assigned | Yes |
| Ambiguity assigned | Yes |
| Keywords present | Yes |
| Scoring table present | Yes |

---

## Purpose

`08-preview-scenarios.md` v2 pins the closed set of scenarios and the seed axis. This document pins the pipeline that renders every (route x scenario x seed) cell to disk so Step 87's coverage linter has a machine-checkable artifact and downstream visual-diff work (Plan 17+) has a stable baseline location. Without this pipeline, preview degraded-UX regressions ship invisibly; the spec's screenshot matrix table is only a promise.

---

## Scope Split (Step 86 vs Step 93)

Step 86 (this spec) ships the driver, artifact layout, and CI workflow using **public routes only** (`/`, `/admin/login`, `/register`, `/forgot-password`, `/e2e/error-harness`). Authed admin/portal routes require the storage-state helper and are deferred to Step 93 (preview seed E2E matrix), which extends the same driver with `use.storageState` per role.

This split is intentional: Step 86's job is to prove the pipeline, artifact naming, and workflow wiring so Step 87 can parse them. Step 93 fills the audited-route rows.

---

## Route Set (Step 86 baseline)

| Route path            | Rationale                                                                                                             |
|-----------------------|-----------------------------------------------------------------------------------------------------------------------|
| `/`                   | Landing shell + fonts + tokens. Layout regression canary.                                                             |
| `/admin/login`        | Auth form. Verifies `Retry-After` banner does not bleed into pre-auth screens under `rate-limited`.                   |
| `/register`           | Public bootstrap surface.                                                                                             |
| `/forgot-password`    | Public reset flow.                                                                                                    |
| `/e2e/error-harness`  | Only public surface that programmatically dispatches `LaraApiError` into `error-store`, capturing Global Error Modal. |

Adding a route: append to `PUBLIC_MATRIX_ROUTES` in `tests/e2e/specs/preview-screenshot-matrix.spec.ts` and update this table. No other change required.

---

## Matrix Contract

Each cell is one PNG. The full cross product is:

```
routes (5) x scenarios (4) x seeds (3) = 60 cells
```

Scenarios: `null | offline | slow | rate-limited` (INV-PS-01).
Seeds: `default | empty | error`.

The `slow` scenario intentionally captures mid-flight state (spinner). The driver waits for `PREVIEW_SLOW_LATENCY_MS / 2` before snapshotting, then aborts inflight fetches, so the screenshot reflects the loading UI, not the resolved page.

---

## Artifact Layout

```
tests/e2e/screenshots/preview-matrix/
  index.json                       # machine-readable manifest, Step 87 input
  <route-slug>/
    <scenario>.<seed>.png          # e.g. admin-login/rate-limited.default.png
```

Rules:

- Route slug is the URL path with `/` -> `-`, leading `-` stripped, root path becomes `root`.
- Scenario token: `null | offline | slow | rate-limited` (literal, no aliasing).
- Seed token: `default | empty | error`.
- `index.json` shape:

```json
{
  "generatedAt": "2026-07-20T16:00:00Z",
  "runtimeMode": "preview",
  "cells": [
    { "route": "/", "scenario": null, "seed": "default", "file": "root/null.default.png", "ok": true }
  ]
}
```

Missing cells MUST appear in `index.json` with `ok: false` and a reason string. Silent gaps are banned (INV-ERR-04 parity).

---

## Driver Contract

`tests/e2e/specs/preview-screenshot-matrix.spec.ts` MUST:

1. Boot the app at `/` in preview mode; wait for `window.__LARA_PREVIEW__` (INV-PS-07).
2. For each seed, call `writeRuntimeOverride({ ...cfg, PreviewSeed })` then reload the target route.
3. For each scenario, call `window.__LARA_PREVIEW__.setScenario(scenario)` before navigating (or use `?preview=<scenario>` for URL-primed cells).
4. Capture with `page.screenshot({ path, fullPage: false, animations: "disabled", clip: { x: 0, y: 0, width: 1440, height: 900 } })`. Fixed viewport, fixed clip, no full-page (INV-BR: deterministic diffs).
5. Append the outcome to `index.json` before moving on. Never batch writes at the end; a crash mid-run MUST leave the manifest truthful up to the last completed cell.
6. Reset scenario to `null` between cells (INV-PS-06 hygiene).

---

## Determinism Rules

- `animations: "disabled"` on every screenshot call.
- Fonts pre-loaded via `page.evaluate(() => document.fonts.ready)` before capture.
- System clock frozen through `Date.now` stub is **not** required at this step; time-dependent UI is tracked in Plan 17.
- Viewport locked to 1440x900. Any route needing a different viewport must document it here first.

---

## CI Wiring

`.github/workflows/preview-screenshot-matrix.yml` runs on:

- `pull_request` when `src/lib/preview-*`, `src/components/shell/PreviewDebugDrawer*`, `src/routes/**`, `tests/e2e/specs/preview-screenshot-matrix.spec.ts`, or this spec change.
- Nightly cron (00:15 UTC) so drift from non-preview edits still surfaces.

The job:

1. Installs deps, `bunx playwright install --with-deps chromium`.
2. Runs `bunx playwright test tests/e2e/specs/preview-screenshot-matrix.spec.ts --project=chromium`.
3. Uploads `tests/e2e/screenshots/preview-matrix/` as an artifact (30-day retention).
4. Fails the job if `index.json` reports any cell with `ok: false` unless the cell is on the waiver list Step 87 will introduce.

---

## Invariants

- **INV-SM-01** Every cell either has a PNG on disk or an `ok: false` entry with a reason in `index.json`. Silent omission is a bug.
- **INV-SM-02** File names use the closed-set scenario/seed tokens verbatim; renaming requires a spec bump here.
- **INV-SM-03** Manifest is written incrementally so a crash preserves partial truth.
- **INV-SM-04** Viewport / clip / animations are pinned in the driver, not in the spec, to prevent silent per-test drift.
- **INV-SM-05** Step 93 additions MUST NOT change the artifact layout; they append route directories.

---

## Non-Goals

- Visual regression diffing (deferred to Plan 17).
- Cross-browser matrix (chromium only until Step 100).
- Authed-route coverage (Step 93 owns it).
- Waiver ergonomics (Step 87 owns it).
