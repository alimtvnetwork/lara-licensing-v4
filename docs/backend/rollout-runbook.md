# Preview -> Dev -> Production Rollout Runbook

**Plan 16 Step 90.** End-to-end operator SOP for cutting a fresh build over from
`preview` (default, no backend) to `dev` (local backend) to `production`
(deployed backend). This runbook sequences the CI gates, the admin Runtime
toggle flips, the traffic-verification checks, and the abort paths into one
document.

Upstream contracts (authoritative, do not paraphrase in code):

- Runtime modes contract: [`spec/28-runtime-modes/`](../../spec/28-runtime-modes/)
- Admin toggle SOP: [`docs/backend/admin-runtime-runbook.md`](./admin-runtime-runbook.md)
- Preview scenarios v2: [`spec/28-runtime-modes/08-preview-scenarios.md`](../../spec/28-runtime-modes/08-preview-scenarios.md)
- Screenshot matrix: [`spec/28-runtime-modes/10-screenshot-matrix.md`](../../spec/28-runtime-modes/10-screenshot-matrix.md)
- Error contract axis: [`spec/03-error-manage/`](../../spec/03-error-manage/)

## Rollout phases

```text
preview  (no backend, IndexedDB seeds)
   |  Phase 1: CI green + acceptance
   v
dev      (localhost / .local backend, dev seeded data)
   |  Phase 2: contract parity + smoke
   v
production (https://<prod-host>, real data)
```

## Phase 0: pre-flight

Do NOT start a rollout until every item is true.

1. Working tree matches the git tag being rolled: `git status` clean,
   `git rev-parse HEAD` matches the release SHA in `RELEASE-NOTES.md`.
2. All required CI gates are green on that SHA:
   - `backend-static-analysis`, `frontend-static-analysis`
   - `error-contract` (envelope + code parity)
   - `backend-e2e`, `frontend-e2e`
   - `preview-screenshot-matrix` (60/60 cells `ok: true`, PNGs on disk)
   - `release-smoke`
3. `bun run verify` and `bun run lint:api-surface` clean locally on the SHA
   (untyped-fetch, any-in-api, magic-endpoints, preview drawer tree-shake,
   screenshot coverage all pass).
4. `public/version.json` on the SHA matches the version pinned in `README.md`
   line 5, the version badge, `package.json`, and `backend/composer.json`.
5. Rollback plan captured: previous release SHA, previous `version.json`
   snapshot, on-call operator named, communication channel open.

If any item fails, stop. Do not proceed to Phase 1.

## Phase 1: preview -> dev

Goal: verify the build actually talks to a real backend without shipping to
users.

1. Boot the dev backend on `http://localhost:8080` (or your `.local` host).
   Confirm `/api/health` returns 200 with `X-Request-Id` echoed.
2. Boot the frontend: `bun install && bun run dev`. It boots into preview.
3. Open Admin > Runtime as `root-admin`. Follow
   [`admin-runtime-runbook.md`](./admin-runtime-runbook.md) preconditions.
4. Flip via the admin page (not by hand-editing `version.json`, rule W-01):
   - `Mode = dev`
   - `ApiBaseUrl = http://localhost:8080` (or `http://<host>.local`)
   - `PreviewSeed = null`
5. Confirm the response carries `Attributes.RequiresReload = true` (rule S-02),
   every open tab hard-reloads, and the new `ETag` matches the new
   `UpdatedAt`.
6. Verify traffic actually hits the backend:
   - Open the browser Network panel. Confirm requests go to
     `http://localhost:8080/api/*`, not to preview handlers.
   - Confirm each response carries `X-Request-Id`, `X-Lara-Version`, and
     (on errors) the canonical envelope from
     [`spec/03-error-manage/`](../../spec/03-error-manage/).
   - The preview debug drawer (Cmd/Ctrl+Shift+D) renders `null` in dev
     (INV-PD-04 / drawer tree-shake guard).
7. Smoke the axis-critical flows:
   - Auth login (real Supabase session, not preview seed).
   - License list + detail (`If-Match` on `Version` round-trip, INV-LIC-*).
   - One admin mutation (Runtime page counts, or a benign audit toggle) and
     confirm the audit event lands.
8. If any step fails, abort per Phase 4 and stay on preview.

## Phase 2: dev -> production

Goal: cut the deployed site from dev to production without user-visible
downtime.

1. Announce a 5-minute window; every open tab will hard-reload on the flip
   (rule S-02).
2. Confirm the production backend is reachable from the browser:
   `curl -sSf https://<prod-host>/api/health` returns 200 with `X-Request-Id`.
3. Confirm production TLS: `https://` scheme, valid cert, HTTP/2 or HTTP/3.
   Dev/localhost is rejected by the `production` mode-validator (rule M-02,
   422 `RUNTIME_CONFIG_MODE_MISMATCH`).
4. Open Admin > Runtime on the deployed site. Confirm current state matches
   Phase 1 (`Mode = dev`, `ApiBaseUrl` = your dev host).
5. Flip:
   - `Mode = production`
   - `ApiBaseUrl = https://<prod-host>`
   - `PreviewSeed = null`
   Tick the diff-confirmation checkbox (rule U-04). Submit with `If-Match`
   set to the current `ETag`.
6. On success, audit event `runtime_config.updated` is emitted. Capture the
   `X-Request-Id` in your rollout log for post-incident correlation.
7. Verify traffic actually hits production:
   - Network panel: requests go to `https://<prod-host>/api/*`.
   - Sample five reads and one write. Each response carries `X-Request-Id`,
     `X-Lara-Version`, and (on error) the canonical envelope.
   - Server logs show the same `X-Request-Id` values with your operator
     `sub` claim (rule OBS-01).
8. If Step 5 returns any envelope error, do not retry blindly. Cross-reference
   the code -> action table in
   [`admin-runtime-runbook.md`](./admin-runtime-runbook.md#response-code-operator-action)
   and fix the input, not the endpoint.

## Phase 3: post-cutover verification (T+5, T+30, T+60)

At each checkpoint:

1. `curl -sSf https://<prod-host>/api/health` returns 200.
2. Frontend loads without console errors; preview drawer is `null`
   (INV-PD-04).
3. `runtime_config.updated` audit event count matches the number of intended
   flips (usually 1).
4. Error-envelope rate on axis-critical endpoints is within baseline
   (compare to the prior week from `spec/03-error-manage/` telemetry).
5. `X-Request-Id` correlation across FE console -> backend logs works for at
   least one sampled failing request. If you cannot correlate, observability
   is broken and rollback is warranted even without a user-visible bug
   (silent-failure rule from the coding guidelines).

## Phase 4: abort / rollback

If Phase 1 or Phase 2 verification fails, or Phase 3 shows envelope error
spikes:

1. Reopen Admin > Runtime. Flip back to the previous mode using the same
   `If-Match` + diff-confirmation flow.
2. Production -> preview is intentionally guarded: set
   `LARA_ALLOW_PROD_TO_PREVIEW=1` on the host before flipping (safety rail
   S-01). Do not remove the guard permanently.
3. If `AllowRuntimeToggle=false` was already set (rule M-03, one-way), the
   toggle is inert and rollback requires a deploy that ships a corrected
   `version.json`. This is by design; do not add a bypass.
4. Capture: the failing `X-Request-Id`, the envelope `Code`, the operator
   `sub`, and the audit event pair (`runtime_config.updated` or
   `runtime_config.denied`). Attach to the incident ticket.
5. Announce the rollback in the same channel as the cutover.

## Do NOT

- Do not hand-edit `public/version.json` on a deployed host (rule W-01).
- Do not use `supabaseAdmin` to bypass `RuntimeConfigPolicy` (rule A-03).
- Do not skip the diff-confirmation checkbox to save a click (rule U-04).
- Do not treat a 409 `RUNTIME_CONFIG_CONFLICT` as retriable without
  refetching `ETag` (rules C-01, C-02).
- Do not flip `AllowRuntimeToggle=false` casually; it is one-way at runtime
  and only a deploy can re-enable it (rule M-03).
- Do not roll forward through a failing Phase 3 hoping metrics stabilize.
  Rollback is cheap; a stuck bad state on production is not.
