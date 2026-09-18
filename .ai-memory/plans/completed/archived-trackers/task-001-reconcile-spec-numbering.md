---
id: task-001
title: Reconcile spec/ numbering with what-to-read.md
status: done
created: 2026-07-15
owner: unassigned
plan-ref: M2
---

## Context

`.ai-memory/what-to-read.md` references `02-spec/12-consolidated-guidelines/` but the directory on disk is `02-spec/17-consolidated-guidelines/`. Similar drift may exist across other numbered spec folders. Onboarding readers hit dead links.

## Acceptance

- Every path in `.ai-memory/what-to-read.md` resolves to a real file or directory.
- `02-spec/00-overview.md` matches the actual `02-spec/` layout.
- Numbering conflicts are recorded in `.ai-memory/memory/decisions/` with the chosen scheme.

## Notes

Blocked until product spec capture (M3) is not required; can proceed independently.

Completed by aligning `02-spec/00-overview.md` with disk and recording the canonical scheme in `.ai-memory/memory/decisions/spec-numbering-scheme.md`.
