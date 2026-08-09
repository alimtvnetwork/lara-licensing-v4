# Plan 18 · Step 16 · CI/CD Plan (Steps 181-185)

Status: draft (produced by Plan 18 Step 16).

Depends on: Steps 6-7 (seed profiles), 13 (Pest), 14 (Playwright),
15 (linters).

## 1. Ground truth

Workflows under `.github/workflows/`:

- `backend-e2e.yml` (117 lines) — Pest job; no seed profile matrix
  today.
- `frontend-e2e.yml` (226 lines) — Vitest + Playwright; single seed.
- `coding-guidelines.yml` (28 lines) — runs `linter-scripts/run.sh
  --linters-only`.
- `error-contract.yml` — error taxonomy job; Plan 18 extends it in
  Step 184.
- `preview-screenshot-matrix.yml` — Plan 17 baseline; Plan 18 adds
  6 new baselines from Step 173.
- `coverage-matrix.yml`, `coverage-report.yml` — coverage; Step 146
  produces the artifact.
- `nightly-e2e.yml` — cross-browser (Firefox/WebKit); extend to
  include the three seed profiles nightly (Step 185).

None of the current workflows set `SEED_PROFILE` or
`PLAYWRIGHT_SEED_PROFILE`. That is the biggest gap.

## 2. Changes per file (Steps 181-185)

### 2.1 `backend-e2e.yml` (Step 181)

Add a `strategy.matrix.seed_profile: [default, empty, error]` to the
Pest job. Env: `SEED_PROFILE: ${{ matrix.seed_profile }}`. Cache key
includes the profile so runs do not poison each other. `fail-fast:
false` so an `error` profile failure does not mask `default` regressions.

Artifacts: upload `backend/storage/logs/lara-audit-errors-*.ndjson`
from Step 11 sink on failure, one per profile.

### 2.2 `frontend-e2e.yml` (Step 182)

Split the Playwright job into two: `vitest` (unchanged) and
`playwright` (new matrix). Playwright matrix:

```yaml
strategy:
  fail-fast: false
  matrix:
    seed_profile: [default, empty, error]
    shard: [1, 2]
env:
  PLAYWRIGHT_SEED_PROFILE: ${{ matrix.seed_profile }}
```

Shard flag on the Playwright CLI. Artifacts: HTML report + traces
per (profile, shard). 6 total shards, ~4-6 min each.

Vitest job stays single-shard; seed logic is fixture-level.

### 2.3 `coding-guidelines.yml` (Step 183)

Append the 2 new linter script names from Step 15
(`check-endpoint-operation-parity.py`,
`check-error-envelope-shape.py`) to the linter list expected by the
job (or rely on `run.sh` fully — verify `run.sh` picks them up via
the dispatch lines added in Step 15). Either way, this workflow
change is a no-op if `run.sh` already dispatches them; the job just
needs a re-verification step that greps for a "linter added" banner.

Add `linter-scripts/*.waivers.txt` to the file list checked for
self-linting (waiver-format guard from Step 15 §4).

### 2.4 `error-contract.yml` (Step 184)

Extend to assert the Step 11 additions:

1. Every 4xx/5xx response in the Pest suite carries `X-Error-Id`.
2. `Attributes.Category` is one of the 6 enum values.
3. `lara-audit-errors` NDJSON sink writes at least one line per
   failing scenario.

Implementation: this workflow already runs a subset of Pest with the
`--group=error-contract` filter. Add annotations `@group
error-contract` to the new files in Step 13 (`ErrorEnvelopeCategory
Test`, `ErrorIdHeaderTest`, `OperationIdEchoTest`,
`LaraAuditErrorsSinkTest`, `ExceptionHierarchyTest`).

### 2.5 `nightly-e2e.yml` (Step 185)

Cross-product the existing browser matrix (`firefox`, `webkit`) with
the 3 seed profiles = 6 nightly jobs. Upload the 6 baselines from
Step 173 as artifacts. On baseline diff > threshold, the workflow
opens an auto-issue (existing action) tagged `plan-18` +
`visual-regression`.

## 3. Step-to-file map (Steps 181-185)

| Step | File | Change |
|--:|---|---|
| 181 | `.github/workflows/backend-e2e.yml` | seed_profile matrix + NDJSON artifact upload |
| 182 | `.github/workflows/frontend-e2e.yml` | split job, seed×shard matrix |
| 183 | `.github/workflows/coding-guidelines.yml` | waiver self-lint + new linter verification banner |
| 184 | `.github/workflows/error-contract.yml` | assert Step 11 envelope additions |
| 185 | `.github/workflows/nightly-e2e.yml` | seed × browser cross-product + baselines |

## 4. Determinism, cost, and rollback

- `fail-fast: false` on every matrix. A single-profile flake never
  gates the rest.
- Cache keys per profile (`backend-cache-${{ matrix.seed_profile }}`)
  so seeds cannot cross-poison.
- Wall clock estimate: backend +3 min (3× profiles run in parallel);
  frontend +12 min via 6 shards in parallel; nightly +30 min via 6
  browser×seed jobs. Total credit budget stays under the existing
  Plan 17 baseline captured in `docs/testing/seed-mode-baseline.json`.
- Rollback gate: if the seed×shard matrix regresses PR wall-clock by
  more than 30%, Step 17's risk doc allows collapsing back to a
  single-profile `default` run on PRs while nightly retains the full
  matrix.

## 5. Out of scope

- New workflows (none needed; all Plan 18 gates live in existing
  files).
- Release workflow (`release.yml`) — Plan 18 does not change release
  ceremony (that stays Step 11 of the release prompt tree).
- CD to staging (Cloudflare Workers deploy path unchanged).
