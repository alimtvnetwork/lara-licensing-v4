Queue Plan 18 Phase A finish: Steps 17-20 (planning artifacts only, no runtime code, no release).

Each step produces one markdown file. Deliverables:

- **Step 17** - `docs/backend/plan-18/17-risk-and-rollback.md`
  Rank risks (auth-shape drift, seeder perf, admin-metrics divergence, preview leaking into prod), assign each mitigation to specific step numbers in 21-200, define rollback gates per phase.

- **Step 18** - `docs/backend/plan-18/18-acceptance-mapping.md`
  Map spec-18 AC-01..AC-10 to the exact steps that satisfy each. Flag any AC with fewer than three covering steps as a planning gap and re-budget before Step 20 closes.

- **Step 19** - update `.lovable/plans/subtasks/18-backend-seed-login-e2e-error-manage/README.md`
  For each SS-01..SS-10 row, list the Step 1-18 artifact(s) it depends on and the exact step range it owns. Pure edit to the existing subtask index.

- **Step 20** - `docs/backend/plan-18/20-plan-freeze.md`
  Summarize every artifact from Steps 1-19, print the step-range ownership table for 21-200, and a checklist confirming no later step is missing a planning source. This closes Phase A; Steps 21+ then execute as scheduled.

Execution order: run 17, 18, 19, 20 in sequence (19 depends on 17-18, 20 depends on all). Each is one file, docs-only, so per-step cost stays small. No version bump, no changelog, no release: Plan 18 release ceremony fires only at Step 200.

After Step 20, remaining Plan 18 work (Steps 21-200) is runtime code and will be picked up under a separate approval.