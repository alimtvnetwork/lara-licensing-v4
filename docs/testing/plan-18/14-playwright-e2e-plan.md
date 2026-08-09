# Plan 18 · Step 14 · Playwright E2E Plan (Steps 151-175)

Status: draft (produced by Plan 18 Step 14).

Depends on: Step 8 (demo login identities), Step 9 (`<DemoLoginPanel />`),
Step 10 (preview fixtures per seed profile), Step 11 (error envelope
`X-Error-Id` + `Attributes.Category`), Step 12 (notification center),
Step 13 (Pest coverage — what E2E is allowed to skip).

## 1. Ground truth

Playwright specs live under `tests/e2e/specs/`. Config: `playwright.config.ts`
selects seed via `PLAYWRIGHT_SEED_PROFILE` env; helpers in
`tests/e2e/helpers/` provide `loginAs(role)` and `useSeedProfile(profile)`
wrappers around the preview transport. Baselines under
`tests/e2e/baselines/`, screenshots under `tests/e2e/screenshots/`.

Existing specs relevant to Plan 18 (do NOT duplicate; extend where noted):

- `auth-login.spec.ts` — extend for demo-login panel (Step 152).
- `admin-dashboard.spec.ts` — extend for KPI-green assertion under all
  three profiles (Step 156).
- `admin-quota-approval.spec.ts` — extend for approve/deny split verbs
  from Step 4 gap-groups (Step 158).
- `admin-impersonation.spec.ts` — extend for notification center
  emitting an impersonation event (Step 162).
- `preview-seed-matrix.spec.ts` — extend to add `error` profile
  assertions (Step 165).
- `route-error-correlation.spec.ts` — extend for `X-Error-Id` header
  copy action (Step 163).

Delta: Plan 18 adds 15 net-new specs and extends 6.

## 2. Spec layout

New files under `tests/e2e/specs/`:

```
plan-18/
  demo-login-panel.spec.ts             (Step 151)
  demo-login-hotkey.spec.ts            (Step 153)
  demo-login-prod-gated.spec.ts        (Step 154)
  admin-overview-green.spec.ts         (Step 156)
  admin-overview-empty.spec.ts         (Step 157)
  admin-overview-error.spec.ts         (Step 159)
  admin-metrics-kpis.spec.ts           (Step 160)
  admin-features-list.spec.ts          (Step 161)
  admin-user-destroy-last-admin.spec.ts (Step 164)
  notification-center-bell.spec.ts     (Step 166)
  notification-center-drawer.spec.ts   (Step 167)
  notification-center-hotkey.spec.ts   (Step 168)
  notification-center-copy-ids.spec.ts (Step 169)
  error-envelope-x-error-id.spec.ts    (Step 170)
  error-category-toast.spec.ts         (Step 171)
```

Extended in place:

```
auth-login.spec.ts                     (Step 152)
admin-quota-approval.spec.ts           (Step 158)
admin-impersonation.spec.ts            (Step 162)
route-error-correlation.spec.ts        (Step 163)
preview-seed-matrix.spec.ts            (Step 165)
admin-dashboard.spec.ts                (Step 156 shared setup)
```

Cross-cutting artifacts:

- `tests/e2e/helpers/demo-login.ts` (Step 155) — `loginAsDemo(role)`.
- `tests/e2e/helpers/notification-center.ts` (Step 172) —
  `openBell()`, `readEntries()`, `copyCorrelationIds()`.
- `tests/e2e/baselines/plan-18/` — 6 screenshot baselines (Step 173).
- `tests/e2e/fixtures/plan-18-seed-guards.ts` (Step 174) — asserts
  `SEED_PROFILE` env matches spec expectation before test body runs
  so a misconfigured runner fails loudly instead of silently green.
- `tests/e2e/README.md` (Step 175 update) — table mapping each Plan 18
  spec to its target seed profile.

## 3. Per-spec assertion checklist

### 3.1 Demo login (Steps 151-155)

- `demo-login-panel.spec.ts` :: seed `default`
  1. Preview boot, navigate `/admin/login`.
  2. `<DemoLoginPanel />` visible with 3 role chips (SuperAdmin,
     Reseller, Portal) matching `src/lib/demo-identities.ts`.
  3. Click SuperAdmin chip populates email+password fields; submit
     lands on `/admin/overview` with authenticated Me payload.
  4. Screenshot baseline `plan-18/demo-login-panel.png`.

- Extend `auth-login.spec.ts` (Step 152)
  1. Non-preview mode: `<DemoLoginPanel />` absent from DOM (query
     by `data-testid="demo-login-panel"`), `NODE_ENV=production`
     build path.

- `demo-login-hotkey.spec.ts` :: `default`
  1. `Shift+D Shift+D` on `/admin/login` reveals the panel even when
     collapsed. ARIA focus lands on the first chip.

- `demo-login-prod-gated.spec.ts` :: `default`
  1. Simulate prod bundle via `import.meta.env.PROD=true` (helper
     already used by `preview-boot-invariant.test.ts`).
  2. Panel unmounts; `DEMO_LOGIN_PANEL_MARKER` string absent from
     `document.documentElement.outerHTML`.

- `helpers/demo-login.ts` (Step 155) — pure helper, no spec body.

### 3.2 Admin overview across seeds (Steps 156-161)

- `admin-overview-green.spec.ts` :: `default`
  1. After demo-login as SuperAdmin, `/admin/overview` renders
     without any red state card.
  2. KPI tiles show non-zero values matching Step 6 row targets
     (>=8 resellers, >=120 licences, >=24 quota requests).
  3. Screenshot baseline `plan-18/overview-green.png`.

- `admin-overview-empty.spec.ts` :: `empty`
  1. Same page renders empty states (not error) for every tile.
  2. Zero red banners.
  3. Copy strings match `src/copy/empty-states.ts`.

- `admin-overview-error.spec.ts` :: `error`
  1. Deliberate error rows visible on Backups tab (stalled) and
     Licences tab (expired) per Step 7.
  2. Toasts fired via `useAppToast` bridged into notification center.

- `admin-metrics-kpis.spec.ts` :: `default`
  1. Directly hit `/admin/metrics` route; KPI tiles + trend
     sparkline render.
  2. Preview intercept confirms operation `admin.metrics.kpis` fired
     (via `preview-transport.ts` event log helper).

- `admin-features-list.spec.ts` :: `default`
  1. `/admin/features` list renders with column headers per Zod
     shape.
  2. Search filter narrows results.

- Extend `admin-quota-approval.spec.ts` (Step 158)
  1. Approve action hits `admin.quotas.approve`, deny hits
     `admin.quotas.deny` (split verbs from Step 4).
  2. Row disappears on optimistic mutation, reappears on rollback
     when preview seeds error scenario (`?scenario=quotas-approve-500`).

### 3.3 Notification center (Steps 166-169, 172)

- `notification-center-bell.spec.ts` :: `default`
  1. Bell icon present in `AppShell.tsx` topbar (`data-testid=
     "notification-bell"`).
  2. On new toast fired via error injection, badge count increments
     with `aria-live="polite"` announcement.

- `notification-center-drawer.spec.ts` :: `default`
  1. Click bell opens drawer with ring-buffer entries in FIFO order.
  2. 51st entry evicts the oldest (buffer cap from Step 12).

- `notification-center-hotkey.spec.ts` :: `default`
  1. `Alt+N` opens drawer.
  2. Arrow keys move roving `tabindex`; `Escape` closes and returns
     focus to bell.

- `notification-center-copy-ids.spec.ts` :: `error`
  1. Trigger an error, open drawer, click "Copy correlation IDs".
  2. Clipboard contains `RequestId`, `ErrorId`, `OperationId` in
     the format defined by Step 12.

- Extend `admin-impersonation.spec.ts` (Step 162)
  1. Starting impersonation emits a notification-center entry with
     `Category="Auth"` and the impersonated user id.

### 3.4 Error envelope (Steps 163, 170, 171)

- Extend `route-error-correlation.spec.ts` (Step 163)
  1. Route error state exposes a "Copy correlation IDs" button.
  2. Clipboard payload includes `ErrorId` from the response
     `X-Error-Id` header (fallback to envelope attribute when the
     header is stripped by an intermediate proxy).

- `error-envelope-x-error-id.spec.ts` :: `error`
  1. Preview response for any failing OperationId carries the
     `X-Error-Id` header.
  2. Same value appears in `Attributes.ErrorId`.
  3. 2xx responses do not carry the header (parity with Pest 142).

- `error-category-toast.spec.ts` :: `error`
  1. Toasts route by `Attributes.Category` to the correct tone:
     `Auth` -> destructive, `Validation` -> warning,
     `RateLimit` -> info, `DomainConflict` -> warning,
     `NotFound` -> muted, `Internal` -> destructive.
  2. Registry mapping in `src/hooks/use-app-toast.ts` reflected 1:1.

### 3.5 Guards (Step 164, 174)

- `admin-user-destroy-last-admin.spec.ts` :: `default`
  1. Attempt to delete the last SuperAdmin returns 409 with
     `Attributes.Category="DomainConflict"`.
  2. Confirmation dialog surfaces the friendly copy from
     `src/copy/errors.ts`.

- `fixtures/plan-18-seed-guards.ts` (Step 174) — runtime fixture
  that reads `PLAYWRIGHT_SEED_PROFILE`, compares against the spec's
  declared `test.info().annotations`, and fails fast on mismatch.

### 3.6 Baselines and README (Steps 173, 175)

- `baselines/plan-18/` (Step 173): 6 baselines for
  `demo-login-panel`, `overview-green`, `overview-empty`,
  `overview-error`, `notification-drawer-open`,
  `error-modal-with-x-error-id`.
- `tests/e2e/README.md` (Step 175): append Plan 18 spec-to-seed
  matrix and helper index.

## 4. Step-to-file map (Steps 151-175)

| Step | File | Seed | Op / concern |
|--:|---|---|---|
| 151 | `plan-18/demo-login-panel.spec.ts` | default | panel render |
| 152 | extend `auth-login.spec.ts` | default | prod-gate absence |
| 153 | `plan-18/demo-login-hotkey.spec.ts` | default | hotkey |
| 154 | `plan-18/demo-login-prod-gated.spec.ts` | default | prod build gate |
| 155 | `helpers/demo-login.ts` | n/a | shared helper |
| 156 | `plan-18/admin-overview-green.spec.ts` | default | KPI green |
| 157 | `plan-18/admin-overview-empty.spec.ts` | empty | empty states |
| 158 | extend `admin-quota-approval.spec.ts` | default | approve/deny split |
| 159 | `plan-18/admin-overview-error.spec.ts` | error | error rows |
| 160 | `plan-18/admin-metrics-kpis.spec.ts` | default | metrics op fired |
| 161 | `plan-18/admin-features-list.spec.ts` | default | features list |
| 162 | extend `admin-impersonation.spec.ts` | default | notif entry |
| 163 | extend `route-error-correlation.spec.ts` | error | copy IDs |
| 164 | `plan-18/admin-user-destroy-last-admin.spec.ts` | default | last-admin guard |
| 165 | extend `preview-seed-matrix.spec.ts` | all | error profile row |
| 166 | `plan-18/notification-center-bell.spec.ts` | default | bell badge |
| 167 | `plan-18/notification-center-drawer.spec.ts` | default | drawer + FIFO |
| 168 | `plan-18/notification-center-hotkey.spec.ts` | default | Alt+N |
| 169 | `plan-18/notification-center-copy-ids.spec.ts` | error | copy correlation |
| 170 | `plan-18/error-envelope-x-error-id.spec.ts` | error | header parity |
| 171 | `plan-18/error-category-toast.spec.ts` | error | category routing |
| 172 | `helpers/notification-center.ts` | n/a | shared helper |
| 173 | `baselines/plan-18/*.png` | n/a | 6 baselines |
| 174 | `fixtures/plan-18-seed-guards.ts` | n/a | seed guard |
| 175 | update `tests/e2e/README.md` | n/a | matrix table |

## 5. Determinism and CI notes

- Every spec declares its seed profile via
  `test.info().annotations.push({type:'seed', description:profile})`;
  the Step 174 fixture asserts `PLAYWRIGHT_SEED_PROFILE` matches.
- Screenshot comparisons pinned to viewport `1280x1800` (matches
  existing `admin-dashboard.spec.ts` baseline).
- Clipboard reads use `context.grantPermissions(['clipboard-read',
  'clipboard-write'], { origin: baseURL })`.
- CI matrix in Step 183 will run three shards keyed by
  `PLAYWRIGHT_SEED_PROFILE`.

## 6. Out of scope

- Real backend Playwright runs (still preview-only per
  `.lovable/memory/standards/preview-is-primary-dev-surface.md`).
- Visual regression policy (Step 173 sets the baselines; the pixel
  threshold is Step 179's linter concern).
- Pest coverage of the same paths (Step 13 owns it — no duplication).
