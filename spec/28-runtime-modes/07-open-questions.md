# Runtime Modes: Open Questions Ledger (OQ-RM)

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`open-questions` · `oq-rm` · `ledger` · `decisions` · `deferred` · `blocking`

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

Single source of truth for every unresolved decision in `spec/28-runtime-modes/**`. Every OQ-RM entry has a closed-set `Status`, an `Owner`, a `DecideBy` (Plan 16 step id), an `ImpactIfUnanswered`, and (when closed) a `Resolution` line pointing to the PR/commit that closed it. No prose OQ may live outside this file after Plan 16 Step 8 lands.

## Status Enum (closed set)

- **OPEN**: question is known, decision required before the referenced Plan 16 step lands.
- **DEFERRED**: intentionally not decided now; safe default applied; will revisit at `DecideBy`.
- **CLOSED**: decision recorded in `Resolution`; entry stays for history.
- **SUPERSEDED**: replaced by a newer OQ-RM entry (`Resolution` names the successor).

## Rules

- **L-01.** Every new OQ MUST be appended here, not left inline in any other spec. Sibling specs may reference `OQ-RM-##` by id.
- **L-02.** `Owner` is a role handle (`root-admin`, `backend-lead`, `frontend-lead`, `qa-lead`), not a person.
- **L-03.** `DecideBy` is a Plan 16 step id (for example `Step 9`) or `Plan 17+` when deferred beyond Plan 16.
- **L-04.** Closing an OQ requires editing this file in the same PR that implements the decision, with `Resolution` naming the commit sha or PR number and the sibling spec section that now pins the decision.
- **L-05.** Never delete a closed OQ. History is the audit trail.

---

## Ledger

### OQ-RM-01: URL query parameter name for mode override

- **Status**: CLOSED
- **Owner**: frontend-lead
- **DecideBy**: Step 13 (runtime-mode core)
- **Question**: What URL query key overrides `Mode` at boot? Options: `?mode=`, `?rm=`, `?_mode=`.
- **ImpactIfUnanswered**: Precedence rule P-01 in `02-mode-selection-precedence.md` cannot be implemented; test AC-RM-03 cannot assert a key.
- **Resolution**: Pinned as `?mode=` in `spec/28-runtime-modes/02-mode-selection-precedence.md §P-01` (v0.519.0). Underscore prefix rejected as unfriendly to bookmarks; `?rm=` rejected as opaque.

### OQ-RM-02: `PreviewSeed` naming convention

- **Status**: OPEN
- **Owner**: frontend-lead
- **DecideBy**: Step 9 (create root `version.json`)
- **Question**: What is the closed set of `PreviewSeed` identifiers shipped by default? Draft candidates: `default`, `empty`, `error`, `large-tenant`, `single-tenant`, `expired-license`.
- **ImpactIfUnanswered**: Step 9 cannot commit `version.json` with a valid `PreviewSeed`; preview handlers (Steps 40-50) have nowhere to branch behavior; AC-RM-08 scenario tests cannot fixture-load.
- **ProposedDefault**: `default` (single tenant, license valid, small dataset). Additional seeds land per domain in Steps 40-50 and get appended to a `PreviewSeed` enum in `01-version-json-schema.md`.
- **ImpactOfDefault**: Safe; every handler MUST accept `default` and MAY branch on other seeds.

### OQ-RM-03: Storage key namespace prefix

- **Status**: CLOSED
- **Owner**: frontend-lead
- **DecideBy**: Step 3 (precedence spec)
- **Question**: What prefix scopes runtime-mode storage keys under `localStorage` and `sessionStorage`?
- **ImpactIfUnanswered**: Two apps on the same origin (dev + preview) would clobber each other's overrides.
- **Resolution**: Pinned in `spec/28-runtime-modes/02-mode-selection-precedence.md §S-01..S-03` as `lara.runtime.mode`, `lara.runtime.apiBaseUrl`, `lara.runtime.previewSeed` (v0.519.0).

### OQ-RM-04: `RUNTIME_CONFIG_LOAD_FAILED` retry policy on boot

- **Status**: OPEN
- **Owner**: frontend-lead
- **DecideBy**: Step 16 (SSR boot integration)
- **Question**: If `/version.json` returns a transient network error (offline, DNS blip), does the resolver retry, and how many times, with what backoff, before rendering `StateError`?
- **ImpactIfUnanswered**: AC-RM-01 asserts fail-closed on 404 but is silent on transient failures; users see `StateError` on a single flaky request.
- **ProposedDefault**: Zero retries at boot. `/version.json` is same-origin and served with the app bundle; a failure means the deploy is broken, not the network. The FE `StateError` includes a manual "Retry" that reloads the page.
- **ImpactOfDefault**: Aligns with fail-closed posture; adds one user-facing action (reload).

### OQ-RM-05: Preview handler determinism across reloads

- **Status**: OPEN
- **Owner**: frontend-lead
- **DecideBy**: Step 37 (preview store)
- **Question**: Is the preview seed store (IndexedDB) persisted across reloads or reset on every boot?
- **ImpactIfUnanswered**: Playwright suites (Step 65+) cannot assume clean state; handlers cannot assume dirty state; AC-RM-07 conflict flow depends on this.
- **ProposedDefault**: Persist across reloads within the same `PreviewSeed`; reset when `PreviewSeed` changes. Provide a "Reset preview data" action in the debug drawer (AC-RM-24).
- **ImpactOfDefault**: Matches user expectation that a demo carries state between page reloads; explicit reset avoids surprises.

### OQ-RM-06: `LARA_ALLOW_PROD_TO_PREVIEW` env var scope

- **Status**: OPEN
- **Owner**: backend-lead
- **DecideBy**: Step 58 (admin runtime-config endpoint)
- **Question**: Is `LARA_ALLOW_PROD_TO_PREVIEW=1` read per-request from `env()` (allowing hot-swap without deploy) or captured at boot?
- **ImpactIfUnanswered**: Safety rail S-01 in `05-admin-runtime-toggle.md` has ambiguous timing; a compromised admin could set the env at request time if the reader is per-request.
- **ProposedDefault**: Read at boot into a singleton config value; changing the env requires a process restart. This matches Laravel's `config()` cache posture and blocks runtime bypass.
- **ImpactOfDefault**: Small operational friction (restart to enable prod-to-preview drills) traded for a stronger safety rail.

### OQ-RM-07: Audit event delivery under `version.json` write failure

- **Status**: OPEN
- **Owner**: backend-lead
- **DecideBy**: Step 61 (audit event)
- **Question**: Rule AU-01 says a failed audit write triggers a compensating rewrite. What if the compensating rewrite ALSO fails?
- **ImpactIfUnanswered**: The host is left with committed on-disk state but no audit trail; INV-RM-07 is violated.
- **ProposedDefault**: Log `AUDIT_COMPENSATION_FAILED` at critical level with the full Before/After diff and both errno values; return 500 to the client; leave the on-disk state as-is (do not retry). Post-incident, the diff plus logs are the audit trail.
- **ImpactOfDefault**: Preserves partial safety when disk itself is failing; escalates via alerting rather than pretending the write did not happen.

### OQ-RM-08: Debug drawer visibility in production

- **Status**: DEFERRED
- **Owner**: frontend-lead
- **DecideBy**: Plan 17+ (post Plan 16)
- **Question**: Should the debug drawer be reachable in `production` mode for root-admin only, or hidden entirely?
- **ImpactIfUnanswered**: AC-RM-24 asserts the drawer surfaces resolved config in non-production; production behavior undefined.
- **ProposedDefault**: Hidden in production. Root-admin who needs it flips to `dev` via the admin toggle, which forces a reload (AC-RM-21).
- **ImpactOfDefault**: Clean production UX; explicit path to diagnostics.

---

## Coverage

- Closed: OQ-RM-01, OQ-RM-03 (2).
- Open, blocking near-term step: OQ-RM-02 (Step 9), OQ-RM-04 (Step 16), OQ-RM-05 (Step 37), OQ-RM-06 (Step 58), OQ-RM-07 (Step 61) (5).
- Deferred: OQ-RM-08 (Plan 17+) (1).

Every OPEN entry has a ProposedDefault so the referenced Plan 16 step can proceed if a formal decision is not recorded first; landing that step with a decision closes the OQ in the same PR.

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: seed OQ list superseded by this ledger.
- `spec/28-runtime-modes/01-version-json-schema.md`, `02-mode-selection-precedence.md`, `03-preview-fixture-contract.md`, `04-generated-types-contract.md`, `05-admin-runtime-toggle.md`, `06-acceptance-criteria.md`.
- Plan 16 Steps 9, 13, 16, 37, 58, 61, and Plan 17+ for deferred items.
