# Diagram Contract

**Version:** 1.1.0
**Status:** Normative. Governs every `.mmd` sequence diagram under `spec/`.

## Actor ordering rule (canonical)

Sequence diagrams MUST declare participants left to right in this order, filtering out actors that are not present in the flow:

1. End-user client (any of: `EndUser`, `WinApp`, `Client`, `AppBuilder` when acting as an OAuth client).
2. Reseller-scoped UI (`Reseller`).
3. Admin-scoped UI (`Admin`).
4. Server (`API` / `LicensingServer`).
5. Storage (`DB` / `Store` / `TokenStore` / `Split-DB`).
6. Side systems (`Audit`, `AuditLog`, mailers, external providers).

Source of truth for the rule: `.lovable/spec/commands/03-diagram-actor-ordering.md`.

## JSON companion rule

Every diagram under `spec/21-app/diagrams/` that describes a full communication protocol (issuance, verify, auth handshake, self-update) MUST ship a sibling `<name>.json` with the shape declared in `.lovable/plans/subtasks/04-diagram-orientation-and-json/SS-02-licensing-flow-json-schema.md`.

Reference implementation: `spec/21-app/diagrams/licensing-flow.json`.

## Acceptance criteria

- **AC-DG-001** (actor ordering): For every `.mmd` file whose first non-comment line is `sequenceDiagram`, the `participant` declarations, filtered to those the canonical order recognises, MUST appear in the canonical order defined above. Waivers require an inline `%% lint:allow-actor-order` comment.
- **AC-DG-002** (JSON companion for licensing flow): `spec/21-app/diagrams/licensing-flow.json` MUST exist, MUST reference `spec/21-app/diagrams/licensing-flow.mmd` in its `Source` field, and MUST enumerate `Actors[]` with `Column` values matching the `.mmd` participant order.

## Enforcement

- `spec/21-app/99-consistency-report.md` Check 21 asserts both ACs.
- `linter-scripts/check-mmd-actor-order.py` (wired into `linter-scripts/run.sh` and `run.ps1`) enforces AC-DG-001. Waivers require an inline `%% lint:allow-actor-order`, and files self-declaring `NON-AUTHORITATIVE` are governed by their owning service and skipped.
