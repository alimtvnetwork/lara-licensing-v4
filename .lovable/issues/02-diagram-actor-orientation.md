# Issue 02: Diagram actor orientation is wrong

Status: open
Created: 2026-07-17

## Symptom
`spec/21-app/diagrams/licensing-flow.mmd` places Admin UI on the far left and End-user App in the middle. User expects End-user App on the far left (primary consumer of the licensing API), with Reseller UI and Admin UI to the right.

## Repro
Open `spec/21-app/diagrams/licensing-flow.mmd` in a Mermaid renderer. Column order left to right: Admin, Reseller, EndUser, API, DB, Audit.

## Expected
Column order left to right: EndUser, Reseller, Admin, API, DB, Audit. The end-user runtime path is the primary read path and should anchor the diagram.

## Related
- spec/21-app/diagrams/licensing-flow.mmd
- spec/23-app-db/09-verify-sequence.mmd
- spec/23-app-db/02-jwt-flow.mmd
- spec/23-app-db/03-oauth-client-credentials.mmd

## Companion artifact request
Emit a JSON representation of the licensing communication flow alongside the .mmd, so downstream tooling can consume it without parsing Mermaid. Suggested name: `licensing-flow.json` (searchable, colocated with the diagram).
