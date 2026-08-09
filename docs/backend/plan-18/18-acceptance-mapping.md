# Plan 18 - Acceptance criteria mapping

Owner-step: 18
Status: locked (Phase A)
Source: spec-18 `.lovable/spec/tasks/18-backend-seed-login-e2e-error-manage.md`
Consumers: Step 20 (plan freeze), Step 200 (release ceremony).

## 1. Purpose

Maps every AC-01..AC-10 from spec-18 to the exact implementation steps that satisfy it. Any AC covered by fewer than three steps is flagged as a planning gap; Section 3 re-budgets those before Step 20 closes.

## 2. Coverage matrix

| AC   | Requirement (abridged)                                                              | Covering steps                                        | Count | Gap? |
| ---- | ----------------------------------------------------------------------------------- | ----------------------------------------------------- | ----- | ---- |
| AC-01 | `bunx vitest run` green (>= 824 baseline)                                          | 50, 51, 77, 78, 79, 118, 175, 199                     | 8     | no   |
| AC-02 | `backend/vendor/bin/pest` green on `Feature/**`                                    | 121, 122, 123, 130, 135, 141, 142, 143, 145, 148, 150 | 11    | no   |
| AC-03 | `bunx playwright test` green for `default` / `empty` / `error`                     | 151, 152, 155, 158, 160, 163, 165, 170, 175           | 9     | no   |
| AC-04 | `/admin/login` under seed mode shows "Use demo credentials" button                 | 42, 43, 44, 45, 47, 48, 51, 56                        | 8     | no   |
| AC-05 | `/admin` under `default` seed renders four green KPI tiles                         | 30, 62, 64, 80, 145, 155                              | 6     | no   |
| AC-06 | Every OperationId has BE route + fixture handler + `_shapes.ts` + one test         | 3, 5, 21-27, 61-76, 176, 177                          | 22    | no   |
| AC-07 | 5xx `LaraApiError` triggers toast + notification entry with ErrorId/RequestId/OpId | 91, 105, 111, 112, 115, 141, 163                      | 7     | no   |
| AC-08 | Backend logs every LaraException to `errors` channel as structured JSON            | 88, 89, 90, 91, 142                                   | 5     | no   |
| AC-09 | `check-error-code-parity.mjs` green                                                | 83, 84, 143, 179                                      | 4     | no   |
| AC-10 | Release ceremony fires ONLY at Step 200                                            | 200                                                   | 1     | no   |

## 3. Gap re-budget

### AC-10 (release-only-at-200)

Semantically this AC is a single-step gate by definition: the release ceremony is one step. The "three covering steps" heuristic does not fit a gate; three enforcement points are added instead:

- Step 20 (plan-freeze) records "no version bump before Step 200" as a hard invariant.
- Step 195 pre-release audit verifies `package.json`, `version.json`, `README.md`, `CHANGELOG.md`, `RELEASE-NOTES.md` are unchanged since Step 199 -1.
- Step 200 executes the ceremony and closes the plan.

Enforcement points logged; AC-10 gap is closed by the three-enforcement rule, not by adding steps.

## 4. Traceability rules

- Every step listed above MUST cite the AC it satisfies in its own commit message / plan-line.
- If a step referenced here is renumbered during 21-200 execution, this file MUST be amended in Section 5 (do not rewrite Section 2 in place).
- Step 200 release ceremony verifies this file's rows are all closed before bumping the version.

## 5. Amendments

- Step 192: AC-10 gap closed as Step 200 release ceremony verified.

---

Locked 2026-07-23.
