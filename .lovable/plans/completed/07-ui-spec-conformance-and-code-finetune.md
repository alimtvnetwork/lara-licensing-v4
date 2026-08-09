# UI Spec Conformance and Code Fine-Tune

Slug: ui-spec-conformance-and-code-finetune
Steps: 50
Status: completed
Created: 2026-07-18

## Context

Bring the frontend into conformance with `spec/24-app-ui-design-system/` (tokens, shell, components, blueprints, motion, a11y, copy) and fine-tune the coding across FE and Laravel BE per `spec/02-coding-guidelines/` and `spec/03-error-manage/`. Existing prior pending plans: `05-rbac-quota-tier-environment.md`, `06-laravel-be-fe-and-publish.md` (still in progress); this plan is additive and focused on UI polish + code hygiene, not on replacing them.

Related sources read: spec/24-app-ui-design-system/{01,02,03,04,06,07,08,09,10,11,12,13,14,15,16,17..26,27,28,29,30,31,50,51,52,53,54,56,57}, spec/02-coding-guidelines/{02-typescript,04-php,11-security}, spec/03-error-manage/**.

No new commands or issues were provided this turn.

## Steps

1. [DONE v0.247.0] Inventory current `src/styles.css` tokens vs `spec/24-app-ui-design-system/08-token-registry.md`; produce a delta list in `.lovable/plans/subtasks/07-ui-spec-conformance-and-code-finetune/SS-01-token-delta.md`. See ./subtasks/07-ui-spec-conformance-and-code-finetune/SS-01-token-delta.md
2. [DONE v0.247.0] Rewrite `src/styles.css` OKLCH color, surface, border, and focus-ring tokens to match token registry (light + dark), keeping `@theme inline` mapping for shadcn. Also delivered: elevation, motion, spacing, radius rescale, reduced-motion override (originally scheduled for later steps but landed atomically for a single coherent stylesheet).
3. [DONE v0.248.0] Add spec typography scale tokens (`--text-*`, line-height, tracking) from `09-typography-scale.md` and wire display + body font pair via `<link>` in `src/routes/__root.tsx`. Also delivered: `--font-sans`, `--font-mono`, weight ladder, `--font-variant-numeric-tabular`, `@utility tabular`, base `html`/`body`/`code` family wiring.
4. Add spacing, radius, shadow, elevation, and z-index tokens per `10-spacing-and-rhythm.md` and `11-shape-and-motion.md`.
5. [DONE v0.264.0] Add motion tokens (`--duration-*`, `--ease-*`) and a `prefers-reduced-motion` override block per `51-motion-and-reduced-motion.md`.
6. [DONE v0.249.0] Introduce a `@utility` set for gradient surfaces, elevated cards, and focus-visible rings using tokens; no hardcoded colors. Delivered: `elevated-card` (card bg + border + radius-lg + elevation-1), `gradient-surface` (surface-raised → surface linear-gradient), `focus-ring-inset` (dense-cell variant using inset box-shadow).
7. [DONE v0.248.0] Add a lint script `linter-scripts/check-hardcoded-colors.py` that fails on `text-white|bg-black|#[0-9a-f]{3,8}` in `src/**/*.tsx`. Also blocks `rgb|rgba|hsl|hsla(` and border/ring/fill/stroke white/black variants; supports per-line and per-file allow annotations.
8. [DONE v0.249.0] Wire that linter and the existing magic-literal linter into `package.json` `scripts.lint:ui`. Color half clean; magic-literal half surfaces 113 pre-existing findings (62 FE catalog gaps + 51 BE, tracked separately).
9. [PARTIAL v0.250.0] Build the app shell per `12-shell-layout.md`: `AppShell` with top bar, collapsible sidebar (shadcn), content region, and toast slot. Delivered part 1 of 3: CSS grid contract (`shell-app|public|error`, `page-content-container`), `--shell-*` measures, `--space-*` aliases, `--z-*` register, `--elevation-*` and `--motion-*` aliases, breakpoint overrides, opt-in scroll lock, and `src/components/shell/AppShell.tsx` primitive (Sidebar/Topbar/Main slots). Follow-ups: AppSidebar (step 10), PageHeader/Breadcrumbs (step 11), migrate `_authenticated.tsx`+`AdminShell` to consume `AppShell`, mobile Sheet sidebar (Recipe D), toast slot.
10. Implement `AppSidebar` per `13-navigation-ia.md`: role-scoped nav groups (Admin, Reseller, Builder, EndUser), active-route highlight via `useRouterState`.
11. Implement `PageHeader` + `Breadcrumbs` per `14-breadcrumbs-and-page-header.md`, driven by route `staticData` crumbs.
12. [DONE v0.263.0] Standardize route shell states (loading/empty/error/forbidden) per `15-empty-error-loading-catalog.md` + `16-route-shell-states.md` as reusable components under `src/components/state/`.
13. [DONE v0.263.0] Wire the router `defaultErrorComponent`, per-route `errorComponent`, and `notFoundComponent` to these standardized shells.
14. [DONE v0.264.0] Refit `Button` variants to match `17-component-button.md` (primary/secondary/ghost/destructive/link + sizes + loading + icon-only).
15. Refit `Input`, `Textarea`, `Select`, `Combobox` to `18-component-input.md` and `19-component-select.md` (label, hint, error, description slots, aria wiring).
16. Refit `Checkbox`, `Radio`, `Switch`, `SegmentedControl` per `20-component-choice.md`.
17. Refit `Dialog`, `Sheet`, `AlertDialog` per `21-component-dialog.md` (focus trap, initial focus, close semantics).
18. Refit `DropdownMenu`, `Popover`, `Tooltip`, `ContextMenu` per `22-component-menu-popover.md`.
19. Add `Toast` + inline `Banner` per `23-component-toast-banner.md` and expose an `useAppToast()` wrapper with error taxonomy mapping.
20. Refit `DataTable` per `24-component-table.md`: sticky header, column sizing, empty state, row density, keyboard nav.
21. Refit `Badge`, `StatusPill`, `Tag` per `25-component-badge-status.md` mapped to error/quota/license status enums.
22. Consolidate iconography per `26-iconography-and-assets.md`: single `Icon` component wrapping `lucide-react` with size + label props.
23. Add copy dictionary constants from `56-copy-dictionary.md` under `src/i18n/copy.ts`; forbid inline UI strings via a codemod-check script.
24. Implement `CommandPalette` per `31-search-and-command-palette.md` + `32-command-registry.md` with keyboard shortcut registry from `57-keyboard-shortcut-registry.md`.
25. Build `Admin/Overview` route per `33-route-blueprint-admin-overview.md` with KPI cards and charts sourced from `30-kpi-and-chart-catalog.md`. See ./subtasks/07-ui-spec-conformance-and-code-finetune/SS-02-admin-overview.md
26. Refit `Admin/Licenses` route per `34-route-blueprint-admin-licenses.md`: filters, table columns, row actions, drawer detail.
27. Build `Admin/Serials` route per `35-route-blueprint-admin-serials.md` (issue, lookup, revoke).
28. Build `Admin/Users` route per `36-route-blueprint-admin-users.md` (role assignment, disable, impersonate entry).
29. Build `Admin/QuotaApprovals` route per `37-route-blueprint-admin-quota-approvals.md` with cross-tenant fanout view.
30. Build `Admin/Features` route per `38-route-blueprint-admin-features.md` (tier x env matrix editor).
31. Build `Reseller/Portal` route per `39-route-blueprint-reseller-portal.md`.
32. Build `Builder/Console` route per `40-route-blueprint-builder-console.md`.
33. Build `EndUser/Me` route per `41-route-blueprint-enduser-me.md`.
34. Build `Auth/Login`, `403`, `404`, `500` routes per `42-route-blueprint-auth-and-403-404-500.md`.
35. Wire every mutation UI to the error taxonomy: map `LaraException` codes to toast copy from the dictionary, no raw error strings surfaced.
36. Add `useServerFn` + TanStack Query wrappers for each protected server fn, centralized in `src/lib/serverFns.ts` with typed error branch handling.
37. Add `ETag` precondition + `Idempotency-Key` header helpers in `src/lib/http.ts` and route all mutating calls through them; on 412 render the `PreconditionRecovery` UX per spec.
38. Add responsive breakpoints and layout matrix per `29-responsive-matrix.md`; verify shell + tables at `sm/md/lg/xl/2xl` via Playwright screenshots.
39. Add a11y pass per `28-a11y-conformance.md`: axe-core CI script under `linter-scripts/axe-check.mjs` invoked against a Playwright build of every route.
40. Add empty-state illustrations from `52-icon-illustration-registry.md` and wire them into `53-empty-state-catalog.md` slots.
41. Add loading skeletons per `54-loading-state-catalog.md` for tables, cards, forms, and detail drawers.
42. Add print/export stylesheet per `55-print-export-stylesheet.md` for License, Serial, and Ledger detail pages.
43. FE code fine-tune: enforce 15-line function-body cap via ESLint rule `max-lines-per-function` scoped to `src/**/*.tsx` with an autofix pass. See ./subtasks/07-ui-spec-conformance-and-code-finetune/SS-03-eslint-hardening.md
44. FE code fine-tune: enable `@typescript-eslint/consistent-type-imports`, `no-floating-promises`, `no-misused-promises`, `no-unnecessary-condition`; fix all resulting findings.
45. BE code fine-tune (Laravel): audit controllers for FormRequest + Policy + Resource usage per `mem://preferences/laravel-best-practices.md`; refactor stragglers.
46. BE code fine-tune: replace any remaining magic literals in `backend/app/**` with enum/config/constant references, verified by `linter-scripts/check-magic-literals.py`.
47. BE code fine-tune: ensure every service method matches error-manage rules from `spec/03-error-manage/**` (no swallowed exceptions, structured logs with request context, taxonomy-mapped `LaraException`).
48. BE code fine-tune: add Pest coverage for the newly refit UI-facing endpoints (list + detail + mutation happy path + one taxonomy failure each).
49. Run `bun run lint:ui`, `tsgo`, `bun run build`, and `php artisan test` (backend); fix regressions until all green.
50. Author `spec/24-app-ui-design-system/62-plan-07-acceptance-report.md` capturing screenshots, a11y results, and coverage delta, then move this plan to `.lovable/plans/completed/07-ui-spec-conformance-and-code-finetune.md` with `Status: completed`.

## Verification

- `bun run lint:ui` clean (no hardcoded colors, no magic literals in `src/`).
- `tsgo` clean.
- `bun run build` clean; Vite preview boots without console errors.
- Playwright screenshots per route at all responsive breakpoints archived under `/tmp/browser/plan-07/`.
- axe-core: zero serious/critical violations per route.
- `php artisan test` all green; new Pest cases cover UI-facing endpoints.
- Acceptance report `62-plan-07-acceptance-report.md` merged.

## Appended from prior pending tasks

- `05-rbac-quota-tier-environment.md` (unchanged, still pending, unrelated scope)
- `06-laravel-be-fe-and-publish.md` (unchanged, still pending; this plan consumes some of its FE surface but does not close it)
