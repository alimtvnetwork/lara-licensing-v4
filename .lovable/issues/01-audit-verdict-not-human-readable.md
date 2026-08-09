# Issue: Audit final verdict not human-readable

Slug: audit-verdict-not-human-readable
Status: open
Created: 2026-07-16

## Symptom

User cannot understand the overall consistency / handoff verdict from `spec/25-app-audit/`. The sealed report (`00-final-report.md`) reports "100.0, Band A" but the reasoning, per-capability numbers, and remaining risks are not surfaced in a plain-language summary a non-auditor can read.

## Expected

A short, plain-language verdict page: what score, what it means, what is still risky, and what is being done about it. Linked from `spec/21-app/00-overview.md` and `README.md`.

## Actual

Verdict is spread across `00-final-report.md`, `99-consistency-report.md`, `01-scoring-rubric.md`, `12-per-file-findings.md`. No single human-readable summary.

## Related files

- `spec/25-app-audit/00-final-report.md`
- `spec/25-app-audit/99-consistency-report.md`
- `spec/25-app-audit/01-scoring-rubric.md`
