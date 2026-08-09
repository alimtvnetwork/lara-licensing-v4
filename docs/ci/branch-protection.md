# Branch Protection: Required Checks for `main`

Plan 10 step 47. This document is the single source of truth for which GitHub
Actions Checks must be required before a pull request can merge to `main`,
and which are informational only. Keep this file and the repository's
`Settings -> Branches -> Branch protection rules` page in sync: if a check
name below changes because a workflow is renamed, update both.

## TL;DR

| Check name (as shown in PR "Checks" tab) | Workflow file | Required? |
| --- | --- | --- |
| `backend-static-analysis / phpstan` | `.github/workflows/backend-static-analysis.yml` | Required |
| `frontend-static-analysis / eslint` | `.github/workflows/frontend-static-analysis.yml` | Required |
| `error-contract / error-contract (aggregate)` | `.github/workflows/error-contract.yml` | Required |
| `backend-e2e / pest` | `.github/workflows/backend-e2e.yml` | Required |
| `frontend-e2e / Playwright (chromium)` | `.github/workflows/frontend-e2e.yml` | Required |
| `junit-annotations / annotate` | `.github/workflows/junit-annotations.yml` | Not required (informational annotations) |
| `coverage-report / consolidate` | `.github/workflows/coverage-report.yml` | Not required (post-merge summary) |
| `nightly-e2e / Playwright (firefox/webkit)` | `.github/workflows/nightly-e2e.yml` | Not required (scheduled, not PR-triggered) |
| `release-smoke / Release smoke (chromium)` | `.github/workflows/release-smoke.yml` | Not required for PR merge (gates `v*` tags) |

The five required checks form the PR gate. The rest are informational, run
out-of-band (schedule, workflow_run, tag push), or serve a different lifecycle
stage and must NOT be added as required checks. Adding a `workflow_run`-triggered
check to the required list will block every PR forever because those checks do
not appear on the PR head SHA.

## Recommended branch protection settings

Configure `main` under `Settings -> Branches -> Branch protection rules`:

- Require a pull request before merging: ON.
  - Require approvals: 1 (raise to 2 once the team is >3 people).
  - Dismiss stale pull request approvals when new commits are pushed: ON.
- Require status checks to pass before merging: ON.
  - Require branches to be up to date before merging: ON.
  - Required checks (exact names from the table above):
    - `backend-static-analysis / phpstan`
    - `frontend-static-analysis / eslint`
    - `error-contract / error-contract (aggregate)`
    - `backend-e2e / pest`
    - `frontend-e2e / Playwright (chromium)`
- Require conversation resolution before merging: ON.
- Require linear history: ON (rebase or squash only, no merge commits).
- Do not allow bypassing the above settings: ON, including for admins.
- Restrict who can push to matching branches: ON, limit to release maintainers.
- Allow force pushes: OFF.
- Allow deletions: OFF.

## Why these five, and only these five

Root invariant: a required check must (a) run on the PR's head commit, (b)
finish in bounded time, and (c) fail loudly when a real regression lands.
Applied to each workflow:

1. `backend-static-analysis / phpstan` (required). PHPStan surfaces type
   drift in `backend/` before Pest even starts. Fast (typically under 2 min).
   Skipping it means a broken generic or missing return type reaches Pest as
   a runtime fatal, wasting the Pest slot.
2. `frontend-static-analysis / eslint` (required). ESLint catches unused
   imports, hook misuse, and the OKLCH token linter violations before the
   SPA build. Under 2 min. Skipping it lets a broken hook dependency array
   ship, then Playwright fails at a component boundary with a stack trace
   pointing at React internals rather than the real cause.
3. `error-contract / error-contract (aggregate)` (required). Plan 11 step 41
   aggregates the FE + BE error-contract gates (closed-set parity, ESLint
   raw-fetch + raw-throw bans, envelope + laraFetch Vitest, plus Pest
   `ErrorContractArchitectureTest`, `RawExceptionBanArchitectureTest`, and
   `EnvelopeShapeMatrixTest`) into one aggregate job. The two child jobs
   (`frontend-error-contract`, `backend-error-contract`) run in parallel and
   the aggregate `error-contract` job depends on both, so requiring the
   aggregate guarantees both halves ran and passed on the PR head SHA. Under
   4 min. Skipping it lets closed-set drift, raw `fetch`, raw `throw new
   Error`, or an envelope-shape regression merge silently, breaking the
   FE/BE error correlation shipped in v0.409.0..v0.437.0.
4. `backend-e2e / pest` (required). The Pest feature suite is the contract
   the SPA depends on. Runs in-memory SQLite so it stays under 5 min. Skipping
   it means a controller regression only surfaces in Playwright, which is 5x
   slower to diagnose.
4. `frontend-e2e / Playwright (chromium)` (required). The 13 specs from
   Plan 10 steps 32-40 cover login, admin dashboard, license CRUD with
   If-Match, quota approval with `ResellerSlug` binding, impersonation
   lifecycle, portal serial + updates, font baseline, and axe a11y. Chromium
   only keeps PR time under ~10 min. Skipping it means user-visible flows
   regress silently between merges.

## Why the rest are NOT required

- `junit-annotations / annotate`: triggered by `workflow_run` on
  `backend-e2e` and `frontend-e2e`, so it runs AFTER those complete on a
  separate SHA. GitHub cannot resolve a `workflow_run` check against a PR
  head, so requiring it deadlocks every merge. Its purpose is inline PR
  annotations, not gating.
- `coverage-report / consolidate`: same `workflow_run` constraint. It
  produces the `$GITHUB_STEP_SUMMARY` coverage table and the shields.io
  badge JSON consumed by the README badges (Plan 10 step 49). Coverage is
  a trend signal, not a merge gate: enforcing a threshold as a required
  check punishes refactors that legitimately shrink coverage denominators.
- `nightly-e2e / Playwright (firefox/webkit)`: scheduled (`cron: 0 5 * * *`)
  plus manual dispatch. Never runs on PR events. It catches gecko/webkit
  regressions the next morning, filed as issues.
- `release-smoke / Release smoke (chromium)`: triggered only by `push:
  tags: v*`. Its job is to gate release promotion, not PR merge; requiring
  it on `main` would block every PR because tags do not exist at PR time.

## Bypass and emergency procedures

- Do not use "Merge without waiting" or admin bypass. Every required check
  above is designed to run within 15 min end-to-end; if that ceiling is
  breached, fix the workflow instead of bypassing the gate.
- If a required check is red because of infra (GitHub Actions outage,
  Playwright browser download 5xx, composer mirror flake), re-run the failed
  job first. Only after two clean re-runs fail, open an incident issue and
  ask a repo admin to temporarily un-require the affected check with a link
  to this doc and a follow-up ticket to re-require it.

## When to update this document

Update this file in the SAME pull request that:

- Adds a new workflow that should gate merges.
- Renames a workflow, a job, or a matrix leg (the Check name changes).
- Changes a workflow's trigger in a way that flips required/informational
  (e.g., moving `frontend-e2e` from `pull_request` to `workflow_run`).
- Retires a workflow.

Then update `Settings -> Branches -> Branch protection rules` immediately
after merge. The two must never drift.

## Related

- `.github/workflows/*` for the actual workflow definitions.
- `docs/testing/test-data.md` for env vars, seeders, and the spec-to-data
  matrix consumed by the required Playwright check.
- `CHANGELOG.md` and `RELEASE-NOTES.md` for the historical rollout of the
  six workflows (v0.389.0 through v0.394.0).
