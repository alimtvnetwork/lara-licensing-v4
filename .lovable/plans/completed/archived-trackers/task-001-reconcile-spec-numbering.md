---
id: task-001
title: Reconcile spec/ numbering with what-to-read.md
status: done
created: 2026-07-15
owner: unassigned
plan-ref: M2
---

## Context

`.lovable/what-to-read.md` references `spec/12-consolidated-guidelines/` but the directory on disk is `spec/17-consolidated-guidelines/`. Similar drift may exist across other numbered spec folders. Onboarding readers hit dead links.

## Acceptance

- Every path in `.lovable/what-to-read.md` resolves to a real file or directory.
- `spec/00-overview.md` matches the actual `spec/` layout.
- Numbering conflicts are recorded in `.lovable/memory/decisions/` with the chosen scheme.

## Notes

Blocked until product spec capture (M3) is not required; can proceed independently.

Completed by aligning `spec/00-overview.md` with disk and recording the canonical scheme in `.lovable/memory/decisions/spec-numbering-scheme.md`.
