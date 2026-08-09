---
id: issue-001
title: spec/ directory numbering drift vs what-to-read.md
status: resolved
severity: medium
created: 2026-07-15
reporter: onboarding-audit
---

## Symptom

`.lovable/what-to-read.md` cites `spec/12-consolidated-guidelines/`, but the on-disk directory is `spec/17-consolidated-guidelines/`. Readers hit broken paths during orientation.

## Root Cause

Indexes treated display numbers as independent labels, so a renumbering pass could change references without preserving the on-disk folder prefixes.

## Fix

Tracked by `.lovable/pending-tasks/task-001-reconcile-spec-numbering.md`.

The canonical numbering rule is recorded in `.lovable/memory/decisions/spec-numbering-scheme.md`.

## Notes

Non-blocking for other onboarding items; readers skip missing paths silently per the reading-list rule, but the reference is still incorrect and should be corrected at the source.
