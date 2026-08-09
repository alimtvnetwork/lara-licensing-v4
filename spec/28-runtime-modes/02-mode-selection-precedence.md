# Mode Selection Precedence

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`precedence` · `runtime-mode` · `localStorage` · `version.json` · `hydration` · `freeze` · `namespaced-storage` · `admin-override`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ (`spec/28-runtime-modes/00-overview.md`) |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the algorithm that resolves the active `RuntimeMode` and `ApiBaseUrl` at client boot, and the discipline for changing them at runtime. Steps 13-16 (`src/lib/runtime-mode.ts`, `src/hooks/use-runtime-mode.ts`, `src/lib/version-json-loader.ts`, SSR-safe hydration) MUST implement exactly this algorithm; Step 17 (`tests/runtime-mode.test.ts`) MUST cover every case listed here.

## Precedence Chain

```
localStorage override (admin toggle)
        v (only if present AND valid AND same Version)
    version.json (fetched from /version.json)
        v (only if reachable AND valid)
    compile-time default: { Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" }
```

Rules:

- **Rule P-01.** Higher-priority source wins entirely per field-group `{ Mode, ApiBaseUrl, PreviewSeed }`. No field-level cascading (never mix `Mode` from `localStorage` with `ApiBaseUrl` from `version.json`).
- **Rule P-02.** `localStorage` override is honored only when its embedded `Version` equals the current `version.json.Version`. Version drift invalidates the override and falls through to `version.json`. Prevents stale preview overrides surviving a deploy.
- **Rule P-03.** `version.json` is honored only when the fetch resolves to a body that validates against `spec/28-runtime-modes/01-version-json-schema.md`. Any parse or schema failure logs to `console.error` with a `LaraException`-shaped payload (`Code: RUNTIME_CONFIG_LOAD_FAILED`, `RequestId`, `ErrorId`) and falls through.
- **Rule P-04.** Compile-time default is the last resort. It never masks a real load failure: the error MUST still be surfaced (banner + `console.error`), otherwise INV-RM-11 (no silent failure) is violated.

## Freeze-at-Hydration

- **Rule F-01.** The resolved `RuntimeConfig` is computed once during SSR (server side sees compile-time default only) and re-computed exactly once on the client during hydration.
- **Rule F-02.** After hydration, the value is frozen inside the Zustand store. Subsequent reads via `getRuntimeMode()` / `useRuntimeMode()` MUST NOT re-run precedence.
- **Rule F-03.** Only two events unfreeze the store: (a) admin Runtime page save action (Step 57), (b) explicit developer call to `resetRuntimeMode()` exposed only under `isPreview() || isDev()` (used by tests and the debug drawer at Step 83).
- **Rule F-04.** SSR HTML MUST render as if `Mode === "preview"` regardless of build, so hydration diff on `<RuntimeBanner>` and gated data reads is zero. `useHydrated()` gates any client-only branch.

## Storage Namespacing (INV-RM-10)

To prevent cross-mode leakage of the admin override, tokens, and preview state:

| Key | Written in | Read in | Purpose |
|-----|-----------|---------|---------|
| `lara.runtime.override.v1` | any mode | any mode | JSON `{ Version, Mode, ApiBaseUrl, PreviewSeed, WrittenAt }` |
| `lara.preview.session.v1` | `preview` only | `preview` only | IndexedDB-mirrored mutation buffer index |
| `lara.auth.session.production.v1` | `production` only | `production` only | Real backend session |
| `lara.auth.session.dev.v1` | `dev` only | `dev` only | Localhost backend session |
| `lara.auth.session.preview.v1` | `preview` only | `preview` only | Seeded fake session |

Rules:

- **Rule S-01.** Every storage read gates on `getRuntimeMode()` and the key's mode suffix. A `preview`-mode client MUST NOT read `lara.auth.session.production.v1` even if the browser holds one from a prior deploy.
- **Rule S-02.** Mode transition via admin toggle triggers a targeted purge of the previous mode's `lara.auth.session.*` key (only). The `lara.runtime.override.v1` is rewritten with the new mode; `lara.preview.session.v1` is preserved (INV-RM-10 does not require destroying the preview snapshot when leaving preview).
- **Rule S-03.** Storage writes MUST be wrapped in a try/catch that logs `STORAGE_WRITE_FAILED` with `RequestId` and re-throws to the caller. Silent swallow is banned by INV-RM-11.

## Resolution Algorithm (pseudo)

```ts
async function resolveRuntimeConfig(): Promise<RuntimeConfig> {
  // 1. compile-time default (also the SSR value)
  let cfg: RuntimeConfig = { Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" };

  // 2. version.json (client only, gated by useHydrated())
  const remote = await loadVersionJson(); // returns { ok, data | error }
  if (remote.ok && remote.data.Version === PACKAGE_VERSION) {
    cfg = pickModeFields(remote.data);
  } else if (!remote.ok) {
    logRuntimeError("RUNTIME_CONFIG_LOAD_FAILED", remote.error);
    // fall through, cfg stays at default
  }

  // 3. localStorage override (only if valid AND Version matches current)
  const raw = safeLocalStorageGet("lara.runtime.override.v1");
  if (raw) {
    const parsed = parseOverride(raw); // returns { ok, data | error }
    if (parsed.ok && parsed.data.Version === PACKAGE_VERSION) {
      cfg = pickModeFields(parsed.data);
    } else if (!parsed.ok) {
      logRuntimeError("RUNTIME_OVERRIDE_INVALID", parsed.error);
      // fall through to cfg from version.json / default
    }
  }

  return cfg;
}
```

The real implementation MUST keep every function body under the 15-line cap (project memory Core rule); split helpers as needed.

## Test Matrix (Step 17)

| # | localStorage | version.json | Expected Result |
|---|--------------|--------------|-----------------|
| 1 | absent | valid `preview` | preview from version.json |
| 2 | absent | valid `production` | production from version.json |
| 3 | valid `dev`, same Version | valid `preview` | dev from override |
| 4 | valid `dev`, older Version | valid `preview` | preview from version.json (P-02 drift) |
| 5 | invalid JSON | valid `preview` | preview from version.json + `RUNTIME_OVERRIDE_INVALID` logged |
| 6 | absent | 404 | compile-time default + `RUNTIME_CONFIG_LOAD_FAILED` logged |
| 7 | absent | schema violation | compile-time default + `RUNTIME_CONFIG_LOAD_FAILED` logged |
| 8 | valid `preview` | schema violation | preview from override (override still validates) |
| 9 | valid `production` w/ null ApiBaseUrl | valid `preview` | `RUNTIME_OVERRIDE_INVALID` (schema P-01 rejects), fall through |
| 10 | after `resetRuntimeMode()` | valid `preview` | re-resolves; passes through override check again |

## Error-Contract Alignment

- **Codes emitted:** `RUNTIME_CONFIG_LOAD_FAILED`, `RUNTIME_OVERRIDE_INVALID`, `STORAGE_WRITE_FAILED`. To be registered in `spec/03-error-manage/03-error-code-registry` when Step 13 lands.
- **Correlation:** every log line MUST include a boot-scoped `RequestId` (generated once per client boot) and per-call `ErrorId`.
- **Visibility:** on P-04 fallback, `<RuntimeBanner>` (Step 52) MUST show `mode=preview (fallback)` so the operator can see that `version.json` failed to load.

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-01, INV-RM-08, INV-RM-10.
- `spec/28-runtime-modes/01-version-json-schema.md`: the schema P-03 validates against.
- `spec/03-error-manage/`: envelope + error-code registry (codes above).
- Plan 16 Steps 13 (`runtime-mode.ts`), 14 (`use-runtime-mode.ts`), 15 (`version-json-loader.ts`), 16 (SSR hydration), 17 (test file), 57 (admin toggle write path), 83 (debug drawer `resetRuntimeMode`).
