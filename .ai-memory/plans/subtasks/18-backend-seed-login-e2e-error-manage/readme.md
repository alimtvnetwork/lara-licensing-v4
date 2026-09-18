# Plan 18 subtasks index

Parent: 18-backend-seed-login-e2e-error-manage
Convention: flip `Status:` in place when a subtask completes (do not move files).
Locked: 2026-07-23 (Step 19). Amendments append-only in Section 3.

## Subtasks

Each row lists the Phase A artifacts (Steps 1-18) it depends on and the implementation step range it owns.

| ID    | Title                                             | Depends on (Phase A artifacts)                                       | Owns steps  | Status  |
| ----- | ------------------------------------------------- | -------------------------------------------------------------------- | ----------- | ------- |
| SS-01 | Backend endpoint parity audit vs `operations.ts`  | 01, 02, 03, 04, 05                                                   | 21-40       | pending |
| SS-02 | Seeder coverage matrix (default / empty / error)  | 06, 07                                                               | 30-40       | pending |
| SS-03 | Demo-login credentials + FE seed-mode shortcut    | 08, 09                                                               | 41-60       | pending |
| SS-04 | Preview-fixture handlers for admin KPIs and lists | 10, 06 (row counts), 03 (parity), 17 (R3 gate)                       | 61-80       | pending |
| SS-05 | Error-manage (LaraException + envelope + ErrorId) | 11, 17 (R5 gate)                                                     | 81-110      | completed |
| SS-06 | Notification center component                     | 12, 11 (envelope fields), 17 (R6 gate)                               | 111-120     | completed |
| SS-07 | Backend Pest feature tests                        | 13, 06 (row counts), 11 (envelope), 08 (identities)                  | 121-150     | completed |
| SS-08 | Playwright e2e across three seeds                 | 14, 07 (profiles), 09 (panel), 12 (notif), 17 (R1/R3/R6)             | 151-175     | completed |
| SS-09 | Linters + CI/CD workflows                         | 15, 16, 17 (R2/R4/R7), 18 (AC mapping)                               | 176-190     | completed |
| SS-10 | Coverage matrix + release ceremony                | 18 (AC mapping), 20 (plan freeze), all prior SS closed               | 191-200     | completed |

Blocking rule: an SS row may not start execution until every artifact listed in its "Depends on" column exists and is marked `locked` in the plan-18 doc header.

## 2. Cross-cutting notes

- SS-02 and SS-04 share Steps 30-40 (seeders) as SS-02 owner, then Steps 61-80 (fixtures) as SS-04 owner. The seeder row-count constants are the single source of truth: fixtures MUST import from `demo-metrics-constants.ts` (see Step 62).
- SS-07 and SS-08 both depend on Step 08 identities via `DemoIdentities.php` and `src/lib/demo-identities.ts`. Neither test surface may inline demo credentials.
- SS-09 owns all linter waivers introduced by Plan 18; other SS rows may not edit `linter-scripts/*.waivers.txt` directly.
- SS-10 executes Step 200 release ceremony; blocked until every prior SS status flips to `completed`.

## 3. Amendments

(none)
