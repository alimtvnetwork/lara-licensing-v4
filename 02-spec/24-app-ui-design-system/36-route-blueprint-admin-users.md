# Route Blueprint: Admin Users (`/admin/users`, `/admin/users/:UserId`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Fourth route blueprint; extends the template established by [`33-route-blueprint-admin-overview.md`](./33-route-blueprint-admin-overview.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), and [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md). Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Admin Users list and detail routes plus the role-assignment Sheet.
**Related:** [`13-navigation-ia.md`](./13-navigation-ia.md), [`14-breadcrumbs-and-page-header.md`](./14-breadcrumbs-and-page-header.md), [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`20-component-choice.md`](./20-component-choice.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`32-command-registry.md`](./32-command-registry.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/19-user-management.md`](../21-app/19-user-management.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

`/admin/users` is the operational Table for platform users (list, filter, deep-link) and `/admin/users/:UserId` is the canonical detail route (identity, role assignments, permission grants, invite / deactivate destructive actions, session and audit trail).

Out of scope: end-user self-service (owned by `/me`); reseller-side user management is deferred until the reseller portal blueprint (Step 36).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/admin/users` | `src/routes/_authenticated/admin/users/index.tsx` | `Admin.Users.Manage` | `ensureQueryData(usersListQuery(searchParams))` |
| `/admin/users/:UserId` | `src/routes/_authenticated/admin/users/$UserId.tsx` | `Admin.Users.Manage` | `ensureQueryData(userDetailQuery({ UserId }))` + `ensureQueryData(userRolesQuery({ UserId }))` + `ensureQueryData(userAuditQuery({ UserId }))` in parallel via `Promise.all` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission model: `Admin.Users.Manage` is a single closed-set key per `40-permissions.md`; sub-actions (`Admin.Roles.Assign`, `Admin.Permissions.Grant`, `Admin.Permissions.Revoke`) are checked at Sheet/Dialog invocation per `32-command-registry.md` §7 permission-hidden rule.
- Permission denial: 403 terminal card per [`16-route-shell-states.md`](./16-route-shell-states.md) §4, NEVER a silent redirect.
- 404: `notFoundComponent` renders the 404 terminal card citing the `UserId` via `Route.useParams()`.
- Head metadata: list `title = "Users - Lara Licensing"`; detail `title = \`User ${DisplayName} - Lara Licensing\``; no `og:image`.
- Search params (list, TanStack `validateSearch`): `q` (string, `≤ 128` chars, matches email OR display-name prefix), `Role` (enum from `04-roles.md`: `Admin` / `Reseller` / `Builder` / `EndUser`), `Status` (enum: `Active` / `Invited` / `Disabled`), `PageIndex` (int `≥ 0`, default 0), `PageSize` (closed set `{25, 50, 100}`, default 25), `Sort` (closed set `{ CreatedAtDesc, CreatedAtAsc, LastSignInDesc, EmailAsc }`, default `CreatedAtDesc`).

## 3. Layout

List:

```
Shell (12) > PageHeader (H1 "Users", RightActions: Refresh, Invite user...)
  > Filter bar (SearchInput + FilterChips: Role, Status)
  > Table (24) with RecordCard fallback below 720 px container width
  > Pagination footer (URL-bound)
```

Detail:

```
Shell > Breadcrumb (Admin > Users > <DisplayName>)
  > PageHeader (H1 "<DisplayName>", subhead: Status Badge + Primary-Role Badge, RightActions: Assign roles..., Disable... | Reactivate...)
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: Identity card (Email, DisplayName, CreatedAt, LastSignInAt, InvitedBy, InvitedAt) + Roles section (list of role Badges with per-role Revoke Menu action) + Permission grants section (list of key + scope + Revoke Menu action)
      Column B: Recent Sessions Table (compact) + Audit Trail Table (compact)
```

## 4. PageHeader

- List H1: `Users` (single `<h1>` per `28-a11y-conformance.md` §5).
- Detail H1: `DisplayName` if non-empty, else `Email` per `27-content-voice.md` §4 (empty-DisplayName fallback rule).
- Right actions (list): `Refresh` icon Button (invalidates `["Admin.Users.List"]`); `Invite user...` primary Button binding to `Admin.Users.Invite` command per `32-command-registry.md` §7 (opens the Invite Dialog).
- Right actions (detail): `Assign roles...` secondary Button (opens the role-assignment Sheet per §9); `Disable...` OR `Reactivate...` Button (destructive when Disable, non-destructive when Reactivate). The action is mutually exclusive with the current `Status`: only one is shown.
- XS/SM collapse: right actions collapse to a MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contract (list)

- Columns (pinned order): `User` (Email + DisplayName stacked), `Status`, `Role` (primary role Badge, additional roles rendered as `+N` chip that opens a Popover per `22-component-menu-popover.md`), `LastSignInAt`, `CreatedAt`, `Actions`.
- `User` cell is a full-cell `<Link>` to `/admin/users/:UserId`, NEVER a Button; whole-row click BANNED per `24-component-table.md` §6.
- `Status`, `Role` render as Badges with glyphs per `25-component-badge-status.md` §5; color NEVER the sole cue.
- Dates render via `src/lib/format-date.ts` UTC tooltip; relative time BANNED as primary.
- `Actions` cell: MoreHorizontal Menu with `View`, `Assign roles...`, `Disable...` | `Reactivate...` (destructive filtered by permission per `32-` §5).
- Selection: none in v1.

## 6. Filtering, search, sort

- SearchInput binds to `q`, 300 ms debounce, server-side match on email OR display-name prefix. Values (email, name) NEVER logged.
- FilterChips: Select-backed enums (`19-component-select.md`), one search param each. Clear-all preserves `PageSize` and `Sort`.
- Empty results render Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters`, **First-run** variant when the collection is empty for the caller scope, **Permission-scope** variant when RLS forbids the whole collection; legacy `15-empty-error-loading-catalog.md` §4 kept as pointer) with Clear filters action, NEVER a blank Table body.

## 7. Route states (list AND detail)

Identical seven-row state table to `34-route-blueprint-admin-licenses.md` §7 and `35-route-blueprint-admin-serials.md` §7 (Loading skeleton, Loaded, Empty, 404 terminal, 403 terminal, Rate limited `RetryAfterBanner`, Error inline Banner with `ErrorCode`+`RequestId`+Retry calling `router.invalidate()` AND `reset()`). Partial failure on detail: the failing sub-Table renders its own error Banner AND a summary Toast; fallback-to-empty is BANNED.

## 8. Data contract

- Query keys: `["Admin.Users.List", <serializedSearchParams>]`, `["Admin.Users.Detail", UserId]`, `["Admin.Users.Roles", UserId]`, `["Admin.Users.Audit", UserId]`. One `invalidateQueries(["Admin.Users"])` refreshes the whole area.
- Every mutation (Invite, Disable, Reactivate, Roles.Assign, Roles.Revoke, Permissions.Grant, Permissions.Revoke) invalidates `["Admin.Users"]` in ONE call; role changes ALSO invalidate `["Admin.Overview"]` (KPI Slots).
- `useSuspenseQuery` in components; loader uses `ensureQueryData`. `useQuery`+`isLoading` BANNED.
- Optimistic role or status mutation BANNED (server is authority; envelope carries `EffectiveAt`).
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog/Sheet open per `21-component-dialog.md` §5.
- Email addresses render in full only on detail identity card; list Table stacks DisplayName over a domain-masked email (`j***@example.com`) by default, with an `admin-only` full-email reveal per row gated by `27-content-voice.md` §9 redaction rule.

## 9. Sheet: Assign roles

- The role-assignment surface is a right-hand Sheet per `29-responsive-matrix.md` (bottom Sheet at XS/SM), NOT a Dialog, because it composes multiple role selections plus per-role scope Fields.
- Layout: closed-set list of platform roles from `04-roles.md` rendered as Checkboxes per `20-component-choice.md`; each checked role reveals its scope Fields (Reseller role requires `ResellerId` Select, Builder role requires `ClientId` Select). Unchecked role is not persisted.
- Save action calls `Admin.Roles.Assign` command per `32-command-registry.md` §7. Diff (added / removed roles) is computed client-side but the server MUST re-diff against current state; the client-side diff is display only.
- Emits `UserRolesAssignConfirmed` and `UserRolesAssignExecuted`|`Failed` with `UserId`, `AddedRoles`, `RemovedRoles`, `IdempotencyKey`, `ErrorCode`, `RequestId` per `22-log-line-contract.md`.
- Sheet close without Save: emits `UserRolesAssignCancelled` (no state change).

## 10. Dialogs invoked

- `Invite user...` Dialog: form with `Email` Field (RFC-5321 validation per `18-component-input.md` §6), `DisplayName` Field, `Role` Select (single primary role at invite time; additional roles assigned post-acceptance via Sheet). Non-destructive. `Idempotency-Key` bound at Dialog open. Emits `UserInviteConfirmed` and `UserInviteExecuted`|`Failed`.
- `Disable...` Dialog: destructive per `21-component-dialog.md` §5 phrase-typing (user types the target Email exactly). Emits `UserDisableConfirmed` and `UserDisableExecuted`|`Failed`. Silent deactivate BANNED.
- `Reactivate...` Dialog: non-destructive single-Button confirmation. Emits `UserReactivateExecuted`|`Failed`.
- `Revoke role` / `Revoke permission grant`: destructive per `21-component-dialog.md` §5 phrase-typing (user types the role or permission key exactly). Silent revoke BANNED.

## 11. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (list): Sidebar > Breadcrumb > Refresh > Invite > SearchInput > FilterChips > Table headers > rows > Pagination.
- Table row focus reveals full-row 2 px `--ring` outline per `28-a11y-conformance.md` §4.
- Sort direction announced via `aria-sort` on the `<th>` AND a glyph.
- Sheet: focus trapped inside, initial focus on the first Checkbox, Escape closes only when the Sheet is unmodified (dirty state prompts a discard-changes Dialog per `21-component-dialog.md` §7).
- Email masking on the list carries `aria-label="Email, masked"` so screen readers never announce the masked form as the address.

## 12. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Admin.Users.List"` or `"Admin.Users.Detail"`, `A11yViolations: 0`, `LoadDurationMs`.
- `TableFilterApplied` / `TableSortChanged` with field VALUES NEVER logged.
- `UserInvite*`, `UserDisable*`, `UserReactivate*`, `UserRolesAssign*`, `UserPermissionGrant*`, `UserPermissionRevoke*` with `UserId`, `IdempotencyKey`, `ErrorCode`, `RequestId`.
- Email, DisplayName, role scope IDs NEVER logged as values (IDs are logged, human-readable strings are not).

## 13. Anti-patterns (BANNED)

1. Component-local pagination or filter state.
2. Row-level click (whole `<tr>` as a link).
3. `useQuery`+`isLoading` initial gate.
4. Silent 403 or silent 404.
5. Optimistic Invite / Disable / Reactivate / role change.
6. Disable / role-revoke / permission-revoke without phrase-typing Dialog.
7. Role-assignment inside a Dialog (must be a Sheet).
8. Rendering unmasked email in the list Table by default.
9. Logging email or DisplayName VALUES.
10. Relative time as primary rendering.
11. Client-only search matching.
12. Per-card refresh Buttons.

## 14. Acceptance criteria

- AC-ROUTE-USERS-001: Both routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-USERS-002: All list state (filters, sort, pagination) round-trips through the URL.
- AC-ROUTE-USERS-003: Below 720 px container width, the list renders as RecordCards.
- AC-ROUTE-USERS-004: Disable, role Revoke, and permission Revoke all route through phrase-typing Dialogs and emit Confirmed + Executed|Failed logs.
- AC-ROUTE-USERS-005: Role-assignment surface is a Sheet (bottom Sheet at XS/SM), never a Dialog.
- AC-ROUTE-USERS-006: One `invalidateQueries(["Admin.Users"])` refreshes list, detail, roles, audit; role changes ALSO invalidate `["Admin.Overview"]`.
- AC-ROUTE-USERS-007: List emails render domain-masked by default with an audited per-row reveal action.
- AC-ROUTE-USERS-008: Axe zero `serious`/`critical` on both routes at 360/768/1440; `RoutePresented` fires with `A11yViolations: 0` on Loaded and Empty.

## 15. Open items (for follow-up commits)

- Bulk deactivate / bulk role assign deferred to v2; when added, MUST route through a Sheet and preserve per-user phrase-typing on destructive rows.
- SSO / SCIM provisioning surface deferred; when added, gets its own blueprint file.
