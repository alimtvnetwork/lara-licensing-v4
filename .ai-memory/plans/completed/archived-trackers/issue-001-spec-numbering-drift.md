---
id: issue-001
title: spec/ directory numbering drift vs what-to-read.md
status: resolved
severity: medium
created: 2026-07-15
reporter: onboarding-audit
---

## Symptom

`.ai-memory/what-to-read.md` cites `02-spec/12-consolidated-guidelines/`, but the on-disk directory is `02-spec/17-consolidated-guidelines/`. Readers hit broken paths during orientation.

## Root Cause

Indexes treated display numbers as independent labels, so a renumbering pass could change references without preserving the on-disk folder prefixes.

## Fix

Tracked by `.ai-memory/pending-tasks/task-001-reconcile-spec-numbering.md`.

The canonical numbering rule is recorded in `.ai-memory/memory/decisions/spec-numbering-scheme.md`.

## Notes

Non-blocking for other onboarding items; readers skip missing paths silently per the reading-list rule, but the reference is still incorrect and should be corrected at the source.
