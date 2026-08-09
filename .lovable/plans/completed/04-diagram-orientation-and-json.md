# Diagram Orientation Fix and JSON Flow Artifact

Slug: diagram-orientation-and-json
Steps: 20
Status: completed
Created: 2026-07-17
Closed: 2026-07-17 (all 20 steps landed across v0.107.0, v0.108.0, v0.109.0)

## Context
`spec/21-app/diagrams/licensing-flow.mmd` orders actors Admin, Reseller, EndUser, API. The end-user runtime path is the primary consumer of the licensing API and must anchor the diagram on the far left. This plan reorders that diagram, sweeps sibling diagrams under `spec/23-app-db/`, adds a searchable JSON companion (`licensing-flow.json`), and codifies the ordering convention so future diagrams do not regress.

Captured inputs:
- Command: `.lovable/spec/commands/03-diagram-actor-ordering.md`
- Issue: `.lovable/issues/02-diagram-actor-orientation.md`

Prior pending plans still open: `03-runtime-alignment.md` (runtime alignment M5). Not merged here; tracked separately.

## Steps

1. Inventory every `.mmd` under `spec/` and record current participant order in a scratch note inside the subtask folder.
2. Confirm the ordering rule against `.lovable/spec/commands/03-diagram-actor-ordering.md` and cite it in each edited diagram's header comment.
3. Rewrite `spec/21-app/diagrams/licensing-flow.mmd` with participants ordered EndUser, Reseller, Admin, API, DB, Audit; keep message content identical, only reorder and adjust arrow directions.
4. Renumber the `Note over` scoping in `licensing-flow.mmd` so each section still spans the correct participant range after reorder.
5. Verify the reordered `licensing-flow.mmd` parses cleanly (no semicolons, no reserved punctuation) by mentally walking each line against Mermaid grammar.
6. Audit `spec/23-app-db/02-jwt-flow.mmd` for the same actor-ordering rule; reorder if the end-user or app-user client is not leftmost. See ./subtasks/04-diagram-orientation-and-json/SS-01-sibling-diagram-sweep.md
7. Audit `spec/23-app-db/03-oauth-client-credentials.mmd` for actor order; reorder if needed. See same subtask.
8. Audit `spec/23-app-db/09-verify-sequence.mmd` for actor order; the verify path is end-user driven so EndUser must be leftmost.
9. Audit `spec/23-app-db/01-erd.mmd` (ER diagram, not a sequence diagram) and record that the rule does not apply; note it in the subtask log so future audits do not re-open.
10. Audit `spec/16-generic-release/images/*.mmd` and any other sequence diagrams surfaced in step 1; apply the rule or record N/A.
11. Author `spec/21-app/diagrams/licensing-flow.json` capturing actors, messages, alt branches, and notes in a shape downstream tooling can consume without parsing Mermaid. See ./subtasks/04-diagram-orientation-and-json/SS-02-licensing-flow-json-schema.md
12. Cross-reference `licensing-flow.json` from `spec/21-app/diagrams/licensing-flow.mmd` header comment so a searcher landing on the .mmd finds the JSON.
13. Add `licensing-flow.json` to `spec/21-app/99-consistency-report.md` as a new normative artifact row.
14. Add Check 20 to `spec/21-app/99-consistency-report.md` asserting actor-ordering compliance for every sequence diagram under `spec/21-app/diagrams/` and `spec/23-app-db/*.mmd`.
15. Extend `linter-scripts/check-spec-folder-refs.py` (or a new small lint) to fail when a sequence diagram's first participant is not EndUser/AppUser when any of those actors are present. See ./subtasks/04-diagram-orientation-and-json/SS-03-mmd-order-linter.md
16. Update `spec/21-app/97-acceptance-criteria.md` with `AC-DG-001` (actor ordering) and `AC-DG-002` (JSON companion exists for licensing flow).
17. Update the release notes / changelog entry for the current in-flight version to record the orientation fix and the JSON companion artifact.
18. Update `.lovable/overview.md` (or the closest folder map file) so contributors know the JSON companion is the searchable artifact for licensing communication.
19. Run `bunx vitest run`, `bunx tsgo --noEmit`, and the linter suite; capture pass evidence into the changelog next to the version bump.
20. Move this plan file to `.lovable/plans/completed/04-diagram-orientation-and-json.md` and flip `Status:` to `completed` when steps 1-19 land and verification is green.

## Verification
- Rendered `spec/21-app/diagrams/licensing-flow.mmd` shows EndUser as the leftmost participant.
- `spec/21-app/diagrams/licensing-flow.json` exists, validates against the shape described in SS-02, and is referenced from the .mmd header.
- `spec/21-app/99-consistency-report.md` Check 20 passes.
- New mmd-order linter (SS-03) exits 0 on the current tree.
- Vitest and tsgo remain green.

## Appended from prior pending tasks
- `03-runtime-alignment.md` remains open, tracked independently; not merged into this plan.
