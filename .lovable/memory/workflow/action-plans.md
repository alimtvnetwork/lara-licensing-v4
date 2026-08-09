# AI Action Plans Workflow

**Rule:** Always save AI implementation plans and conversation outcomes to the repository memory so that historical context is never lost.

## Procedure
Whenever you generate a significant `implementation_plan.md` or execute a complex refactoring loop:
1. Save a copy of the plan into `.lovable/memory/history/plans/`.
2. Name the file with a descriptive slug and date, for example: `YYYY-MM-DD-feature-name-plan.md`.
3. Include the goals, the steps taken, and any decisions made during the execution so future AI agents understand *why* and *how* the codebase was modified.
