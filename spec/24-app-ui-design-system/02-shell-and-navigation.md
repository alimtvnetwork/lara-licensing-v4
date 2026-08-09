# Shell and Navigation

**Version:** 0.23.0
**Updated:** 2026-07-15
**Status:** Active
**Category:** UI / Frontend

## 1. Public Shell

Public routes use a compact header with the LaraLicensingV1 wordmark, Verify, Sign in, and Create account. `/` presents the product name as the H1, a literal licensing-management offer, and a relevant product image or application screenshot. Hero content is not placed in a card and leaves the next content band visible.

Authentication and `/verify` use a centered single-column work area with a maximum width of 480px. Legal and support links remain visible beneath the form.

## 2. Authenticated Shell

The authenticated shell consists of:

1. A 240px desktop sidebar with actor-specific navigation.
2. A 56px top bar with page context, global search when applicable, theme control, and account menu.
3. A main region with breadcrumb, H1, optional page actions, and route content.

The sidebar contains only routes available to the resolved role from `spec/21-app/16-ui-surfaces.md`. The current route uses `aria-current="page"`, a visible label, icon, and primary edge marker.

## 3. Actor Navigation

| Actor | Primary items |
|-------|---------------|
| Admin | Overview, Resellers, Users, Categories, Licenses, Audit, Abuse. |
| Reseller | Overview, Packages, Licenses, Serials. |
| AppBuilder | Overview, Clients, Keys, Logs. |
| EndUser | Products, Devices, Profile. |

Role switching is not displayed unless the authenticated user holds multiple server-verified roles. It never changes authorization only on the client.

## 4. Page Header

Each route starts with one H1 and an optional one-sentence description. Place one primary action at the right edge on desktop and below the title on mobile. Secondary commands use an overflow menu when more than two are present.

Detail routes show breadcrumbs and a stable identifier with a copy command. Destructive transitions such as Revoke appear outside the primary action cluster.

## 5. Mobile Navigation

Below 768px, the sidebar becomes a modal sheet opened by a menu icon in the top bar. Opening moves focus into the sheet, Escape closes it, and closing restores focus to the trigger. The active route and all role-allowed destinations remain available.

## 6. Route State

- Loading preserves shell dimensions and uses row or field skeletons.
- Route failure renders the canonical `ErrorCode`, message, and `RequestId` with a retry command.
- Forbidden renders `/forbidden`; it does not leave stale protected content visible.
- Empty states include a factual reason and only a permitted next action.

## Cross-References

- [UI surfaces](../21-app/16-ui-surfaces.md)
- [Error taxonomy](../21-app/12-error-taxonomy.md)
- [Responsive and accessibility](./04-responsive-and-accessibility.md)