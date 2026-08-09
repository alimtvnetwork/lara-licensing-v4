# Spec 24 UI + Swagger Contract (Fluid Design, Modern React, Laravel Backend)

Slug: spec-24-ui-fluid-swagger
Steps: 50
Status: pending
Created: 2026-07-17

## Context

Expand `spec/24-app-ui-design-system/` from a 4-file contract into a full AI-buildable UI specification for LaraLicensingV1. The frontend stack is React/Next.js flavored (TanStack Start today, patterns must be portable to Next.js App Router); the backend is Laravel exposing a Swagger/OpenAPI-documented REST surface. UI must use fluid design (clamp-based type/space scales), minimize CSS-in-JS and heavy frameworks, and rely on native CSS (custom properties, container queries, `:has()`, cascade layers, logical properties). Every UI surface must declare which Swagger operation it calls and how (verb, path, auth, headers, envelope, errors). Swagger UI itself must be gated by authentication + permission for third-party consumers.

Related open items pulled from `.lovable/` scan:
- `.lovable/plans/pending/05-rbac-quota-tier-environment.md` (Plan 05 remaining steps: Admin.Features UI, role-aware nav, Playwright smoke).
- `.lovable/pending-issues/issue-001-spec-numbering-drift.md`
- `.lovable/pending-issues/issue-002-lib-runtime-spec-drift.md`
- `.lovable/pending-tasks/task-001-reconcile-spec-numbering.md`

New captured input from this turn: none routed to `.lovable/spec/commands/` or `.lovable/issues/` (the message is a scoped planning brief, not a persistent command or a bug report).

## Scope Anchors

- Target folder: `spec/24-app-ui-design-system/` (new files 05..40 + 90..96).
- Companion: `spec/21-app/11-api-contracts/` cross-links to Swagger operationIds.
- New sibling: `spec/26-app-api-swagger/` for OpenAPI authoring rules, gating, hosting.
- No code edits this plan; specs, diagrams, checklists, colored examples only.

## Steps

1. Create `spec/24-app-ui-design-system/05-team-mood-and-ux-north-star.md`: define team mood (calm, precise, operator-grade), UX north star (5 verbs: Verify, Issue, Renew, Investigate, Recover), non-goals, target personas mapped to `21-app` actors.
2. Create `spec/24-app-ui-design-system/06-fluid-design-foundations.md`: fluid type scale via `clamp()`, fluid space scale, min/ideal/max viewport anchors (360, 768, 1440, 1920), container-query breakpoints, `@layer` order (`reset, tokens, base, components, utilities, overrides`).
3. Create `spec/24-app-ui-design-system/07-css-technique-budget.md`: allowed native CSS (custom properties, `color-mix`, OKLCH, container queries, `:has()`, `:is()`, cascade layers, logical properties, `light-dark()`, `@starting-style`); banned techniques (CSS-in-JS runtime, styled-components, emotion, heavy animation libs beyond framer-motion for orchestrated flows).
4. Create `spec/24-app-ui-design-system/08-token-registry.md`: full OKLCH values for `background`, `surface`, `surface-raised`, `primary`, `accent`, `success`, `warning`, `destructive`, `muted`, `border`, `input`, `ring` in light and dark; include hex + OKLCH swatch grid and WCAG contrast pairs table.
5. Create `spec/24-app-ui-design-system/09-typography-scale.md`: fluid step table (Display, Title, Heading, Subheading, Body, Label, Code) with clamp expressions, line-height, tracking, and IBM Plex Sans / JetBrains Mono fallback stacks.
6. Create `spec/24-app-ui-design-system/10-spacing-and-layout-grid.md`: 4pt base, fluid rhythm, container widths (`--container-narrow: 480px`, `--container-app: 1440px`), gutters, safe-area insets, page grid template areas for authenticated shell.
7. Create `spec/24-app-ui-design-system/11-shape-elevation-motion.md`: radius scale (0, 4, 6, 8, 12), elevation as border+shadow stack, motion tokens (`--motion-fast: 150ms`, `--motion-medium: 200ms`, `--motion-slow: 250ms`), easing curves, reduced-motion rules.
8. Create `spec/24-app-ui-design-system/12-iconography-and-assets.md`: single outline family (Lucide 1.5px), naming, sizing (16/20/24), accessible-name rule for icon-only commands, illustration policy (none unless spec-approved).
9. Create `spec/24-app-ui-design-system/13-content-voice.md`: writing rules for labels, empty states, error copy (must include ErrorCode + human line + next action), destructive confirmation phrasing.
10. Create `spec/24-app-ui-design-system/14-a11y-conformance.md`: WCAG 2.2 AA required checks, focus rings, ARIA patterns per component, screen-reader announcements for lifecycle changes.
11. Create `spec/24-app-ui-design-system/15-responsive-matrix.md`: exact behavior at 360, 390, 768, 1024, 1440, 1920 for shell, tables, forms, dialogs, cards; container-query rules per component.
12. Create `spec/24-app-ui-design-system/16-shell-layout-spec.md`: normative layout tree (Sidebar 240px, TopBar 56px, Main, Right rail), collapse rules, focus order, keyboard shortcuts (⌘K, g+letter navigation).
13. Create `spec/24-app-ui-design-system/17-navigation-per-actor.md`: exact nav items for Admin, Reseller, AppBuilder, EndUser with route paths, permission keys, and icon names; matches `21-app/16-ui-surfaces.md`.
14. Create `spec/24-app-ui-design-system/18-page-header-and-actions.md`: H1 rule, breadcrumb rule, primary/secondary/destructive placement, mobile stacking rule.
15. Create `spec/24-app-ui-design-system/19-command-catalog.md`: button variants (primary, secondary, ghost, destructive, icon), loading contract, doubled-click prevention, keyboard activation.
16. Create `spec/24-app-ui-design-system/20-form-catalog.md`: input, select, combobox, textarea, checkbox, radio, switch, date/time, secret-reveal, serial/hash monospace input; validation timing (blur + submit), error summary pattern, autofocus rule.
17. Create `spec/24-app-ui-design-system/21-table-and-list-catalog.md`: sortable headers, URL-encoded filters, pagination contract, row menu, empty/loading/error/partial states, mobile record-card transform.
18. Create `spec/24-app-ui-design-system/22-status-badge-catalog.md`: complete state → tone → icon → label mapping for every LicenseState, SerialState, BuilderKeyState from `21-app/15-license-lifecycle.md`.
19. Create `spec/24-app-ui-design-system/23-feedback-overlays.md`: toast, inline alert, dialog, drawer, sheet contracts; RequestId disclosure; retry-after banner reuse (`src/components/retry-after-banner.tsx`).
20. Create `spec/24-app-ui-design-system/24-kpi-and-chart-catalog.md`: KPI card shape, chart primitives (line, bar, sparkline), textual-summary requirement, non-color series distinction, unavailable-data placeholder.
21. Create `spec/24-app-ui-design-system/25-empty-error-forbidden-states.md`: canonical illustrations (none, use icon+text), copy per state, permitted next actions, forbidden route rendering, stale-content rule.
22. Create `spec/24-app-ui-design-system/26-loading-and-skeletons.md`: shell-preserving skeleton geometry, shimmer motion budget, when to use spinner vs skeleton vs progress bar.
23. Create `spec/24-app-ui-design-system/27-search-and-command-palette.md`: `⌘K` palette spec, scoped search, recent items, permission filtering, keyboard model.
24. Create `spec/24-app-ui-design-system/28-notifications-and-audit-hooks.md`: bell menu, unread badge, audit link pattern, deep-link to `Admin.Audit` filtered view.
25. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/00-index.md`: index of per-route blueprint files (30..40 below); each blueprint = purpose + layout ASCII + data source (Swagger operationId) + states + AC-IDs.
26. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/01-public-landing.md`: `/` blueprint.
27. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/02-auth-signin-signup-verify.md`: `/auth/*` and `/verify` blueprints.
28. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/03-admin-overview-and-audit.md`: `/admin`, `/admin/audit`, `/admin/abuse` blueprints.
29. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/04-admin-resellers-users-categories.md`: `/admin/resellers/*`, `/admin/users/*`, `/admin/categories/*`.
30. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/05-admin-licenses-serials-features.md`: `/admin/licenses/*`, `/admin/serials/*`, `/admin/features/*` (Plan 05 residual).
31. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/06-reseller-portal.md`: `/reseller/*` including quota-requests.
32. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/07-appbuilder-portal.md`: `/builder/*` clients, keys, logs.
33. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/08-enduser-portal.md`: `/me/*` products, devices, profile.
34. Create `spec/24-app-ui-design-system/29-per-surface-blueprints/09-swagger-console.md`: `/api-docs` gated Swagger UI blueprint (auth + `Api.Swagger.Read` permission, tenant scoping, try-it-out policy). See `./subtasks/06-spec-24-ui-fluid-swagger/SS-01-swagger-console.md`.
35. Create `spec/24-app-ui-design-system/30-checklist-for-new-surface.md`: numbered checklist an AI must satisfy before shipping a new route (tokens, fluid clamps, container queries, a11y, Swagger operationId link, error taxonomy binding, tests).
36. Create `spec/24-app-ui-design-system/31-colored-examples.md`: side-by-side "do / don't" swatches with rendered token names, contrast ratios, and OKLCH values (markdown color blocks + hex).
37. Create `spec/24-app-ui-design-system/32-nextjs-portability-notes.md`: mapping from TanStack Start primitives to Next.js App Router equivalents (loader ↔ server component/`fetch`, `createServerFn` ↔ Route Handler / Server Action, `<Link>` parity).
38. Create `spec/24-app-ui-design-system/33-laravel-backend-contract.md`: Laravel side (controllers, form requests, resources, sanctum/passport, rate-limit middleware, envelope shape) matching `21-app/11-api-contracts/*`.
39. Create `spec/24-app-ui-design-system/34-endpoint-visualization.md`: rule that every UI blueprint MUST include a "Calls" table (Verb, Path, OperationId, AuthScope, Idempotency, ErrorCodes) and a mermaid sequence snippet.
40. Create `spec/26-app-api-swagger/00-overview.md`: new spec folder scoping OpenAPI 3.1 authoring, file layout (`openapi.yaml` split by tag), servers block for anywhere-hosting.
41. Create `spec/26-app-api-swagger/01-authoring-rules.md`: naming (operationId = `Domain.Action`), schema reuse, error envelope schema import, examples requirement.
42. Create `spec/26-app-api-swagger/02-auth-and-gating.md`: Swagger UI behind login + `Api.Swagger.Read` permission; third-party access via personal access tokens; production vs sandbox servers; CORS rules.
43. Create `spec/26-app-api-swagger/03-hosting-anywhere.md`: `servers:` list, environment placeholder policy, self-hosted Laravel deployments, health probe, versioning header.
44. Create `spec/26-app-api-swagger/04-try-it-out-policy.md`: which endpoints allow live try-it-out (read-only + sandbox mutations), rate-limit disclosure, idempotency key auto-fill.
45. Create `spec/26-app-api-swagger/97-acceptance-criteria.md`: AC-SWG-001..010 covering auth gate, permission gate, operationId parity with `spec/21-app/26-route-dto-index.md`, examples for every 4xx.
46. Update `spec/24-app-ui-design-system/00-overview.md` inventory table + version to 0.24.0; add AC-ADS-018..030 covering fluid clamps, container queries, Swagger link presence, endpoint-visualization table. See `./subtasks/06-spec-24-ui-fluid-swagger/SS-02-overview-and-ac-update.md`.
47. Update `spec/24-app-ui-design-system/97-acceptance-criteria.md`: add new AC rows and verification commands (`check-spec-cross-links.py`, new `check-swagger-operationid-parity.py` placeholder).
48. Update `spec/24-app-ui-design-system/99-consistency-report.md`: log every new file, cross-link source alignment, remaining gaps (implementation, Swagger generator).
49. Update `spec/21-app/26-route-dto-index.md` and `spec/21-app/16-ui-surfaces.md`: add "OperationId" column pointing into `spec/26-app-api-swagger/` and "UI Blueprint" column pointing into `spec/24-app-ui-design-system/29-per-surface-blueprints/*`.
50. Update `spec/00-overview.md` folder index to register `26-app-api-swagger/`, bump project `.lovable/project.json` spec-version marker, and file a follow-up plan stub `.lovable/plans/pending/07-swagger-generator-runtime.md` for the runtime work (do not write its 50 steps yet; stub only, `Status: pending`, `Steps: TBD`).

## Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0 after every new file lands.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0 (no em dashes, no banned tokens).
- Each new file passes the AC block presence check (`Verification` section required by `spec/_template.md`).
- Manual review: every blueprint under `29-per-surface-blueprints/` contains (a) ASCII layout, (b) Calls table, (c) States list, (d) AC-IDs.
- Cross-link parity: every UI blueprint operationId exists in `spec/26-app-api-swagger/` and in `spec/21-app/26-route-dto-index.md`.

## Appended from prior pending tasks

- Plan 05 residuals (Admin.Features UI, role-aware nav, Playwright smoke) are absorbed into steps 30, 13, and the Verification section.
- `.lovable/pending-issues/issue-001-spec-numbering-drift.md` acknowledged; step 50 touches `spec/00-overview.md` numbering registry.
- `.lovable/pending-issues/issue-002-lib-runtime-spec-drift.md` remains out of scope (runtime); tracked separately.
- `.lovable/pending-tasks/task-001-reconcile-spec-numbering.md` acknowledged in step 50.
