# SS-01: Plain-language audit verdict page

Slug: plain-language-verdict
Parent: 02-spec-21-audit-remediation
Status: pending
Created: 2026-07-16

## Goal

Author `spec/25-app-audit/00-verdict-plain.md` so a non-auditor reader can, in under two minutes, learn: the current score, what it means, what the top three residual risks are, and where in `spec/21-app/` those risks live. Link it from `spec/21-app/00-overview.md` and the root `README.md`.

## Shape

1. One-paragraph verdict (score, band, "safe to hand to a blind AI? yes/no/with caveats").
2. Table: capability, score, one-line rationale.
3. Top three residual risks with the file(s) they live in.
4. Pointer to the machine-readable report (`99-consistency-report.md`).

## Done when

- File exists with the four sections above.
- `spec/21-app/00-overview.md` has a "Verdict" link in the header.
- `README.md` has a one-line link under the project status.
- No em dashes, no SEO wording, no marketing tone.
