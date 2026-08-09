# Prompts Index

**Version:** 0.10.0
**Last updated:** 2026-07-15

Canonical registry of standing prompts stored under `.lovable/prompts/`.

## Standing prompts

| File | Purpose |
|---|---|
| `01-read-memory.md` | Onboarding ritual: what to read on session start, in what order, and how to summarize. Updated to v1.7. |
| `01-write-memory.md` | How to update memory files (naming, versioning, index cross-links). Updated to v1.1. |
| `02-plan.md` | Planning ritual: 50-step plan requirement with strict spec-first lifecycle. Updated to v4.1. |

## Rules

- Only canonical, reusable prompts live here. One file per prompt.
- **Do not create per-invocation archive/mirror files** for dropdown prompts (next N, plan N, proofread, etc.). No `xx-next-task.md`, no `NN-<slug>.md` snapshots. If the canonical body of a standing prompt changes, edit its file in place and bump the version.
- Numeric prefix (`NN-`) is stable once assigned. Do not renumber.
- When adding a prompt, add a row to the table above and reference it from `.lovable/what-to-read.md` if it belongs in the read-order.

## Why the archive ban

User standing rule (see `.lovable/user-preferences.md` and `.lovable/strictly-avoid.md` SA-024). Per-invocation mirrors bloat the directory, duplicate transient text, and were explicitly rejected.
