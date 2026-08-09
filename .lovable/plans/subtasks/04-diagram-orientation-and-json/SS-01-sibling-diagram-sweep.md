# SS-01: Sibling diagram sweep

Parent: 04-diagram-orientation-and-json
Slug: sibling-diagram-sweep
Status: pending
Created: 2026-07-17

## Scope
Walk every `.mmd` file surfaced in step 1 of the parent plan. For each sequence diagram, record: file path, current participant order (top-to-bottom of the declarations, which renders left-to-right), whether it complies with the ordering rule from `.lovable/spec/commands/03-diagram-actor-ordering.md`, and the minimal reorder needed to comply.

## Deliverable
A checklist appended to this file listing each `.mmd` and its compliance state (COMPLIANT / REORDER / N/A ER-diagram). Reorder edits happen in the parent plan steps 6-10, not here.

## Rule reminder
Order left to right: EndUser, Reseller, Admin, API, DB, Audit / side systems. Non-participants (ER diagrams, flowcharts) are N/A.
