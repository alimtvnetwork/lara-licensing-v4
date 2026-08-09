# Improve spec/21-app from Blind-AI Audit Findings

Slug: spec-21-audit-remediation
Steps: 50
Status: completed
Created: 2026-07-16

## Context

The blind-AI audit under `spec/25-app-audit/` sealed at 100.0 Band A but its verdict is not human-readable, and the audit's findings ledger (AF-SU-021, AF-ID-030/031, AF-OBS-050/051, AF-UM-014, AF-ST-001, AF-MIG-040) was resolved by patching downstream artifacts rather than the original `spec/21-app/` files. This plan pushes those fixes back into `spec/21-app/` so the source spec, not the audit companion, becomes the single normative surface, and binds every clause to `.lovable/coding-guidelines/` and `spec/03-error-manage/`.

Captured inputs:
- Command: `.lovable/spec/commands/02-improve-spec-21-from-audit.md`
- Issue: `.lovable/issues/01-audit-verdict-not-human-readable.md`

Depth files:
- `./subtasks/02-spec-21-audit-remediation/SS-01-plain-language-verdict.md`
- `./subtasks/02-spec-21-audit-remediation/SS-02-error-manage-mapping.md`
- `./subtasks/02-spec-21-audit-remediation/SS-03-coding-guideline-conformance.md`
- `./subtasks/02-spec-21-audit-remediation/SS-04-version-drift-reconciliation.md`

## Steps

1. Read every file in `spec/25-app-audit/` and produce a one-page findings index at `spec/25-app-audit/98-findings-index.md` listing AF-ID, severity, resolved-in version, and target file under `spec/21-app/`.
2. Author the plain-language verdict page. See ./subtasks/02-spec-21-audit-remediation/SS-01-plain-language-verdict.md.
3. Link the verdict page from `spec/21-app/00-overview.md` header and from `README.md` project status line.
4. Reset spec/21-app version drift to a shared 1.0.0 baseline. See ./subtasks/02-spec-21-audit-remediation/SS-04-version-drift-reconciliation.md.
5. Record the version reset in `.lovable/memory/history/01-decisions.md`.
6. In `spec/21-app/00-overview.md`, add a "Normative sources" section citing `.lovable/coding-guidelines/coding-guidelines.md`, `spec/02-coding-guidelines/01-cross-language/15-master-coding-guidelines.md`, and `spec/03-error-manage/`.
7. Author `spec/21-app/18-error-management-binding.md`. See ./subtasks/02-spec-21-audit-remediation/SS-02-error-manage-mapping.md.
8. Cross-link `18-error-management-binding.md` from `10-endpoints.md`, `12-error-taxonomy.md`, and `14-rate-limiting.md`.
9. In `12-error-taxonomy.md`, add for each `ErrorCode` the mandatory log level and whether the client MUST surface `X-Request-Id`.
10. In `12-error-taxonomy.md`, add the nine codes added to `src/lib/lara-api-error.ts` (Step 54) as first-class entries with description, HTTP status, and log level.
11. In `14-rate-limiting.md`, add an acceptance criterion that clients MUST render `Retry-After` with a live countdown and MUST NOT fabricate the header when absent (matches `use-retry-after-countdown.ts`).
12. In `15-license-lifecycle.md`, add the "last active Admin protected" invariant and cite `AuthzLastAdminProtected`.
13. In `10-endpoints.md`, add columns "Log level on failure" and "Retry policy" per endpoint.
14. In `10-endpoints.md`, add the self-update endpoints from `17-self-update-endpoint.md` to the main table so it is exhaustive.
15. In `11-api-contracts/00-overview.md`, add a "JSON key casing" clause: all envelope keys and payload keys MUST be PascalCase (cites `.lovable/strictly-avoid/sa-031-pascal-case-data.md`).
16. In `11-api-contracts/00-overview.md`, add a "Request-Id propagation" clause: server MUST return `X-Request-Id`, client MUST include it in every error surface.
17. In `11-api-contracts/01-auth-contracts.md`, document the `/Auth/Refresh` reuse-detection response (`AuthRefreshReused`) with the exact envelope shape.
18. In `11-api-contracts/02-license-contracts.md`, document the idempotency-key contract for `POST /Licenses/{id}/Serials` (16-128 chars, 24h TTL) that `serial-issue-form.tsx` already enforces.
19. In `11-api-contracts/03-verification-contracts.md`, add explicit failure envelopes for each verify step and map to `ErrorCode`.
20. In `11-api-contracts/04-admin-contracts.md`, add the `/admin/users` and `/admin/users/{id}/role` contracts to match `19-user-management.md`.
21. Author `spec/21-app/21-json-envelope.md` codifying the universal envelope (Success, Data, Error, RequestId, RateLimit) with a Mermaid sequence for a 200 and a 429.
22. Add coding-guideline conformance sections to targeted files. See ./subtasks/02-spec-21-audit-remediation/SS-03-coding-guideline-conformance.md.
23. In `04-roles.md`, replace any inline role string list with a reference to the `UserRole` join table defined in `23-app-db/01-schema.md`, per `sa-041-separate-user-roles`.
24. In `05-license-categories.md`, restate the enum as a `LicenseCategory` table with `LicenseCategoryId` PK (per coding-guideline rule 5).
25. In `06-license-variations.md`, add a Mermaid ERD fragment showing `LicenseVariation` join table.
26. In `07-serial-generation.md`, add a worked example table for each `LicenseCategory` value.
27. In `08-hash-key.md` and `09-verify-key.md`, add explicit "no swallowed errors" clauses citing coding-guideline rule 6.
28. In `13-audit-logging.md`, add the AC-AUD-007 rename protocol as a first-class subsection (currently only in the final report).
29. In `13-audit-logging.md`, list mandatory audit fields (`ActorUserId`, `ActorRole`, `RequestId`, `ResourceType`, `ResourceId`, `Action`, `OccurredAt`) and forbid free-form action strings.
30. In `16-ui-surfaces.md`, add a per-route "Failure surfaces" column mapping each route to the `ErrorCode` values it must render.
31. In `16-ui-surfaces.md`, add the AppBuilder and EndUser update-banner behavior (matches `update-banner.tsx` and `lara-shell-role.ts`).
32. In `17-self-update-endpoint.md`, add the client MUST-abort clause on any SHA-256 or size mismatch, referencing the six covered branches in `tests/lara-self-update.test.ts`.
33. In `17-self-update-endpoint.md`, add the platform selection rule (asset MUST match caller platform or return `UpdateAssetNotFound`).
34. In `19-user-management.md`, add the "last active Admin cannot be demoted or deactivated" AC and cite `AuthzLastAdminProtected`.
35. In `19-user-management.md`, add the role-picker UX contract (matches `user-role-picker.tsx`).
36. In `20-observability.md`, add the client-side rules: mount `<Toaster />`, log via `use-lara-error-toast.ts`, never swallow, always show `X-Request-Id`.
37. In `20-observability.md`, add the `performRefresh` observability contract (transient vs fatal, log levels) matching `tests/lara-api-client.test.ts`.
38. Author `spec/21-app/22-testing-contract.md` listing the minimum Vitest coverage bar (transport, response parser, self-update integrity, retry banner, update banner, refresh 401) and require it in CI.
39. Cross-link `22-testing-contract.md` from `package.json` scripts documentation in `README.md`.
40. In `spec/23-app-db/01-schema.md`, add the `UserRole` table with `UserRoleId`, `UserId`, `AppRoleId`, `AssignedAt`, `AssignedBy`, and an FK diagram.
41. In `spec/23-app-db/01-schema.md`, add `GRANT`/RLS clauses per `.lovable/strictly-avoid/sa-042-server-side-authorization.md` even though the target is Laravel, framed as "authorization model requirements".
42. In `spec/23-app-db/01-erd.mmd`, add the `UserRole`, `LicenseCategory`, `LicenseVariation` tables so the ERD matches the schema doc.
43. In `spec/24-app-ui-design-system/03-components-and-states.md`, add the `RetryAfterBanner` and `UpdateBanner` components as first-class entries with states and a11y notes.
44. In `spec/24-app-ui-design-system/04-responsive-and-accessibility.md`, add the `aria-live="polite"` requirement for rate-limit and update banners.
45. In `spec/21-app/97-acceptance-criteria.md` (create if missing), fold all new ACs from steps 9-37 into a numbered list, one AC per line, each citing its source file.
46. In `spec/21-app/99-consistency-report.md` (create if missing), regenerate the cross-file consistency check now that steps 1-45 have landed, and require zero open findings.
47. Update `spec/25-app-audit/00-final-report.md` "Post-seal amendments" to link the new spec files (18, 21, 22) and the verdict page.
48. Update `.lovable/plan.md`: bump version to 0.78.0, mark M3 sub-items introduced by this plan, and add a "Spec remediation" milestone entry.
49. Update `CHANGELOG.md` and `RELEASE-NOTES.md` with a "Spec remediation from blind-AI audit" entry citing this plan.
50. Move this plan from `.lovable/plans/pending/02-spec-21-audit-remediation.md` to `.lovable/plans/completed/02-spec-21-audit-remediation.md` and flip `Status: pending` to `Status: completed`; do the same for each `SS-*.md` file whose goal is met.

## Verification

- `bun run build:dev` remains green after each spec-only edit (no code touched).
- Every new AC-ID appears in `spec/21-app/97-acceptance-criteria.md` and is cited from at least one leaf file.
- Every `ErrorCode` in `12-error-taxonomy.md` appears in `18-error-management-binding.md`.
- Every endpoint in `10-endpoints.md` appears in `18-error-management-binding.md`.
- Verdict page renders in preview via a manual `code--view` and reads plainly.
- Final scan: `rg "—" spec/21-app spec/23-app-db spec/24-app-ui-design-system` returns zero matches.

## Appended from prior pending tasks

- `.lovable/plans/pending/01-blind-ai-audit-folder-21.md` is sealed at step 50 per `spec/25-app-audit/00-final-report.md` and MUST be moved to `.lovable/plans/completed/01-blind-ai-audit-folder-21.md` as part of step 50 above.
- `.lovable/pending-tasks/task-001-reconcile-spec-numbering.md` remains open and is addressed indirectly by step 4 (version reset); a follow-up task will be filed if numbering issues persist after step 46.
- `.lovable/pending-issues/issue-001-spec-numbering-drift.md` remains open; step 4 closes it if the reset holds.
