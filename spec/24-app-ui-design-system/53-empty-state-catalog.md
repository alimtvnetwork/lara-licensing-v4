# Empty-State Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for every empty-state surface in the console: the copy, the illustration slot, the primary CTA, the permission-gated CTA behaviour, and the first-time-user vs filter-reset distinction.
**Owner:** Empty-state governance. Every list, table, and dashboard surface MUST cite one row in this catalog.
**Related:** [`16-route-shell-states.md`](./16-route-shell-states.md), [`24-component-table.md`](./24-component-table.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`33-route-blueprint-admin-overview.md`](./33-route-blueprint-admin-overview.md), [`34-route-blueprint-admin-licenses.md`](./34-route-blueprint-admin-licenses.md), [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md), [`36-route-blueprint-admin-users.md`](./36-route-blueprint-admin-users.md), [`37-route-blueprint-admin-quota-approvals.md`](./37-route-blueprint-admin-quota-approvals.md), [`38-route-blueprint-admin-features.md`](./38-route-blueprint-admin-features.md), [`39-route-blueprint-reseller-portal.md`](./39-route-blueprint-reseller-portal.md), [`40-route-blueprint-builder-console.md`](./40-route-blueprint-builder-console.md), [`41-route-blueprint-enduser-me.md`](./41-route-blueprint-enduser-me.md), [`52-icon-illustration-registry.md`](./52-icon-illustration-registry.md).

---

## 1. Purpose and scope

Defines the closed catalog of empty-state surfaces, distinguishes three empty-state VARIANTS (First-run, Filter-reset, Permission-scope), and pins per-surface copy + illustration + CTA + permission behaviour.

Out of scope: 403 / 404 / 500 terminal states (owned by `42-`); loading skeletons (owned by the coming loading catalog); zero-result Search states inside the Command Palette (owned by `32-`).

## 2. Three variants (closed set)

Every empty-state renders in exactly one of these variants; the runtime picks by inspecting the request state, NEVER by inspecting the rendered length alone.

- **First-run:** the underlying resource collection is empty for the caller's scope (no filters applied, no search). Warm copy plus an actionable primary CTA if the caller has the required permission; otherwise a permission-scope explainer.
- **Filter-reset:** the collection has rows but the current filter / search returned zero. Cold copy plus a `Reset filters` action linking to the same route with cleared search params. NEVER a primary create CTA (creation is unrelated to the empty filter result).
- **Permission-scope:** the caller cannot see any rows because RLS or permission scope blocks the entire collection. Neutral copy plus a `Request access` link to the docs / Admin contact; NEVER expose row counts, IDs, or leak the existence of hidden rows.

Determining the variant:

- Query returned success with a page of zero rows AND the URL carries no filter, no search: First-run.
- Query returned success with a page of zero rows AND at least one filter or search param is set: Filter-reset.
- Query returned `Forbidden` at the collection scope (not a specific row): Permission-scope. Query returned `Forbidden` at a specific row: render the row-level inline 403 per `42-` §5, NOT this catalog.
- Query IN FLIGHT: render the loading skeleton, NEVER an empty-state.
- Query FAILED (non-403): render the error surface with retry per `16-` §4, NEVER an empty-state (silent success is the wrong frame for a failure).

## 3. Layout

- Centred column, `max-width: 480px`.
- Illustration at the top (`--illustration-size-md: 160px` per `52-` §10), OPTIONAL; omitted on inline empty-states inside a Card.
- Heading: `--type-heading-md` (per `13-typography.md`), single line.
- Body: `--type-body-md`, up to two sentences.
- Primary CTA: a single Button per §6. Secondary link OPTIONAL.
- `role="status"` on the empty-state container so screen readers announce the copy after a successful navigation to an empty view.
- Full-surface variant (route body) vs inline variant (inside a Card). Inline variant omits the illustration and uses `--type-heading-sm`.

## 4. Copy voice

- Follow `27-content-voice.md` §3: direct, operational, no marketing enthusiasm.
- First-run copy states the resource type and the next concrete step.
- Filter-reset copy states the current filter is empty and offers to reset.
- Permission-scope copy is neutral and NEVER hints at hidden data ("You do not have access to this section." NOT "There are 12 licenses your role cannot see.").
- No exclamation marks. No emoji. No welcome copy ("Welcome to Licenses!"). No em dashes.
- Second-person address ("you" / "your"), never first-person ("we").

## 5. Empty-state catalog

Every route/surface in the app that can render zero rows MUST cite a row here. The `Illustration` column names an SVG under `src/assets/illustrations/`; a dash means no illustration (inline variant).

| Route / surface | First-run copy | First-run CTA | Filter-reset copy | Permission-scope copy | Illustration |
|---|---|---|---|---|---|
| `/admin/overview` | `No activity yet. Issue the first license to populate this dashboard.` | `Issue license` -> `/admin/licenses/new` (Admin only) | not applicable (dashboard has no filters) | `You do not have access to the admin overview.` | `empty-dashboard.svg` |
| `/admin/licenses` | `No licenses issued yet.` | `Issue license` -> `/admin/licenses/new` | `No licenses match this filter. Reset filters to see everything.` | `You do not have access to the license catalog.` | `empty-licenses.svg` |
| `/admin/licenses/:LicenseId/features` (per-license overrides) | `No feature overrides on this license. Overrides fall through to the tier default.` | `Add override` (inline, Admin only) | not applicable | `You do not have access to feature overrides.` | -- (inline) |
| `/admin/serials` | `No serials issued yet.` | `Issue serial` -> `/admin/serials/new` | `No serials match this filter.` | `You do not have access to the serial catalog.` | `empty-serials.svg` |
| `/admin/serials/:SerialId/audit` | `No audit events recorded on this serial.` | -- (audit is append-only, not user-created) | `No events match this filter.` | `You do not have access to serial audit history.` | -- (inline) |
| `/admin/users` | `No users invited yet.` | `Invite user` -> `/admin/users/new` | `No users match this filter.` | `You do not have access to user management.` | `empty-users.svg` |
| `/admin/users/:UserId/roles` | `No roles assigned. Assign at least one role for the user to sign in.` | `Assign role` (inline) | not applicable | `You do not have access to role assignment.` | -- (inline) |
| `/admin/quotas` | `No quota requests pending.` | -- (requests originate from resellers) | `No requests match this filter.` | `You do not have access to quota approvals.` | `empty-quota-requests.svg` |
| `/admin/features` | `No features defined yet.` | `Add feature` -> `/admin/features/new` | `No features match this filter.` | `You do not have access to the feature catalog.` | `empty-features.svg` |
| `/admin/features/:FeatureKey/licenses` (per-license overrides using this feature) | `No per-license overrides target this feature. Tier defaults apply everywhere.` | -- | `No overrides match this filter.` | `You do not have access to feature overrides.` | -- (inline) |
| `/admin/environments` | `No environments defined yet. Add production first.` | `Add environment` -> `/admin/environments/new` | not applicable (small catalog, no filters) | `You do not have access to environment management.` | -- (inline) |
| `/admin/tiers` | `No tiers defined yet.` | `Add tier` -> `/admin/tiers/new` | not applicable | `You do not have access to tier management.` | -- (inline) |
| `/reseller` (portal overview) | `No licenses issued under your account yet.` | `Issue license` -> `/reseller/licenses/new` (Reseller only) | not applicable | not applicable (route is behind the Reseller gate; unauthorised users hit `42-` §2) | `empty-reseller.svg` |
| `/reseller/licenses` | `No licenses issued yet.` | `Issue license` -> `/reseller/licenses/new` | `No licenses match this filter.` | not applicable (reseller scope guaranteed by gate) | -- (inline) |
| `/reseller/quota-requests` | `No requests submitted yet.` | `Request quota` -> `/reseller/quota-requests/new` | `No requests match this filter.` | not applicable | -- (inline) |
| `/builder` (console overview) | `No clients registered yet.` | `Register client` -> `/builder/clients/new` (Builder only) | not applicable | not applicable | `empty-builder.svg` |
| `/builder/clients` | `No clients registered yet.` | `Register client` -> `/builder/clients/new` | `No clients match this filter.` | not applicable | -- (inline) |
| `/builder/updates` | `No updates published yet.` | `Publish update` -> `/builder/updates/new` | `No updates match this filter.` | not applicable | -- (inline) |
| `/me` (end-user overview) | `No products linked to your account yet. Enter a serial to link one.` | `Verify by serial` (opens Dialog) | not applicable | not applicable | `empty-me.svg` |
| `/me/products` | `No products linked yet.` | `Verify by serial` (opens Dialog) | `No products match this filter.` | not applicable | -- (inline) |
| `/me/products/:LicenseId` (devices sub-table) | `No devices bound to this product yet. Install the client to bind the first device.` | `Install...` (opens Reveal card) | `No devices match this filter.` | not applicable | -- (inline) |
| `/me/devices` (global device list) | `No devices bound yet.` | -- (devices are bound via install; not directly created) | `No devices match this filter.` | not applicable | -- (inline) |
| Command Palette (see `32-`) | not applicable (owned by `32-`) | -- | -- | -- | -- |

- 22 catalog rows; every list / table surface in the app appears here.
- Row order matches Sidebar IA order per `14-navigation-ia.md`.

## 6. Primary CTA rules

- The First-run CTA MUST link to the create route (`/admin/licenses/new`, not a Dialog opened from the empty-state) so the surface is deep-linkable and refresh-safe.
- The CTA is rendered DISABLED (visible but not activatable) when the caller lacks the required permission per `40-permissions.md`; the disabled Button carries a Popover explaining the missing permission per `17-component-button.md` §9.
- Rendering the CTA HIDDEN based on permission BANNED (hidden actions make the app feel randomly incomplete); disabled + explanation is the correct pattern.
- The Filter-reset variant never carries a create CTA; the primary action is `Reset filters`, secondary is `Clear search`.
- The Permission-scope variant carries `Request access` linking to the docs URL configured at build time (`import.meta.env.VITE_DOCS_REQUEST_ACCESS_URL`); if the URL is unset the link is HIDDEN (not disabled; a link to nowhere is worse than no link).

## 7. Filter-reset behaviour

- `Reset filters` navigates to the same route path with all search params removed (`router.navigate({ to, search: {} })`).
- If the current URL was arrived at from a Command Palette suggestion (referrer state carries `From: 'CommandPalette'` per `32-`), the `Reset filters` copy adds a suffix `(or search again with Ctrl+K)`; otherwise the copy is unadorned.
- The Reset action MUST NOT invalidate query cache; the URL change re-runs the loader per TanStack Router `loaderDeps` and the correct data lands.
- The Filter-reset empty-state MUST arrive within one animation frame of the query resolving; a flash of the loading skeleton is acceptable but a flash of the First-run empty-state is BANNED (this misleads the user about their filter state).

## 8. Permission-scope behaviour

- The Permission-scope empty-state renders when the collection-level query returns `Forbidden`. It NEVER renders when the collection returns rows but a subset is filtered by RLS; that case is a normal, possibly empty list result and picks First-run or Filter-reset.
- The copy MUST be indistinguishable regardless of whether the collection has zero, one, or thousands of rows the caller cannot see; leaking cardinality is a security violation per `40-permissions.md` §11.
- The `Request access` link opens in a NEW tab (`target="_blank"` + `rel="noopener noreferrer"`) so the current URL is preserved for the admin to review.
- Telemetry: log `EmptyStatePermissionScope` with the route path and caller's Role, NEVER the caller's UserId or the target scope's identifiers.

## 9. Motion

- Empty-state fade-in follows `51-` §6 registry: `--motion-duration-md` + `--motion-easing-decelerate` + no distance (opacity only). Under reduced-motion Strategy A (instant).
- Illustration MUST NOT animate on mount or hover (per `52-` §10 and `51-` §12).
- The Reset-filters action's row disappearance uses the standard Table transition per `24-` §7, NOT a special empty-state exit motion.

## 10. Loading vs empty-state race

- If the query resolves faster than the skeleton's mount duration, the empty-state MAY render without a preceding skeleton flash; this is expected and correct.
- If the query is slower than the skeleton's max-hold (per the coming loading catalog), the skeleton stays visible until data lands; the empty-state MUST NEVER interleave with the skeleton (flicker BANNED).
- Handling: the surface renders the skeleton ONLY when `query.isPending` is true; the empty-state renders ONLY when `query.data` is defined and the data is zero-length. Rendering the empty-state on `query.isFetching` for a background refetch is BANNED (this would flash empty during a normal refresh).

## 11. Anti-patterns (BANNED)

1. First-run copy that welcomes the user or uses exclamation marks.
2. Emoji in empty-state copy or illustration.
3. Create CTA on a Filter-reset empty-state.
4. Reset-filters CTA on a First-run empty-state.
5. Permission-scope copy that leaks cardinality or IDs.
6. Hidden (not disabled) CTA when caller lacks permission.
7. Empty-state fired on `query.isFetching` (background refetch) instead of only on `query.data.length === 0`.
8. Empty-state fired on `query.isError` (must render error surface with retry per `16-`).
9. Empty-state fired for a per-row 403 (must render row-level inline 403).
10. Illustration animation.
11. Route paths in create CTAs that would break refresh (Dialogs disguised as pages).
12. Copy longer than two sentences.
13. Empty-state without `role="status"` on the container.
14. Filter-reset copy that offers to `Clear all data` or similar destructive phrasing.

## 12. Acceptance criteria

- AC-EMPTY-001: Every list / table surface in the app cites one row in §5.
- AC-EMPTY-002: Variant selection is decided by query state (`data.length === 0`, filters present, `Forbidden` scope), NEVER by rendered DOM length.
- AC-EMPTY-003: Permission-scope copy leaks no cardinality or IDs; the `Request access` link opens in a new tab.
- AC-EMPTY-004: First-run CTA links to a route, NEVER opens a Dialog (deep-link + refresh safe).
- AC-EMPTY-005: Disabled CTA on First-run permission-denied variant carries a Popover explanation per `17-` §9.
- AC-EMPTY-006: Fade-in motion follows `51-` §6 registry row; illustration does not animate.
- AC-EMPTY-007: `check-empty-state-catalog.py` (new linter §13) passes: every route rendering a list has an empty-state citation and no route is missing from the catalog.

## 13. Linter

New linter `linter-scripts/check-empty-state-catalog.py`:

- Scans `src/routes/**/*.tsx` for list-rendering routes (routes calling `useSuspenseQuery` returning arrays or paginated envelopes).
- Verifies each renders a component citing one §5 row via a `data-empty-state-id="..."` prop attribute.
- Fails on: list route with no empty-state citation; empty-state ID not in the §5 catalog; multiple citations per surface.
- Runs in CI and via `./linter-scripts/run.sh check-empty-state-catalog`.

## 14. Open items

- Zero-result Search states inside the Command Palette deferred to `32-command-registry.md` (short-form empty-state distinct from this catalog).
- Onboarding-first-run walkthrough overlay on empty dashboards deferred (paired with `51-` §16).
- Illustration set commission (five illustrations named in §5 with `.svg` filenames) deferred to a design commit; placeholder SVGs acceptable in v1 as long as they follow `52-` §10 rules.
