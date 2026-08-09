---
Slug: 02-admin-overview
Status: pending
Created: 2026-07-18
Parent: 07-ui-spec-conformance-and-code-finetune
---

# Admin Overview Route

Implement `/admin` per `spec/24-app-ui-design-system/33-route-blueprint-admin-overview.md`.

Structure:
- `PageHeader` with breadcrumbs (Home > Admin > Overview).
- KPI grid: Active Licenses, Pending Quota Requests, Bindings (24h), Update Adoption. Pull metrics via `createServerFn` under `_authenticated/admin` layout; use `ensureQueryData` in loader.
- Charts: license issuance (7d), quota approvals (30d), self-update adoption (per channel). Pull catalog IDs from `30-kpi-and-chart-catalog.md`.
- Recent activity list from `AuditLogs` (last 25) with actor, action, target, timestamp.
- All copy from `src/i18n/copy.ts`.
- Empty/loading/error slots wired to `src/components/state/*`.
- Reduced-motion respected on chart animation.

Verification: Playwright screenshots at sm/md/lg/xl; axe-core clean; server fn returns cached data under 200ms warm.
