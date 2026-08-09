# Frontend Transport Gap Report

Generated: 2026-07-19 (v0.274.0)
Source: `src/lib/lara-*.ts`, cross-referenced with `docs/backend/endpoint-gap-report.md`.
Parent plan: `.lovable/plans/pending/09-fluid-ui-and-cpanel-release.md` (Step 2).

## Method

Ran `grep -oE '"/[A-Za-z][^"]*"'` across every `src/lib/lara-*.ts` file, then mapped each hit to a backend route entry. Any endpoint without a match is flagged missing. Function signatures were inspected to confirm the transport is typed (accepts a request DTO and returns a typed response), not a thin `fetch` wrapper.

## Typed transports currently shipping

| Client module | Endpoints covered | Missing endpoints in scope | Notes |
|---|---|---|---|
| lara-auth.ts | POST /Auth/Token (login) | Register, Logout, password reset (planned) | Route path is `/Auth/Token`, backend exposes `/Auth/Login`. Rename mismatch flagged below. |
| lara-me.ts | GET /Users/Me | - | Backend endpoint not declared in `api.php` yet; served by session middleware side-channel. Track in Plan 09 step 30. |
| lara-license.ts | GET/POST /Licenses (list, issue) | show, update, revoke, ledger, bindings | Admin scope only; Reseller-scoped variant absent. |
| lara-reseller.ts | GET/POST /Resellers | show, update | No reseller-slug detail transport. |
| lara-serial.ts | POST /Verify/Serial, /Verify/Hash, /Verify/Final | POST /Serials (issue) | Portal serial-issue call missing. |
| lara-impersonation.ts | POST /Impersonation/End | Impersonate (start), ForceEnd | Start path routed via `lara-user-role.ts` today; consolidate here. |
| lara-user-role.ts | assign/revoke/list roles | - | Path constants inlined; move to `closed-sets.ts` per §Magic Literals. |
| lara-prefix.ts | (none) | list/create/delete | File exists but exports type only; no transport function. |
| lara-quota.ts | (none) | list/store/cancel/approve/deny + indexAll | File exists but exports type only. |
| lara-self-update.ts | (none) | manifest/uploadTicket/publish/yank/asset/signature | File exists as placeholder. |
| lara-environment.ts | (none) | Environments CRUD (backend not shipped yet) | Blocked on backend Plan 06 step 42 follow-up. |
| lara-features.ts | (none) | Features catalog read | Backend catalog exists (FeatureCatalogSeeder); read endpoint not shipped. |

## Endpoints with no typed frontend client (29 of 41)

Admin: `/Api/Admin/Ping`, `/Api/Admin/Resellers/{slug}` (show, update), all `/Api/Admin/Prefixes/*`, `/Api/Admin/Licenses/{k}` (show, update, revoke, ledger), all `/Api/Admin/Licenses/{k}/Bindings/*`, all `/Api/Admin/Users/*` list/create/update/detail, `/Api/Admin/Impersonation/{sid}/ForceEnd`, all `/Api/Admin/QuotaRequests/*`, all `/Api/Admin/AppUpdates/*`.

Reseller: every `/Api/Reseller/*` route.

Portal: `/Api/Portal/Ping`, `/Api/Portal/Serials`.

App: every `/Api/App/*` route.

## Naming mismatches to reconcile

1. `lara-auth.ts` posts to `/Auth/Token` but the backend route is `POST /Api/Auth/Login`. Either rename the backend route (breaks nothing yet, no external callers) or fix the frontend. Recommendation: fix the frontend to `/Auth/Login` in Plan 09 step 52 (login route refit).
2. `lara-me.ts` reads `/Users/Me` but no such route exists in `api.php`. Add `GET /Api/Admin/Me` (or a shared `/Api/Me` outside admin prefix) in Plan 09 step 30 alongside the metrics endpoint.
3. `lara-shell-role.ts` and `lara-sidebar-collapsed.ts` are pure browser-state helpers, not transports; they are correctly named `lara-*` for consistency but are not counted in the transport coverage figure.

## Backfill plan (feeds Plan 09 steps 30-51)

1. Ship transport functions for the 29 uncovered endpoints, one function per action, typed with the corresponding Resource DTO from the backend gap report.
2. Move every path literal into a `PATHS` const map inside each `lara-*.ts` module (blocks magic-string regressions).
3. Add a Vitest guard `tests/transport-coverage.test.ts` that parses the backend route list and asserts every path has a matching export somewhere under `src/lib/lara-*.ts`.
4. Reconcile the two naming mismatches above in Plan 09 steps 30 + 52.

## Coverage summary

- Backend routes: 41.
- Typed transport functions: 12 (partial coverage, no endpoint is complete end-to-end with typed DTOs).
- Coverage ratio: 29 percent.
- Target after Plan 09 steps 30-51: 100 percent, gated by the coverage test above.
