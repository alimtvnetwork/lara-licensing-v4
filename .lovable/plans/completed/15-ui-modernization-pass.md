# UI Modernization Pass (Fluid, Depth, Motion)

Slug: ui-modernization-pass
Steps: 30
Status: completed
Created: 2026-07-20

## Context

Extend the v0.487.0 shell modernization (glass topbar, sidebar gradient, brand tile, StateCard halo, PageHeader gradient underline, shimmer skeleton, fade-in) across every remaining surface: KPI tiles, DataTable, forms, dialogs, banners, badges, toasts, empty states, admin overview, license/reseller detail pages, and auth screens. All work stays in presentation code (`src/components/**`, `src/routes/**`, `src/styles.css`); no business logic, no token renames, no schema changes. Existing Vitest and Playwright suites (`tests/route-states.test.tsx`, `tests/skeleton-and-empty-state.test.tsx`, `tests/shell-*`, `tests/stat-card.test.tsx`, `tests/data-table.test.tsx`, `tests/e2e/specs/admin-dashboard.spec.ts`) must continue to pass unchanged.

Follows Spec 24 (`spec/24-app-ui-design-system/`) token discipline, closed-set copy in `src/lib/copy.ts`, and the Plan 11 error-contract axis (no new toast/error surfaces outside the established stack). No prior pending plan blocks this work.

## Steps

1. Read `src/styles.css` end to end and inventory every utility added in v0.487.0; write a short comment block near line 534 listing which surfaces adopt each.
2. Add `--shadow-inset-hairline` and `--ring-focus-strong` tokens for elevated interactive surfaces; keep names Spec-24 aligned.
3. Add `@utility surface-elevated` (card + hairline inset shadow + subtle top tint) used by tiles and dialogs.
4. Add `@utility chip` for compact metadata chips (identifier chips, role chips, closed-set values) with tonal variants driven by `data-tone`.
5. Refit `src/components/ui/card.tsx` to opt into `surface-elevated` via a `variant="elevated"` prop; keep default variant byte-identical.
6. Refit `src/components/admin/stat-card.tsx` KPI tile: gradient number, tonal delta chip, hover-lift, focus ring. Verify `tests/stat-card.test.tsx` still passes.
7. Modernize `src/routes/_authenticated/admin.index.tsx` KPI grid spacing and add a subtle `dot-pattern` panel behind the headline row.
8. Refit `src/components/ui/button.tsx` primary variant to a two-stop gradient (`--primary` -> `color-mix accent`) with `shadow-glow-primary` on hover; keep destructive/ghost/outline byte-identical.
9. Refit `src/components/ui/badge.tsx` to use the new `chip` utility for tonal badges; keep default sizing.
10. Refit `src/components/ui/input.tsx` and `textarea.tsx`: rounded-lg, hairline border, focus ring uses `--ring-focus-strong`. Verify `tests/input-textarea.test.tsx`.
11. Refit `src/components/ui/dialog.tsx` and `sheet.tsx` overlays: increase blur, use `--shadow-elevation-3`, add `fade-in` on content. See ./subtasks/15-ui-modernization-pass/SS-01-overlays.md
12. Refit `src/components/ui/dropdown-menu.tsx` and `popover.tsx` panels to match dialog radii and elevation-2.
13. Refit `src/components/data-table.tsx` header row: sticky, glass-topbar backdrop, gradient underline on sorted column. See ./subtasks/15-ui-modernization-pass/SS-02-data-table.md
14. Refit table row hover to a tinted surface using `color-mix(in oklab, var(--primary) 4%, transparent)` instead of flat muted.
15. Refit `src/components/ui/skeleton.tsx` variants (`row`, `title`, `avatar`) so the shimmer sweep respects `prefers-reduced-motion` via `@media`.
16. Refit `src/components/state/state-empty.tsx` (or equivalent EmptyState) with the same halo/gradient underline treatment as StateCard.
17. Refit `src/components/shell/PageHeader.tsx` breadcrumbs: chevron in muted, current segment in `chip` with `data-current`.
18. Refit `src/components/shell/AppSidebar.tsx` group labels: smaller caps, tracking-widest, muted; add subtle divider between groups.
19. Refit `src/components/admin/user-data-table.tsx` role column to render role chips (uses new `chip` utility) with tone per role.
20. Refit `src/components/admin/reseller-quota-requests-data-table.tsx` status column to tonal chips (open/approved/denied) sourced from `closed-sets.ts`.
21. Refit `src/hooks/use-app-toast.ts` toast surface via Sonner theme override: rounded-xl, elevation-2, hairline border, `fade-in`. See ./subtasks/15-ui-modernization-pass/SS-03-toast-theme.md
22. Refit `src/components/impersonation-banner.tsx` and `update-banner.tsx` to a single `Banner` primitive using the `chip` tonal system.
23. Refit `src/components/retry-after-banner.tsx` and `global-rate-limit-banner.tsx` to the same primitive; run `tests/banner.test.tsx`, `tests/retry-after-banner.test.tsx`, `tests/global-rate-limit-banner.test.tsx`.
24. Refit `src/routes/index.tsx` (public landing) hero: gradient headline, `dot-pattern` behind, feature cards use `surface-elevated`.
25. Refit `src/routes/forgot-password.tsx`, `reset-password.tsx`, and the admin/reseller login route auth cards to `surface-elevated` with brand-tile mark.
26. Verify SSR + hydration parity by loading `/`, `/admin/login`, `/admin` via Playwright headed script under `/tmp/browser/ui-modern/` and screenshotting sm/md/lg viewports. See ./subtasks/15-ui-modernization-pass/SS-04-visual-verification.md
27. Run full Vitest suite (`bunx vitest run`) and fix any silhouette/selector regressions without touching test bodies.
28. Run `bunx playwright test tests/e2e/specs/admin-dashboard.spec.ts` and confirm KPI region, sidebar nav, and PageHeader still resolve.
29. Run `python linter-scripts/check-hardcoded-colors.py` and `python linter-scripts/check-heading-fonts.py` to confirm no new hardcoded color or font drift was introduced.
30. Bump to v0.488.0 across `package.json`, `backend/composer.json`, `README.md` badge + version line, add CHANGELOG entry, then move this file to `.lovable/plans/completed/15-ui-modernization-pass.md` and flip Status to `completed`.

## Verification

- Vitest: every existing `tests/*.test.tsx` under the touched components passes without body edits.
- Playwright: `tests/e2e/specs/admin-dashboard.spec.ts` still green; new screenshots under `/tmp/browser/ui-modern/screenshots/` show glass topbar, gradient title underline, tonal chips, and shimmer skeletons on sm/md/lg.
- Linters: `check-hardcoded-colors.py`, `check-heading-fonts.py`, `check-magic-literals.py` all exit 0.
- Build: `bun run build` succeeds; no new Vite warnings.
- Version: README badge + version line and both manifests read `0.487.0` -> `0.488.0` in one commit.

## Appended from prior pending tasks

- 05-rbac-quota-tier-environment (unchanged, unrelated backend work)
- 06-laravel-be-fe-and-publish (unchanged, backend release workflow)
- 07-ui-spec-conformance-and-code-finetune (parent of admin overview subtasks; Step 7 above touches its subtree but does not close it)
- 09-fluid-ui-and-cpanel-release (unchanged, release automation)
- 12-backup-restore-snapshot (superseded by 13/14 spec + implementation ladder)
- 13-backup-restore-spec-authoring (spec authoring, closed at v0.484.0 handover)
- 14-backup-restore-implementation (in progress, Step 1 shipped at v0.486.0)
