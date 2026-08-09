# Runtime Modes: Preview vs Dev vs Production - Overview

**Version:** 1.1.0
**Updated:** 2026-07-21
**AI Confidence:** Stable
**Ambiguity:** Low

> **v1.1 note (Plan 17):** Production-mode boot is now **gated**. A flip to
> `production` (or to a runtime override URL) MUST pass `probeBackendHealth()`
> before `saveMode()` commits, MUST log a `runtime.mode.switch` telemetry
> event (`REQUESTED` / `COMMITTED` / `ABORTED{reason}`), and MUST write the
> verified origin to `lara.runtime.lastGoodBackendUrl.v1` only after the
> probe succeeds. Failed probes and `Seed data` flips never mutate the
> last-good URL. See INV-RM-11 and INV-RM-12 below.

---

## Keywords

`runtime-mode` · `preview` · `dev` · `production` · `version.json` · `api-base-url` · `fixtures` · `typed-api` · `openapi` · `admin-runtime-toggle`

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

The Lovable preview iframe cannot reach the Laravel backend. Without a first-class runtime-mode axis, every data-driven screen degrades to `StateOffline` or `StateError`, and neither the user nor the agent can visually test flows before deploy. This module defines the runtime-mode axis (`preview`, `dev`, `production`), the single-source-of-truth `version.json` that drives it, and the invariants that all downstream specs (fixture contract, typed API, admin toggle) must satisfy.

## Modes

| Mode | Transport | Data Source | Persistence | Intended Use |
|------|-----------|-------------|-------------|--------------|
| `preview` | `preview-transport` (in-process handlers) | `src/lib/preview-seeds/*` | IndexedDB (session-scoped) | Lovable preview iframe, screenshot review, offline visual QA |
| `dev` | `lara-fetch` -> `apiBaseUrl` (localhost Laravel) | Real backend | MySQL/Postgres shards | Local developer machine with backend running |
| `production` | `lara-fetch` -> `apiBaseUrl` (published origin) | Real backend | Split-DB shards | Published cPanel deployment |

Transitions between modes MUST be observable (banner, audit log entry when done via admin toggle) and MUST NOT require a rebuild in `preview` or `dev`.

## Single Source of Truth

Repo-root `version.json` is authoritative. Fields:

- `version` (string, semver): mirrors `package.json` and `backend/composer.json`. Enforced by `linter-scripts/check-version-json.py`.
- `mode` (`"preview" | "dev" | "production"`): compile-time default; runtime overridable per Step 3 precedence.
- `apiBaseUrl` (string | null): required when `mode !== "preview"`; must be `null` in `preview`.
- `previewSeed` (`"default" | "empty" | "error"`): selects the seed loader in `preview` mode; ignored otherwise.
- `updatedAt` (ISO-8601 UTC): mtime of the last write.
- `allowRuntimeToggle` (boolean): when `false`, the admin Runtime page is read-only.

## Mode Selection Precedence

`localStorage override (admin toggle)` -> `version.json` -> `compile-time default ("preview")`.

Precedence is one-way per client boot: the resolved mode is frozen at hydration and only re-reads on explicit user action (Runtime page save or manual `localStorage.clear()`).

## Invariants (INV-RM-*)

- INV-RM-01: Exactly one mode is active per client tab; `getRuntimeMode()` never returns `undefined`.
- INV-RM-02: In `preview`, `apiBaseUrl` MUST be null and no network egress to `api.*` origins occurs; enforced by `check-untyped-fetch.py`.
- INV-RM-03: In `dev`/`production`, `apiBaseUrl` MUST be an absolute `https://` URL (or `http://localhost:*` in `dev`).
- INV-RM-04: Every endpoint reachable from `src/generated/api/operations.ts` MUST have a matching preview handler; assert in `src/lib/preview-transport.test.ts`.
- INV-RM-05: Preview handlers MUST emit the canonical LaraException envelope (`Data`, `Attributes.RequestId`, `Attributes.ErrorId` on failure) so the FE error contract is exercised identically to production.
- INV-RM-06: Runtime-config mutations MUST be audit-logged (who, from, to, when); `preview` audit lands in IndexedDB, `production` audit lands in the audit shard.
- INV-RM-07: The `RuntimeBanner` is visible in `preview` and `dev`, hidden in `production`; SSR-safe via `useHydrated()` to avoid mismatch.
- INV-RM-08: `import.meta.env.MODE` may only be read inside `src/lib/runtime-mode.ts`; all other code goes through `getRuntimeMode()`. Enforced by `check-runtime-mode-usage.py`.
- INV-RM-09: `version.json.version` MUST equal `package.json.version` AND `backend/composer.json.version`; enforced by `check-version-json.py` and the existing version-sync gate.
- INV-RM-10: Toggling mode never leaks credentials across modes; `preview` never reads `production` tokens from `localStorage`, and vice versa (namespaced storage keys).
- INV-RM-11: **Boot is gated.** Flipping to `production` (or applying a runtime backend override URL) MUST call `probeBackendHealth(url)` in `src/lib/backend-health.ts` and block the switch on failure; UI surfaces the error in `#runtime-backend-error`. Enforced by `RuntimeModeSwitch.tsx` and the E2E spec `route-error-correlation.spec.ts`.
- INV-RM-12: `lara.runtime.lastGoodBackendUrl.v1` (see `src/lib/last-good-backend-url.ts`) is written ONLY after a successful health probe. `Seed data` flips and failed probes MUST NOT mutate it. Every mode transition MUST emit `runtime.mode.switch` telemetry (`REQUESTED` / `COMMITTED` / `ABORTED{reason}`) via `logRuntimeInfo`, with metadata only (no raw URLs).

## Cross-References

- `spec/28-runtime-modes/01-version-json-schema.md` (Step 2): JSON Schema for `version.json`.
- `spec/28-runtime-modes/02-mode-selection-precedence.md` (Step 3): precedence rules formalized.
- `spec/28-runtime-modes/03-preview-fixture-contract.md` (Step 4): per-endpoint handler contract.
- `spec/28-runtime-modes/04-generated-types-contract.md` (Step 5): OpenAPI -> `src/generated/api/`.
- `spec/28-runtime-modes/05-admin-runtime-toggle.md` (Step 6): RBAC and persistence for the Runtime page.
- `spec/28-runtime-modes/06-acceptance-criteria.md` (Step 7): `AC-RM-01..AC-RM-25`.
- `spec/03-error-manage/`: preview handlers act as the error-code test bench (Plan 16 Step 92).
- `spec/07-design-system/`: empty-state guidance backing `preview-seeds/empty.ts` (Plan 16 Step 93).
- `spec/06-seedable-config-architecture/`: `version.json` is the runtime-config seed root (Plan 16 Step 94).

## Open Questions

Tracked in `spec/28-runtime-modes/07-open-questions.md` once authored (Step 8). Initial ledger:

- OQ-RM-01: `preview` persistence, IndexedDB vs in-memory Map, when the user clears storage mid-session.
- OQ-RM-02: Whether `dev` mode should proxy to a shared staging backend or require localhost only.
- OQ-RM-03: Whether `allowRuntimeToggle=false` should also hide the Runtime admin route or only lock its inputs.
