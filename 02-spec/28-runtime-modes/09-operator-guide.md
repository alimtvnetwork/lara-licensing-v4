# Runtime Modes: Operator Guide

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`operator-guide` · `runtime-mode` · `preview-bridge` · `bypass-detection` · `window.__LARA_PREVIEW__` · `laraFetch` · `requestLaraApi`

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

Operational runbook for switching runtime modes, driving preview scenarios, and diagnosing preview-bypass bugs. Companion to `02-mode-selection-precedence.md` and `08-preview-scenarios.md`.

## 1. Selecting a Mode

Precedence (highest wins):
1. LocalStorage override written by `/admin/runtime` toggle (`allowRuntimeToggle: true`).
2. `public/version.json.Mode`.
3. Compile-time default (`preview`).

Change modes via:

- **UI:** `/admin/runtime` (root only). Emits an audit event and rewrites `localStorage`.
- **Repo:** edit `public/version.json`, set `Mode` to `"preview" | "dev" | "production"`, ship.
- **DevTools:** `localStorage.setItem("lara.runtime.mode", "dev")` then reload. Requires `AllowRuntimeToggle=true`.

## 2. Driving Preview Scenarios

Four triggers, resolved per-call in this order (highest wins): per-call `x-preview-scenario` header > process-global setter (setter / drawer / URL parse / bridge) > `null`. See `08-preview-scenarios.md` for the closed set, invariants, and shipping constants.

Global bridge:

```js
window.__LARA_PREVIEW__.setScenario("offline");     // simulate network failure
window.__LARA_PREVIEW__.setScenario("slow");        // 2s latency
window.__LARA_PREVIEW__.setScenario("rate-limited");// 429, Retry-After 30s
window.__LARA_PREVIEW__.clear();                    // back to default
```

URL query (Step 81):

```
/admin/licenses?preview=slow
/admin/licenses?preview=          # explicit reset to null
```

Per-call header (Step 80, 3 s Retry-After window for surgical mutation testing):

```ts
apiClient.call("admin.licenses.create", payload, {
  headers: { "x-preview-scenario": "rate-limited" },
});
```

In-app drawer (Step 83, Cmd/Ctrl+Shift+D): flips scenario and seed without DevTools. Loaded via `PreviewDebugDrawerLazy` (Step 84), so production bundles never ship the drawer chunk.

Reload clears the process-global scenario (INV-PS-06) unless `?preview=` is present. Not honoured outside preview mode (INV-PS-02).


## 3. Preview Bypass Detection

Two guards report legacy calls that skip the preview transport:

| Layer                              | Guard                          | Message tag                          |
|------------------------------------|--------------------------------|--------------------------------------|
| `src/lib/lara-fetch.ts`            | `assertNotPreview(path)`       | `laraFetch preview bypass`           |
| `src/lib/lara-api-client.ts`       | `assertRequestNotPreview(path)`| `requestLaraApi preview bypass`      |

Both:
- `console.error` with `{ path }` for observability.
- Throw `LaraApiError(ServerError, status=0)` so the Global Error Modal surfaces the offender instead of a silent white screen.

### Triage

1. Reproduce in preview mode.
2. Open DevTools console; the offending `path` is logged.
3. Migrate the caller to `apiClient.call(<operationId>, ...)` using the generated types in `src/generated/api/`.
4. Add a Vitest that pins runtime mode to `"preview"` and asserts the migrated call succeeds.

## 4. Audit / Verification Checklist

- [ ] `/admin/runtime` toggle produces an audit event with `actorId`, `previousMode`, `nextMode`, `requestId`.
- [ ] `laraFetch` and `requestLaraApi` guards log the bypass path (grep console for `preview bypass`).
- [ ] Every scenario emits its documented `LaraApiError` shape (see `08-preview-scenarios.md`).
- [ ] `window.__LARA_PREVIEW__` is `undefined` when `getRuntimeMode() !== "preview"`.

## 5. Common Failure Modes

| Symptom                                          | Root cause                                                              | Fix                                                                  |
|--------------------------------------------------|-------------------------------------------------------------------------|----------------------------------------------------------------------|
| Blank screen, no network activity                | Legacy lib calls `requestLaraApi` directly.                             | Migrate to `apiClient.call`; guard now surfaces it via error modal.  |
| Scenario toggle has no effect                    | Not in preview mode (INV-PS-02) or scenario invalid (INV-PS-01).        | Verify `getRuntimeMode()`; confirm setter argument is in closed set. |
| Retry countdown missing on 429                   | Handler swallowed `retryAfterSeconds`.                                  | Ensure `useSubmitLock` / `LaraApiError` are threaded to the button.  |
| Toggle fails with 403                            | Non-root actor.                                                         | Grant root role or use CLI mode change.                              |

## 6. Non-Goals

- Persisting scenarios across reloads.
- Per-operation scenario scoping.
- Replacing the toggle UI with a headless HTTP API (tracked separately).
