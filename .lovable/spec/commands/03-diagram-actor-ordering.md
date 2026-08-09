# Command 03: Diagram actor ordering convention

Created: 2026-07-17
Scope: every sequence diagram under `spec/**/*.mmd` that depicts client to server flows.

## Rule
Order participants left to right by "distance from the primary end-user consumer":
1. End-user App (far left, primary runtime consumer)
2. Reseller UI
3. Admin UI
4. Lara API
5. Split-DB
6. AuditLog / side systems (far right)

## When it applies
- Any new sequence diagram in `spec/`.
- Any edit to an existing sequence diagram touches participant order: reorder to match this rule in the same edit.

## Companion JSON
Every licensing / auth / update sequence diagram MUST ship a sibling `<name>.json` describing actors, messages, alt branches, and notes, so tooling can consume the flow without parsing Mermaid. Filename mirrors the `.mmd` (e.g. `licensing-flow.mmd` -> `licensing-flow.json`).
