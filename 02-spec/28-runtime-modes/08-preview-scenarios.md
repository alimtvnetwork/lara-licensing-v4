# Preview Scenarios: Offline, Slow, Rate-Limited

**Version:** 2.0.0
**Updated:** 2026-07-20
**AI Confidence:** High
**Ambiguity:** Low

---

## Keywords

`preview-scenario` · `offline` · `slow` · `rate-limited` · `LaraApiError` · `Retry-After` · `x-preview-scenario` · `?preview=` · `PreviewDebugDrawer` · `INV-RM-04` · `INV-RM-06` · `INV-PS-01..12`

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

Preview mode returns golden-path fixture data by default. That is not enough to visually verify degraded UX (offline banners, retry countdowns, spinner behaviour, `Retry-After` submit-locks). This document is the single source of truth for the scenario overlay contract implemented by `src/lib/preview-scenario.ts` and consumed by `src/lib/api-client.ts::callPreview`.

Steps 80-84 of Plan 16 extended the axis beyond the original process-global setter: scenarios can now be forced from a URL query, a per-call header, or the in-app `PreviewDebugDrawer`. This revision (v2.0.0) captures the closed set, the precedence, the shipping constants, and the observability signals that downstream steps (screenshot matrix, coverage linter, E2E replay) enforce.

---

## Closed Scenario Set

Exactly four values are allowed. Any other value is a bug and MUST be rejected or logged as an ignored warning.

| Scenario         | Semantics                                                                       | Emits                                                                              |
|------------------|---------------------------------------------------------------------------------|-------------------------------------------------------------------------------------|
| `null`           | Default. Fixture handler runs normally.                                         | Fixture response.                                                                   |
| `"offline"`      | Simulated network failure before the handler runs.                              | `LaraApiError(ServerError, httpStatus=0)`, message tagged `offline`.                |
| `"slow"`         | Injects `PREVIEW_SLOW_LATENCY_MS` (2000 ms) delay, then runs the handler.       | Normal fixture response, delayed. Cancellation honoured via `AbortSignal`.          |
| `"rate-limited"` | Simulated 429 before the handler runs.                                          | `LaraApiError(RateLimited, httpStatus=429, retryAfterSeconds=<window>)`.            |

`<window>` differs by trigger:

- Process-global (setter / drawer / URL / bridge): `PREVIEW_RATE_LIMIT_RETRY_AFTER_S = 30`.
- Per-call header (`x-preview-scenario: rate-limited`): `PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S = 3`.

The two windows exist by design (Step 80). QA cannot force one screen into a 429 without the process-global setter breaking every unrelated screen, so the header path uses a short 3 s window for surgical countdown testing while the global path keeps 30 s for realistic operator drills.

---

## Trigger Surfaces (four)

| Trigger                        | Source                                              | Scope         | Setter                                                                            |
|--------------------------------|-----------------------------------------------------|---------------|-----------------------------------------------------------------------------------|
| Process-global setter          | `src/lib/preview-scenario.ts::setPreviewScenario`   | Every call    | Any code, incl. drawer / bridge / router boot.                                    |
| URL query `?preview=<value>`   | `src/router.tsx` boot, calls `parseScenarioFromSearch(window.location.search)` | Every call from boot until reset | Users, Playwright, screenshot pipeline, shared preview links.  |
| Per-call header                | Request `headers["x-preview-scenario"]` read by `callPreview` in `src/lib/api-client.ts` | Single call only | Callers that need to force a specific op without touching global state. |
| In-app `PreviewDebugDrawer`    | `src/components/shell/PreviewDebugDrawer.tsx`, mounted via `PreviewDebugDrawerLazy` | Every call    | Operators, no DevTools needed.                                                   |

The drawer is loaded via `React.lazy` behind `isPreview() || isDev()` (Plan 16 Step 84), so production bundles never include its code. See `linter-scripts/check-preview-in-prod-bundle.py`.

---

## Precedence

For a single `apiClient.call(...)`, the effective scenario is resolved in this order (highest wins):

1. **Per-call header** `x-preview-scenario` if present and valid.
2. **Process-global** `getPreviewScenario()` (last write via setter / drawer / URL parse / bridge).
3. **`null`** (default, run the fixture handler).

The URL param does not "win over" the global setter at request time; it feeds the global setter once at boot. A later `setPreviewScenario(null)` (drawer "Normal", bridge `clear()`) supersedes the URL param without a reload.

Unknown values at any layer are ignored with a `console.warn`, never thrown:

- Setter: `preview-scenario: setPreviewScenario ignoring unknown value` (v0.558+).
- URL parser: `preview-scenario: ignoring unknown ?preview= search value` (v0.587+).
- Header parser: `preview-scenario: ignoring unknown x-preview-scenario header value` (v0.586+).

---

## Invariants

- **INV-PS-01** The scenario set is closed: `{ null, "offline", "slow", "rate-limited" }`. All four triggers MUST reject or warn-and-drop unknown strings.
- **INV-PS-02** Scenarios apply only when `getRuntimeMode() === "preview"`. In `dev` and `production` all triggers MUST be no-ops. Production MUST NOT even ship the drawer chunk (Step 84).
- **INV-PS-03** `offline` and `rate-limited` MUST throw a `LaraApiError` before invoking the fixture handler (no partial data leaks).
- **INV-PS-04** `slow` MUST honour `AbortSignal` and reject with `AbortError` when the caller cancels.
- **INV-PS-05** `rate-limited` MUST populate `retryAfterSeconds` so `useSubmitLock` and `Retry-After` propagation stay honest. The value follows the trigger table above (3 s header, 30 s global).
- **INV-PS-06** Process-global scenario state is module-scoped and MUST NOT persist across page reloads. A reload returns to `null` unless `?preview=` is present in the URL.
- **INV-PS-07** Setter / getter / clear MUST be exposed on `window.__LARA_PREVIEW__` in preview mode only (see `09-operator-guide.md`).
- **INV-PS-08** Per-call header MUST NOT leak into subsequent calls; `callPreview` reads it fresh each invocation.
- **INV-PS-09** URL parser is case-insensitive; explicit `?preview=` (empty value) resets to `null`; absent param preserves the current scenario (`undefined` sentinel).
- **INV-PS-10** All scenario-emitted errors flow through the standard `LaraApiError` -> `errorStore` -> Global Error Modal pipeline. There is no scenario-specific error UI.
- **INV-PS-11** Every trigger MUST log a structured observability line at info level (`console.info("[preview-*] ...")`) so QA can grep. Silent scenario changes are a bug.
- **INV-PS-12** The drawer wrapper (`PreviewDebugDrawerLazy`) MUST return `null` in production before touching any drawer or preview-scenario module import; static imports of the drawer module outside its file and wrapper are banned by `linter-scripts/check-preview-in-prod-bundle.py`.

---

## Shipping Constants

Defined in `src/lib/preview-scenario.ts`, never inlined at call sites (Core rule: no magic literals):

- `PREVIEW_SLOW_LATENCY_MS = 2000`
- `PREVIEW_RATE_LIMIT_RETRY_AFTER_S = 30`
- `PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S = 3`
- `VALID_SCENARIOS: ReadonlySet<PreviewScenario> = new Set([null, "offline", "slow", "rate-limited"])`

---

## Control Bridge

Exposed by `src/router.tsx` on client boot in preview mode only:

```ts
window.__LARA_PREVIEW__ = {
  setScenario(s: PreviewScenario): void,
  getScenario(): PreviewScenario,
  clear(): void, // equivalent to setScenario(null)
}
```

Playwright example (Step 55 smoke):

```ts
await page.evaluate(() => window.__LARA_PREVIEW__.setScenario("rate-limited"));
```

URL example (Step 81):

```
https://<preview-host>/admin/licenses?preview=slow
```

Header example (Step 80):

```ts
apiClient.call("admin.licenses.create", payload, {
  headers: { "x-preview-scenario": "rate-limited" },
});
```

---

## Screenshot Matrix (Steps 86-87 source of truth)

The route x scenario matrix downstream steps enforce is derived from this doc. Rows are the closed scenario set; columns are the seed set (`default | empty | error`, from `03-preview-fixture-contract.md`). Every audited route (`/admin/licenses`, `/admin/quotas`, `/admin/audit`, `/portal/serials`, `/admin/runtime`) MUST produce a screenshot for each cell in the matrix or waive it in `linter-scripts/check-screenshot-coverage.py` (Step 87).

```
                default          empty            error
null            <route>.n.d.png  <route>.n.e.png  <route>.n.err.png
offline         <route>.o.d.png  <route>.o.e.png  <route>.o.err.png
slow            <route>.s.d.png  <route>.s.e.png  <route>.s.err.png
rate-limited    <route>.rl.d.png <route>.rl.e.png <route>.rl.err.png
```

Naming, tolerance, and the CI job that regenerates the matrix are pinned in Step 86's spec (`07-ui-baselines/preview-scenarios.md`, authored next).

---

## Testing Rules

- Unit: `tests/preview-scenario.test.ts`, `tests/preview-scenario-integration.test.ts`, `tests/preview-scenario-url-param.test.ts`, `tests/preview-slow-scenario.test.ts`, `tests/preview-rate-limit-header.test.ts` MUST cover each trigger and each scenario against at least one operation.
- E2E: `tests/e2e/specs/preview-scenario-smoke.spec.ts` MUST assert that `slow` renders a spinner and `rate-limited` renders the retry banner.
- Drawer: `tests/preview-debug-drawer.test.tsx` and `tests/preview-debug-drawer-lazy.test.tsx` cover the operator surface and its tree-shake guard.
- Lint: `linter-scripts/check-preview-in-prod-bundle.py` runs as part of `bun run lint:api-surface`.

---

## Non-Goals

- Chaos-style random scenario injection (deferred, tracked in `07-open-questions.md`).
- Scenario persistence across reloads without the `?preview=` param.
- Scenario-specific error UI (all errors go through the Global Error Modal).
- Extending the closed set without a spec bump; adding a fifth scenario is a v3 change.

---

## Change Log

- **v2.0.0** (2026-07-20, Plan 16 Step 85): documented URL param (Step 81), per-call header + 3 s window (Step 80), drawer (Step 83), drawer tree-shake guard (Step 84), precedence, INV-PS-08..12, screenshot matrix contract.
- **v1.0.0** (2026-07-20, Plan 16 Step 54): initial scenario set, control bridge, INV-PS-01..07.
