---
Slug: SS-02-overview-and-ac-update
Parent: 06-spec-24-ui-fluid-swagger
Status: pending
Created: 2026-07-17
---

# Overview + AC Update (spec/24-app-ui-design-system/00-overview.md, 97-acceptance-criteria.md)

## Inventory table (target shape for 00-overview.md)

Add rows 05..34 for the new files listed in the parent plan, plus 29-per-surface-blueprints/00-index.md through 09-swagger-console.md.

Bump version `0.23.0` → `0.24.0`, Updated date to the day the update lands.

## New Acceptance Criteria (AC-ADS-018..030)

- AC-ADS-018: Every UI surface uses fluid `clamp()` type + spacing from `06-fluid-design-foundations.md`.
- AC-ADS-019: No component ships CSS-in-JS runtime; all styling via cascade layers and tokens.
- AC-ADS-020: Container queries drive component-level responsiveness for table, form, and card catalogs.
- AC-ADS-021: Every blueprint file includes a Calls table with OperationId parity to `spec/26-app-api-swagger/`.
- AC-ADS-022: Every blueprint file includes a mermaid sequence snippet showing UI → API → DB flow.
- AC-ADS-023: Every blueprint declares required permission keys from `spec/21-app/40-permissions.md`.
- AC-ADS-024: Swagger console only renders after permission `Api.Swagger.Read` is verified server-side.
- AC-ADS-025: Swagger try-it-out is disabled for write operations on prod server.
- AC-ADS-026: Every rendered API response panel shows `X-Request-Id` with copy affordance.
- AC-ADS-027: Endpoint visualization sequence diagrams place EndUser far left, Admin far right per `spec/21-app/diagrams/00-diagram-contract.md`.
- AC-ADS-028: Colored examples file lists WCAG contrast for every token pair used on text.
- AC-ADS-029: New-surface checklist (`30-checklist-for-new-surface.md`) is referenced from every blueprint.
- AC-ADS-030: Next.js portability notes cover loader/action/link parity for all app routes.

## Verification commands

```bash
python3 linter-scripts/check-spec-cross-links.py
python3 linter-scripts/check-forbidden-strings.py
python3 linter-scripts/check-mmd-actor-order.py
# New placeholder linter (to be authored under a follow-up plan):
# python3 linter-scripts/check-swagger-operationid-parity.py
```
