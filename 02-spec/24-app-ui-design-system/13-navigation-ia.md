# Navigation Information Architecture

**Version:** 0.24.0
**Updated:** 2026-07-22
**Status:** Active
**Category:** UI / Frontend
**AI Confidence:** High
**Ambiguity:** Low

## Keywords

`nav-ia` · `sidebar-tree` · `per-actor` · `permission-binding` · `hidden-vs-disabled`

## 1. Purpose

Freeze the sidebar navigation tree for every authenticated actor. `12-shell-layout.md` §3.3 owns the sidebar geometry; this file is the sole normative source for what appears in it, in what order, in which section group, and under which `PermissionKey` from [`../21-app/40-permissions.md`](../21-app/40-permissions.md). Every per-actor blueprint (Plan 06 Steps 21-28) MUST cite this file, not re-derive the tree from `../21-app/16-ui-surfaces.md`.

## 2. Structural Contract

The sidebar is composed of three section groups, always in this order:

1. **Primary** (workflow destinations): the actor's daily surfaces.
2. **Ops** (operational visibility): audit, abuse, updates, quota requests, activity log.
3. **Account** (personal + system): profile, theme, sign out. Pinned to the sidebar's inline-end-bottom via `margin-block-start: auto` per `12-shell-layout.md` §3.3.

Rules:

- Section headers use `--text-label` and `color: var(--muted-foreground)`.
- Section groups are separated by `--space-3` block gap.
- Items inside a group are separated by `--space-1` block gap.
- Item order inside a group is normative; do not sort alphabetically.
- Empty groups are omitted entirely (no header rendered).

## 3. Visibility Rule: Hidden vs Disabled vs Missing

For every item, exactly one of three states applies per role:

| State | Meaning | Rendering |
|-------|---------|-----------|
| Visible | The role holds the item's `PermissionKey` per §3 of [`../21-app/40-permissions.md`](../21-app/40-permissions.md). | Rendered as a link. `aria-current="page"` when active. |
| Hidden | The role does not hold the permission and never will by default. | Item is NOT rendered in the sidebar. Route is still guarded server-side. |
| Disabled | The role holds the permission but a runtime precondition blocks it (feature flag off, quota state pending, deferred `D` status per `../21-app/16-ui-surfaces.md`). | Item rendered with `aria-disabled="true"`, muted foreground, tooltip explains the precondition. Never used purely because the role lacks permission (that is Hidden). |

There is no fourth state. Items MUST NOT be shown as enabled and then produce a `Forbidden` on click; if the role cannot use the route, it is Hidden. This closes the UX row-scope leak fixed in v0.141.0.

## 4. Item Schema

Every sidebar item is a record with:

- `label`: sentence-case string, no ellipsis, max 22 characters.
- `route`: canonical route path from `../21-app/16-ui-surfaces.md`.
- `icon`: single outline icon from the pinned family.
- `permission`: one `PermissionKey` from `../21-app/40-permissions.md` §2, or `null` for personal routes (Account group only).
- `group`: `Primary` | `Ops` | `Account`.
- `status`: `C` (canonical), `A` (alias), `D` (deferred); mirrors `../21-app/16-ui-surfaces.md`.
- `disabledReason?`: shown as tooltip when Disabled.

## 5. Admin Tree

Landing: `/admin`.

### 5.1 Primary

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Overview | `/admin` | `LayoutDashboard` | `Admin.Overview.Read` | D |
| 2 | Resellers | `/admin/resellers` | `Building2` | `Resellers.Manage` | C |
| 3 | Users | `/admin/users` | `Users` | `Users.Manage` | C |
| 4 | Categories | `/admin/categories` | `Tags` | `LicenseCategories.Manage` | D |
| 5 | Licenses | `/admin/licenses` | `KeyRound` | `Licenses.Read` | C |
| 6 | Features | `/admin/features` | `SlidersHorizontal` | `Features.Manage` | C |

### 5.2 Ops

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Audit | `/admin/audit` | `ScrollText` | `AuditEvents.Read` | A |
| 2 | Abuse | `/admin/abuse` | `ShieldAlert` | `RateLimitBuckets.Read` | D |
| 3 | App updates | `/admin/app-updates` | `PackageCheck` | `AppUpdates.Manage` | C |

### 5.3 Account

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Profile | `/account/profile` | `UserCircle` | `Users.ReadSelf` | C |
| 2 | Sign out | (action) | `LogOut` | `null` | C |

## 6. Reseller Tree

Landing: `/reseller/$resellerId` where `$resellerId` equals `me.ResellerId` per [`../21-app/11-api-contracts/06-user-contracts.md`](../21-app/11-api-contracts/06-user-contracts.md). Foreign `$resellerId` values render `/forbidden` per v0.141.0.

### 6.1 Primary

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Overview | `/reseller/$resellerId` | `LayoutDashboard` | `Reseller.Overview.Read` | D |
| 2 | Packages | `/reseller/$resellerId/packages` | `Package` | `LicensePackages.Manage` | D |
| 3 | Licenses | `/reseller/$resellerId/licenses` | `KeyRound` | `Licenses.Read` | C |
| 4 | Serials | `/reseller/$resellerId/serials` | `Barcode` | `Serials.Lookup` | C |
| 5 | Quota | `/reseller/$resellerId/quota-requests` | `Gauge` | `QuotaRequests.Submit` | C |

### 6.2 Ops

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Activity | `/reseller/$resellerId/activity` | `ScrollText` | `AuditEvents.ReadOwn` | D |

### 6.3 Account

Same as Admin §5.3.

## 7. AppBuilder Tree

Landing: `/builder`.

### 7.1 Primary

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Overview | `/builder` | `LayoutDashboard` | `Builder.Overview.Read` | D |
| 2 | Clients | `/builder/clients` | `AppWindow` | `Clients.Manage` | D |
| 3 | Keys | `/builder/keys` | `KeySquare` | `ClientKeys.Manage` | D |
| 4 | Updates | `/builder/updates` | `PackageCheck` | `AppUpdates.ReadOwn` | C |

### 7.2 Ops

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Logs | `/builder/logs` | `ScrollText` | `AuditEvents.ReadOwn` | D |

### 7.3 Account

Same as Admin §5.3.

## 8. EndUser Tree

Landing: `/app`.

### 8.1 Primary

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Products | `/app/products` | `LayoutGrid` | `EndUser.Products.Read` | D |
| 2 | Devices | `/app/devices` | `MonitorSmartphone` | `EndUser.Devices.Read` | D |

### 8.2 Ops

Empty for `EndUser` in v1; the group is not rendered.

### 8.3 Account

| Order | Label | Route | Icon | PermissionKey | Status |
|:-----:|-------|-------|------|---------------|:------:|
| 1 | Profile | `/account/profile` | `UserCircle` | `Users.ReadSelf` | C |
| 2 | Update | `/app/update` | `Download` | `AppUpdates.ReadOwn` | C |
| 3 | Sign out | (action) | `LogOut` | `null` | C |

## 9. Multi-Role Users

A user MAY hold more than one role (for example `Admin` + `Reseller`). The sidebar renders the tree for the user's **active portal**, resolved by the route prefix (`/admin/*`, `/reseller/*`, `/builder/*`, `/app/*`). A portal switcher in the topbar (right of breadcrumb, left of search) lists only server-verified roles. Switching portals navigates to that portal's landing route; it never toggles authorization client-side. This preserves the v0.62.0 rule.

## 10. Active-Route Contract

- Exactly one item carries `aria-current="page"`, matched by route prefix (deepest match wins).
- Active item styling: primary edge marker `4px` inline-start with `background: var(--primary)`, foreground `var(--primary)`, background `color-mix(in oklab, var(--primary) 12%, transparent)`.
- Hover surface for non-active items follows `10-spacing-and-rhythm.md` §7.
- Keyboard focus ring per `08-token-registry.md` §9 always visible.

## 11. Ops Group Rules

- Every Ops item shows a numeric badge when the permission's underlying resource has unread/pending items (e.g. pending `QuotaRequests` for Admin), refreshed every 60s. Badge count is `tabular-nums`, `--text-label`, background `color-mix(in oklab, var(--destructive) 15%, transparent)` for attention, or `--muted` for informational.
- Ops badges NEVER animate on update. Color and text change; no pulse, no shake.

## 12. Empty Tree Behavior

If a role ends up with all items Hidden (bug or misconfigured role), the sidebar renders only the Account group and the main region shows a canonical "No permissions granted yet" empty state with a support contact. This is a bug-surface, not a UX pattern; it must fire the `AuthzNoPermissions` log line at `WARN` level.

## 13. Verification

- AC-ADS-041: every sidebar item cites a real `PermissionKey` from `../21-app/40-permissions.md` §2.
- AC-ADS-042: item order matches the tables in §5-§8.
- AC-ADS-043: Hidden vs Disabled distinction preserved (no Forbidden on click for role-lacking items).
- AC-ADS-044: portal switcher lists only server-verified roles.
- AC-ADS-045: exactly one `aria-current="page"` on any authenticated route.

```bash
python3 linter-scripts/check-spec-cross-links.py
```

## Cross-References

- [Shell and Navigation](./02-shell-and-navigation.md)
- [Shell Layout](./12-shell-layout.md)
- [Token Registry](./08-token-registry.md)
- [Typography Scale](./09-typography-scale.md)
- [UI Surfaces](../21-app/16-ui-surfaces.md)
- [Permissions Catalog](../21-app/40-permissions.md)
- [User Contracts](../21-app/11-api-contracts/06-user-contracts.md)
