# Blind-AI Audit of spec/21-app (LaraLicensingV1)

Slug: blind-ai-audit-folder-21
Steps: 50
Status: completed
Created: 2026-07-16

## Context

Audit `spec/21-app/` for blind-AI implementability (0-100). Produce a new
`spec/25-app-audit/` folder in the style of `spec/17-consolidated-guidelines/25/26/29-blind-ai-audit-*.md`. Add a self-update endpoint contract to `spec/21-app/` derived from `spec/14-update/`, and wire PowerShell publishing via `linter-scripts/run.ps1` + `spec/11-powershell-integration/`. Flag coding-guideline gaps (spec/02) and user-management clarity gaps (roles, RLS-equivalent, debugging).

Captured inputs:
- Command: `.lovable/spec/commands/01-blind-ai-audit-folder-21.md`
- Prior pending: `.lovable/pending-tasks/task-001-reconcile-spec-numbering.md` (already resolved in v0.13.0 per plan; kept for cross-check)
- Prior issue: `.lovable/pending-issues/issue-001-spec-numbering-drift.md` (same, resolved)

## Steps

1. Read every file under `spec/21-app/` end-to-end and record byte-size, headings, and version tag per file.
2. Read every file under `spec/14-update/` and extract the probe → download → verify → rename → handoff contract as a bullet checklist.
3. Read `spec/11-powershell-integration/` in full and list every PowerShell entry point + exit-code contract.
4. Read `spec/02-coding-guidelines/` overview + language files that could touch the Lara API (PHP, TypeScript, static-analysis specs).
5. Read `spec/03-error-manage/` in full and record the mandatory error-surfacing rules that any Lara code must obey.
6. Read `spec/23-app-db/01-schema.md` and cross-reference every table/column named in `spec/21-app/10-endpoints.md` and `11-api-contracts/`.
7. Read `spec/24-app-ui-design-system/` and map each actor surface in `spec/21-app/16-ui-surfaces.md` to a concrete component/token requirement.
8. Read `.lovable/strictly-avoid/` in full so audit findings never propose banned patterns (dashes, `src/pages/`, admin-client authorization, etc.).
9. Read `.lovable/memory/decisions/api-host-vs-frontend.md` to confirm the Laravel-host assumption used by the audit.
10. Create `spec/25-app-audit/` folder with `00-overview.md` describing scoring rubric (understand / build / modify / validate) mirroring `spec/17-consolidated-guidelines/29-blind-ai-audit-v3.md`.
11. Write `spec/25-app-audit/01-scoring-rubric.md` defining the 0-100 scale, weights, and pass/fail thresholds per capability. See `./subtasks/01-blind-ai-audit-folder-21/SS-01-scoring-rubric.md`.
12. Write `spec/25-app-audit/02-file-inventory.md` — one row per `spec/21-app/*` file with status, size, version, last-updated.
13. Write `spec/25-app-audit/03-per-file-findings.md` — for each file in `spec/21-app/`, list: what's present, what's missing, exact rewrite guidance, severity (🔴🟠🟡🟢).
14. Write `spec/25-app-audit/04-cross-file-consistency.md` — endpoints vs contracts vs DB vs error-taxonomy vs audit-log vs rate-limit vs lifecycle.
15. Write `spec/25-app-audit/05-ui-surface-gaps.md` — per actor (Admin, Reseller, AppBuilder, EndUser), list every route/component/state/empty-error case missing from `16-ui-surfaces.md`.
16. Write `spec/25-app-audit/06-user-management-gaps.md` — role model clarity, `user_roles` table, `has_role` server function, RLS-equivalent policies for the Laravel host, debugging affordances.
17. Write `spec/25-app-audit/07-coding-guideline-alignment.md` — cross-reference every `spec/21-app/` requirement against `spec/02-coding-guidelines/` (15-line functions, 3 params, complexity ≤ 10, static-analysis rules) and `spec/03-error-manage/`.
18. Write `spec/25-app-audit/08-self-update-integration.md` — how `spec/14-update/` maps onto the Lara host. See `./subtasks/01-blind-ai-audit-folder-21/SS-02-self-update-endpoint.md`.
19. Write `spec/25-app-audit/09-powershell-publishing.md` — PowerShell entry point, arguments, exit codes, dry-run, checksum publish, GitHub release upload path.
20. Write `spec/25-app-audit/10-stress-test-tasks.md` — reproduce the 8-task blind-AI stress test from v3 for the LaraLicensingV1 surface.
21. Write `spec/25-app-audit/97-acceptance-criteria.md` mirroring `spec/24-app-ui-design-system/97-acceptance-criteria.md`.
22. Write `spec/25-app-audit/99-consistency-report.md` with health score, file inventory, and validation history seed row.
23. Add a new spec file `spec/21-app/17-self-update-endpoint.md` defining `GET /App/UpdateManifest`, `GET /App/UpdateAsset/{version}/{platform}`, checksum contract, version-probe regex. See `./subtasks/01-blind-ai-audit-folder-21/SS-02-self-update-endpoint.md`.
24. Add `spec/21-app/18-publishing-powershell.md` describing `pwsh scripts/publish-lara.ps1 -Version <semver> -Channel {stable|beta}` and its dry-run/verify flags. See `./subtasks/01-blind-ai-audit-folder-21/SS-03-powershell-publish.md`.
25. Add `spec/21-app/19-user-management.md` — `user_roles` table, `app_role` enum (Admin, Reseller, AppBuilder, EndUser), `has_role(userId, role)` contract, per-role capability matrix. See `./subtasks/01-blind-ai-audit-folder-21/SS-04-user-management.md`.
26. Add `spec/21-app/20-debugging-and-observability.md` — request IDs, structured logs, error surface (never swallow), correlation with audit-log entries.
27. Update `spec/21-app/00-overview.md` file inventory to list new files 17-20 with one-line descriptions.
28. Update `spec/21-app/10-endpoints.md` to reference the new self-update endpoints under a `/App/*` group.
29. Update `spec/21-app/12-error-taxonomy.md` with codes for update flow (`UPDATE_MANIFEST_UNAVAILABLE`, `UPDATE_ASSET_NOT_FOUND`, `UPDATE_CHECKSUM_MISMATCH`, `UPDATE_VERSION_DOWNGRADE_BLOCKED`).
30. Update `spec/21-app/13-audit-logging.md` with audit events for `UpdatePublished`, `UpdateDownloaded`, `UpdateVerified`, `RoleGranted`, `RoleRevoked`.
31. Update `spec/21-app/14-rate-limiting.md` with a bucket for `/App/UpdateManifest` (high-fanout, cache-friendly) and `/App/UpdateAsset/*` (bandwidth-bounded).
32. Update `spec/21-app/15-license-lifecycle.md` to reference user-management (only `Admin` and `Reseller` roles may transition states).
33. Update `spec/21-app/16-ui-surfaces.md` to add a "Publish Update" admin surface and an "Update available" banner for AppBuilder/EndUser roles.
34. Ensure every new `spec/21-app/*.md` file has frontmatter `Version: 0.38.0`, `Updated: 2026-07-16`.
35. Bump `.lovable/plan.md` and `README.md` version pointers to v0.38.0 with a one-line changelog entry for the audit + self-update + user-management additions.
36. Update `CHANGELOG.md` and `RELEASE-NOTES.md` with the v0.38.0 entry.
37. Cross-link `spec/25-app-audit/00-overview.md` from `spec/00-overview.md` and from `spec/21-app/00-overview.md`.
38. Add `linter-scripts/check-lara-audit-links.py` (skeleton) to enforce that every `spec/21-app/*.md` file has a corresponding row in `spec/25-app-audit/03-per-file-findings.md`.
39. Add a health-score computation snippet to `spec/25-app-audit/99-consistency-report.md` (Errors=0, Warnings=<count>, Score=100 − Errors×10 − Warnings×2).
40. Run the blind-AI 8-task stress test on paper (documented in step 20's file) and record pass/partial/fail per task with the reason grounded in a `spec/21-app/*` line reference.
41. Compute the four-capability score (Understand / Build / Modify / Validate) and store it in `spec/25-app-audit/00-overview.md` §TL;DR.
42. List every 🔴 finding with a paired "How to fix" sub-bullet giving the file, section, and exact insertion text.
43. List every 🟠 finding with a paired "How to fix" sub-bullet.
44. List every 🟡 finding with either a fix or an explicit "acceptable-as-is" justification.
45. Verify no finding violates `.lovable/strictly-avoid/` (no em dashes in prose, PascalCase JSON keys, camelCase code, kebab-case slugs, no `src/pages/`, no admin-client authorization).
46. Move the resolved pending-tasks/pending-issues entries into `.lovable/solved-issues/` if not already, and note this in the plan's completion trailer.
47. Add a "How to run the audit again" section to `spec/25-app-audit/00-overview.md` referencing this plan file and the PowerShell publish command.
48. Verify build + typecheck are unaffected by the new spec files (no code changes expected).
49. Move this plan file from `.lovable/plans/pending/` to `.lovable/plans/completed/` and flip `Status:` to `completed` in the same move once steps 1-48 land.
50. Post a one-sentence closing summary to the user with the final blind-AI score and the path to `spec/25-app-audit/00-overview.md`.

## Verification

- All new files exist under `spec/25-app-audit/` and `spec/21-app/17..20-*.md`.
- Health score in `99-consistency-report.md` is computed, not guessed.
- Blind-AI stress test in `10-stress-test-tasks.md` cites file+line for every pass/fail.
- `linter-scripts/check-lara-audit-links.py` runs green.
- Typecheck (`tsgo`) still passes; no source code touched.
- No `.lovable/strictly-avoid/` rule violated in any new prose.

## Appended from prior pending tasks

- `task-001-reconcile-spec-numbering` — already resolved at v0.13.0; kept for cross-check only.
- `issue-001-spec-numbering-drift` — already resolved at v0.13.0; kept for cross-check only.
