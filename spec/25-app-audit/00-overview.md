# 25. Application Audit (v2)

Version: 2.0.0
Updated: 2026-07-19
Project version at audit time: v0.398.0
Status: Active. Supersedes the sealed v1 audit which was deleted on 2026-07-19 because its 100/Band-A verdict no longer reflected reality after Plan 05/06/07/09 landed only partially.

## Why this rewrite

The previous audit graded `spec/21-app/` in isolation and returned 100/A+. That was correct for spec self-consistency but MASSIVELY overstated whole-product readiness. This v2 audit grades the full delivery pipeline end to end: spec, backend implementation, frontend implementation, database + shards, CI/CD, deploy, and operational runbook. The goal is a single honest verdict the human owner can rely on before pressing Publish.

## Files in this folder

- `00-overview.md` (this file): scope, method, index.
- `01-verdict-honest.md`: per-axis confidence scores, weighted total, band, and the one-line "should I publish?" answer.
- `02-gap-catalog.md`: every gap preventing a 10/10, grouped by axis, each with severity, owner, and the step range in the 300-step plan that closes it.
- `03-plan-300-steps.md`: the 300 concrete steps to reach 100/100. Sequenced, each step is a single deliverable a blind AI can execute.

## Method

Ten axes, weighted:

| Axis | Weight | What it measures |
|---|---|---|
| A. Spec fidelity | 8% | `spec/21-app` internally consistent, self-referential, no drift |
| B. Backend implementation | 15% | Laravel controllers/models/policies match spec 21 |
| C. Frontend implementation | 12% | TanStack routes + lara-* transport match spec 21 + spec 24 |
| D. Database + shards | 10% | Root + shard migrations, RLS/grants, seeders, idempotency |
| E. Auth + RBAC + quota + tier + env + features | 12% | Plan 05 layers A-G actually enforced at runtime |
| F. Observability + audit | 6% | RequestId, AuditWriter, structured logs, retention |
| G. Test coverage | 10% | Pest + Vitest + Playwright, AC parity, migrations idempotent |
| H. CI/CD | 6% | 9 workflows green, branch protection, release gate |
| I. Deploy + release | 12% | cPanel publish path proven end-to-end against a real host |
| J. Runbook + ops | 9% | Bootstrap, DR, secret rotation, SMTP, monitoring documented and rehearsed |

Score per axis is 0-100 based on evidence found on disk today. Overall = weighted mean. Band: A+ >=95, A 85-94, B 70-84, C 50-69, F <50.

## Cross-references

- Verdict: `01-verdict-honest.md`
- Gaps: `02-gap-catalog.md`
- Plan: `03-plan-300-steps.md`
- Prior plans still open: `.lovable/plans/pending/05-*.md`, `06-*.md`, `07-*.md`, `09-*.md`
- Deferred from completed Plan 10: composer three-way version sync, `MigrationsAreIdempotentTest`
