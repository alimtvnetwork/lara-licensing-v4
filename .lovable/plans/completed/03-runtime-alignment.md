# Runtime Alignment with Amended Specs

Status: completed (v0.113.0, 2026-07-17)
Owner: Lovable agent
Created: 2026-07-16 (v0.101.0)

## Goal

Bring `src/lib/` runtime code and route handlers into line with the spec amendments landed during Plan 02 (v0.85.0 to v0.100.0). Spec is frozen; runtime is the drift surface.

## Scope

1. `src/lib/lara-auth.ts` refresh flow must implement the session-family reuse-detection contract from `spec/21-app/31-auth-session-family.md` (parent/child chain, `AuthRefreshReused` cascade, 90d TTL, session cap).
2. `src/lib/lara-client.ts` must attach `X-Request-Id` on every request and surface `Retry-After` per `spec/21-app/14-rate-limiting.md` v1.2.0.
3. `src/lib/lara-self-update.ts` must exercise the 3-step publish state machine from `spec/21-app/17-self-update-endpoint.md` v1.1.0 and verify SHA-256 against `AppUpdateAssets`.
4. Error taxonomy client must recognise `AuthRefreshRaceLost` (409) and `AuthSaltRotationFailed` (500) added in `spec/21-app/12-error-taxonomy.md` v1.1.0.
5. Vitest coverage: parity tests that assert each amended AC-* ID has at least one runtime assertion or a documented spec-only exemption.

## Non-goals

- No backend implementation (Laravel target lives outside this repo).
- No new UI surfaces beyond wiring existing routes to the amended transport.

## Steps

1. Inventory `src/lib/` files and map each function to its normative spec section.
2. Diff each function against the amended spec; record findings in `.lovable/pending-issues/`.
3. Fix findings in dependency order (client -> auth -> self-update -> UI hooks).
4. Add Vitest parity tests per amended AC.
5. Bump minor version per fix; update CHANGELOG + RELEASE-NOTES + README.
6. Archive this plan to `.lovable/plans/completed/` when parity report is GREEN.

## Exit criteria

- All amended AC-* IDs from Plan 02 have a runtime test or a documented exemption.
- `bunx vitest run` GREEN.
- Version pinned in `README.md`, `package.json`, `.lovable/plan.md`, `CHANGELOG.md`, `RELEASE-NOTES.md`.
