---
name: Memory Protocol
description: Mandatory rules for end-of-session memory enforcement
type: constraint
---

## RULE 0, capture everything or the session is lost

The next AI session has full amnesia. If you did it and did not write it, it did not happen. If it is pending and you did not record it, it is dead. Write for a stranger with zero context. Never truncate history, never overwrite blindly, never leave orphans.

## Hard rules (non-negotiable, auto-reject on violation)

1. No files at the `.lovable/memory/` root. Every memory file lives under a topic folder: `.lovable/memory/<topic>/XX-<slug>.md`.

2. Path is `.lovable/memory/`, never `.lovable/memories/`. Path is `.lovable/plans/`, never `plan/`. Path is `.lovable/ambiguous-questions/`, never `ambiguity/`.

3. Read before write. Every index, plan, suggestions, strictly-avoid, and what-to-read file is READ in full before it is touched. Unrelated entries stay intact.

4. Never delete history. Completed items move to a `## Completed` section or to `plans/completed/`. Solved issues move to `solved-issues/`. Nothing is erased.

5. Same-operation index update. Creating or moving a file ALWAYS updates the matching index in the same turn (`memory/index.md`, `plans/index.md`, `cicd-index.md`, `prompts/index.md`, `suggestions/index.md`).

6. Filenames are lowercase-hyphenated with a 2-digit numeric prefix: `01-auth-flow.md`. `XX` is the next free sequence within its folder.

7. Plans and suggestions single-file trackers stay single files: `.lovable/plan.md` (or `.lovable/plans/index.md` for the roll-up), `.lovable/suggestions.md`. Per-suggestion verbatim captures live under `.lovable/suggestions/XX-<slug>.md`.

8. Ambiguity moves, never copies. Answered file goes from `01-new-ambiguity/` to `02-ambiguity-resolved/` with a `## Resolution` block appended and `Status: resolved` flipped in the same move.

9. Root `README.md` and `.lovable/what-to-read.md` stay in sync. Same file list, same order, no drift. Every write-memory run updates both.

10. Nothing executes this turn beyond file writes and `mv`. No code changes, no installs, no migrations.
