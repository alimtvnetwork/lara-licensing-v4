# Route Blueprint: Builder Console (`/builder`, `/builder/clients`, `/builder/clients/:ClientId`, `/builder/updates`)

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Eighth route blueprint; extends the template established by `33-`..`39-`. Every deviation in runtime code MUST be either (a) reflected back into this file in the same commit, or (b) rejected by review.
**Owner:** Single normative source for the Builder-role console (client credentials, key rotate / revoke destructive flows, updates publish / retract).
**Related:** [`15-empty-error-loading-catalog.md`](./15-empty-error-loading-catalog.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`18-component-input.md`](./18-component-input.md), [`19-component-select.md`](./19-component-select.md), [`20-component-choice.md`](./20-component-choice.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-status.md`](./25-component-badge-status.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`30-kpi-and-chart-catalog.md`](./30-kpi-and-chart-catalog.md), [`32-command-registry.md`](./32-command-registry.md), [`35-route-blueprint-admin-serials.md`](./35-route-blueprint-admin-serials.md), [`39-route-blueprint-reseller-portal.md`](./39-route-blueprint-reseller-portal.md), [`../21-app/04-roles.md`](../21-app/04-roles.md), [`../21-app/08-hash-key.md`](../21-app/08-hash-key.md), [`../21-app/17-self-update-endpoint.md`](../21-app/17-self-update-endpoint.md), [`../21-app/18-publishing-powershell.md`](../21-app/18-publishing-powershell.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md), [`../21-app/35-security-events.md`](../21-app/35-security-events.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md), [`54-loading-state-catalog.md`](./54-loading-state-catalog.md), [`56-copy-dictionary.md`](./56-copy-dictionary.md).

---

## 1. Purpose and scope

Builder-role self-service surface. RLS-scoped to the caller's `BuilderId`; server MUST reject cross-builder access with `404 ClientNotFound` (never `403`) to prevent enumeration, mirroring `39-` §2. UI MUST NEVER show a `BuilderId` selector.

Routes:

- `/builder` overview (KPIs, recent Update activity, current signing-key age).
- `/builder/clients` list of client credential pairs (`ClientId` + hash-of-`ClientSecret`).
- `/builder/clients/:ClientId` detail (metadata, secret rotation, revoke).
- `/builder/updates` publish / retract queue per `17-self-update-endpoint.md` and `18-publishing-powershell.md`.

Out of scope: end-user device fingerprints (owned by `41-route-blueprint-enduser-me.md`, Step 38); Admin approval flows for cross-builder promotion (deferred).

## 2. Route wiring

| Route | File | Permission | Loader |
|---|---|---|---|
| `/builder` | `src/routes/_authenticated/builder/index.tsx` | `Builder.Console.View` | `ensureQueryData(builderOverviewQuery())` + `ensureQueryData(builderKeyHealthQuery())` + `ensureQueryData(builderRecentUpdatesQuery())` via `Promise.all` |
| `/builder/clients` | `src/routes/_authenticated/builder/clients/index.tsx` | `Builder.Clients.Read` | `ensureQueryData(builderClientsListQuery(searchParams))` |
| `/builder/clients/:ClientId` | `src/routes/_authenticated/builder/clients/$ClientId.tsx` | `Builder.Clients.Read` | `ensureQueryData(builderClientDetailQuery({ ClientId }))` + `ensureQueryData(builderClientAuditQuery({ ClientId }))` |
| `/builder/updates` | `src/routes/_authenticated/builder/updates/index.tsx` | `Builder.Updates.Read` | `ensureQueryData(builderUpdatesListQuery(searchParams))` |

- Layout parent: `_authenticated` gate per `12-shell-layout.md`.
- Permission keys per `40-permissions.md` §1. Denial renders 403 terminal card per `16-route-shell-states.md` §4.
- Cross-builder client lookup MUST return `404 ClientNotFound`, not `403`, per `12-error-taxonomy.md` scope-hiding.
- Invalid-format `ClientId` (must be UUID v4 per `26-route-dto-index.md`) renders 404 card, NEVER 400.
- Head metadata: overview `title = "Builder console - Lara Licensing"`; clients list `title = "Clients - Lara Licensing"`; detail `title = \`Client ${ShortId} - Lara Licensing\``; updates `title = "Updates - Lara Licensing"`; no `og:image`.
- Search params (clients list, `validateSearch`): `q` (string ≤ 128 chars, server-side prefix match on `Label` OR `ClientId` short-id), `Status` (enum `Active`/`Revoked`), `PageIndex`, `PageSize` closed set `{25, 50, 100}`, `Sort` closed set default `CreatedAtDesc`.
- Search params (updates list, `validateSearch`): `q` (prefix match on `Version` OR `ChannelId`), `Channel` (enum from `18-publishing-powershell.md` closed set: `Stable`/`Beta`/`Insider`), `Status` (enum: `Draft`/`Published`/`Retracted`), `PageIndex`, `PageSize`, `Sort` closed set default `PublishedAtDesc`.

## 3. Layout

Overview:

```
Shell > PageHeader (H1 "Builder console", subhead: BuilderOrgName + PlanTier,
                    RightActions: New client..., Publish update...)
  > KPI strip (four cards): Builder.ClientsActive, Builder.UpdatesPublishedLast30d,
                            Builder.OldestActiveKeyAgeDays (threshold 180 d amber, 365 d red per 35-security-events.md),
                            Builder.RetractedUpdatesLast30d
  > Two-column band (container-query `>= 960 px`, single column below):
      Column A: KeyHealth card (per-client age with color-plus-glyph tone per 25-)
      Column B: RecentUpdates Table (compact 5 rows, "View all" <Link>)
```

Clients list mirrors `39-` §3 with a builder-scoped Table.

Detail:

```
Shell > Breadcrumb (Builder > Clients > <ShortId>)
  > PageHeader (H1 "<ClientLabel>", subhead: Status Badge + ClientId short + CreatedAt + LastRotatedAt,
                RightActions: Edit label..., Rotate secret..., Revoke client...)
  > Two-column band:
      Column A: Metadata card (Label editable, Description editable, ClientId immutable, CreatedAt, LastRotatedAt,
                               SecretExpiresAt, AllowedScopes read-only chip list)
                SecretPreview card (hash-only per 08-hash-key.md; the plaintext ClientSecret is shown ONCE at rotation)
      Column B: Audit Trail Table (compact, builder-scope subset; cross-builder rows filtered server-side)
```

Updates:

```
Shell > PageHeader (H1 "Updates", RightActions: Publish update...)
  > Filter bar (SearchInput + FilterChips: Channel, Status)
  > Table with columns: Version, Channel, Status, PublishedAt, RetractedAt, Actions
  > Pagination footer
```

## 4. PageHeader actions

- `New client...`: opens the Create Client Dialog. Permission `Builder.Clients.Create`.
- `Publish update...`: opens the Publish Update Dialog. Permission `Builder.Updates.Publish`.
- `Edit label...` (detail): non-destructive Dialog editing Label + Description only.
- `Rotate secret...` (detail): destructive per `21-component-dialog.md` §5 phrase-typing (user types the client `Label` exactly, NOT `ClientId`, because Label is human-recognisable and reduces mis-rotation risk). Result renders the NEW plaintext `ClientSecret` in a Reveal card ONCE with a mandatory `I have copied and stored the secret` confirmation Checkbox before the Dialog can be closed; on close the plaintext is discarded client-side and NEVER re-retrievable (server stores hash only per `08-hash-key.md`).
- `Revoke client...` (detail): destructive phrase-typing (user types the client `Label`) with mandatory `Reason` Field ≤ 240 chars. Copy MUST state that revocation immediately invalidates all sessions using this credential and cannot be undone (a new client must be created).
- `Retract...` (updates list Actions Menu, Published rows only): destructive phrase-typing (user types the `Version` exactly per `18-publishing-powershell.md`) with mandatory `Reason`. Copy MUST state that retraction stops new downloads but does NOT remove installations already in the field.
- Rendered actions are permission-hidden per `32-` §5. XS/SM collapse to MoreHorizontal Menu per `29-responsive-matrix.md`.

## 5. Table contracts

Clients list, columns (pinned order): `Client` (Label + short-id stacked), `Status`, `CreatedAt`, `LastRotatedAt`, `SecretAgeDays` (right-aligned integer, tone amber >= 180 d, red >= 365 d per §3 KeyHealth thresholds), `Actions`. `Client` cell full-cell `<Link>`; whole-row click BANNED.

Updates list, columns (pinned order): `Version`, `Channel`, `Status`, `PublishedAt`, `RetractedAt`, `DownloadCount` (right-aligned integer, `-` when Draft), `Actions`. `Version` cell full-cell `<Link>` to a future detail route (deferred, §14); until then the `<Link>` is inert `<span>` styled as a link (BANNED after v1); v1 renders `Version` as plain text with an Actions Menu carrying `Retract...` when Published. Correction: for v1 the `Version` cell is plain text (NO full-cell link) because there is no update-detail route yet; adding a route later opens the `<Link>` per §14.

Cross-column rules per `24-` §5: Dates via `format-date.ts` UTC tooltip, relative-time-as-primary BANNED.

## 6. Filtering, search, sort

- SearchInput binds `q`, 300 ms debounce, server-side. Client `Label`, update `Version`, and `ChannelId` values match by prefix (case-insensitive on Label, case-sensitive on Version because SemVer prefix `v1.0` is distinct from `V1.0`).
- Empty results render Empty catalog card per [`53-empty-state-catalog.md`](./53-empty-state-catalog.md) §3 (**Filter-reset** variant with `Clear filters`). Empty KeyHealth (**First-run** variant per `53-` §3) (no active clients) renders the empty catalog card copy `No active clients yet. Create your first client to publish updates.` per `27-` §6 empty-state voice.

## 7. Route states

Identical seven-row state table to `34-` §7. Partial failure on Overview: if `builderKeyHealthQuery` fails, KPI Cards 3 renders Skeleton-error state and `New client...` / `Publish update...` remain ENABLED because they do not depend on key-health data; if `builderRecentUpdatesQuery` fails, the RecentUpdates Table renders its own Banner AND `Publish update...` remains ENABLED (publishing does not depend on the history). Fallback-to-zero on any KPI BANNED.

## 8. Data contract

- Query keys: `["Builder.Overview"]`, `["Builder.KeyHealth"]`, `["Builder.RecentUpdates"]`, `["Builder.Clients.List", <serializedSearchParams>]`, `["Builder.Clients.Detail", ClientId]`, `["Builder.Clients.Audit", ClientId]`, `["Builder.Updates.List", <serializedSearchParams>]`. NO key carries a `BuilderId` value.
- Rotate Secret invalidates `["Builder.Clients.Detail", ClientId]`, `["Builder.KeyHealth"]`, and `["Builder.Overview"]` in ONE call. Revoke Client also invalidates `["Builder.Clients"]` (prefix). Publish Update invalidates `["Builder.Updates"]` (prefix) AND `["Builder.RecentUpdates"]` AND `["Builder.Overview"]`. Retract invalidates the same set.
- `useSuspenseQuery` + `ensureQueryData`; `useQuery`+`isLoading` BANNED.
- Optimistic mutations BANNED.
- All mutations send `Idempotency-Key` per `29-idempotency-lifecycle.md`, generated at Dialog open. Concurrent double-rotate resolves as one apply + one `409 ClientSecretAlreadyRotated`; the loser Dialog renders the loser plaintext state: it does NOT render the winner's new plaintext (which is available only to the winner via its own Dialog result). This is a security requirement because the loser has no proof it authorised the winning rotation.
- Concurrent-editor guard: detail loader returns `ClientEtag`; every mutation MUST send `If-Match: <ClientEtag>`; `412` renders inline refresh Banner.

## 9. Dialogs invoked

- `New client...` Dialog: form with `Label` Field ≤ 120 chars mandatory, `Description` Field ≤ 480 chars optional, `AllowedScopes` multi-Select from closed set per `40-permissions.md`. Server returns the freshly generated `ClientId` + plaintext `ClientSecret` in the response envelope ONCE; the Dialog then swaps into the Reveal state (see §4 rotate rules for the copy-confirmation Checkbox). Emits `ClientCreateConfirmed` and `ClientCreateExecuted`|`Failed`.
- `Rotate secret...` Dialog: destructive phrase-typing (Label); Reveal state after commit; the plaintext is NEVER logged and NEVER placed in a query cache. Emits `ClientRotateSecretConfirmed` and `ClientRotateSecretExecuted`|`Failed` with `SecretFingerprint` (SHA-256 8-char, server-echoed) but the VALUE never.
- `Revoke client...` Dialog: destructive phrase-typing (Label) + mandatory `Reason`; copy states immediate session invalidation and no undo per §4. Emits `ClientRevokeConfirmed` and `ClientRevokeExecuted`|`Failed`; `ReasonFingerprint` IS logged, `Reason` VALUE NEVER.
- `Publish update...` Dialog: form with `Version` Field (SemVer regex `^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$` per `18-publishing-powershell.md`), `Channel` Select (closed set), `Payload` upload Field with SHA-256 checksum displayed on hash-in-worker (never on main thread) per `17-self-update-endpoint.md`, `ReleaseNotes` Field. Emits `UpdatePublishConfirmed` and `UpdatePublishExecuted`|`Failed`.
- `Retract...` Dialog (updates list): destructive phrase-typing (`Version`) + mandatory `Reason`; copy states retraction is one-way and existing installations remain. Emits `UpdateRetractConfirmed` and `UpdateRetractExecuted`|`Failed`.

## 10. A11y

- Single `<h1>` per route; `<main>` landmark; skip-link first tab stop.
- Tab order (detail): Sidebar > Breadcrumb > Edit label > Rotate secret > Revoke client > Metadata Fields > SecretPreview reveal > Audit Trail.
- Reveal card carries `role="dialog"` semantics inside the parent Dialog and initial focus MUST land on the `Copy secret` Button, not on the confirmation Checkbox, so keyboard users can grab the secret before acknowledging per `28-a11y-conformance.md` §5.
- SemVer Field carries `aria-describedby` pointing to a helper node quoting the regex pattern in plain language (`Format: major dot minor dot patch, optionally with a pre-release tag.`) per `18-component-input.md` §6.
- KeyHealth amber / red tone MUST also carry a glyph and text; color alone BANNED per `25-` §5.

## 11. Telemetry

Per `22-log-line-contract.md`:
- `RoutePresented` with `RouteId: "Builder.Overview" | "Builder.Clients.List" | "Builder.Clients.Detail" | "Builder.Updates.List"`, `A11yViolations: 0`, `LoadDurationMs`.
- `ClientCreate*`, `ClientLabelEdit*`, `ClientSecretRotation*`, `ClientRevoke*`, `UpdatePublish*`, `UpdateRetract*` with `ClientId` or `UpdateId`, `Version` for updates, `Channel`, `IdempotencyKey`, `ClientEtag`, `ErrorCode`, `RequestId`. `SecretFingerprint` (SHA-256 8-char) IS logged for rotation; plaintext `ClientSecret` NEVER. `ReleaseNotes` VALUE NEVER logged. `Reason` VALUE NEVER; `ReasonFingerprint` IS.

## 12. Anti-patterns (BANNED)

1. Any UI element or query key carrying a `BuilderId` value at builder scope.
2. Returning cached plaintext `ClientSecret` after Dialog close (must be discarded and never re-retrievable).
3. Rendering `403` for cross-builder client lookup (server returns `404`).
4. Optimistic mutations of any kind.
5. Missing `Idempotency-Key` on Create / Rotate / Revoke / Publish / Retract.
6. Missing `If-Match: <ClientEtag>` on Rotate / Revoke / Edit label.
7. Phrase-typing on `ClientId` for Rotate / Revoke (must be Label per §4).
8. `useQuery`+`isLoading` initial gate.
9. Silent 403 or 404 (invalid-format ClientId MUST render 404 card).
10. Color-only tone on `SecretAgeDays` or `KeyHealth` (glyph mandatory).
11. Plaintext `ClientSecret` in a query cache or a log line.
12. `Version` cell rendered as an inert `<span>` styled as a `<Link>` (must be plain text until the update-detail route exists per §5 correction).

## 13. Acceptance criteria

- AC-ROUTE-BUILDER-001: All four routes render under `_authenticated`; permission denial renders 403 terminal card.
- AC-ROUTE-BUILDER-002: Cross-builder client lookup returns `404 ClientNotFound`; invalid-format `ClientId` renders 404 card.
- AC-ROUTE-BUILDER-003: Rotate secret plaintext is shown exactly ONCE in a Reveal card, gated by the `I have copied and stored the secret` Checkbox, and is NEVER cached or logged; `SecretFingerprint` IS logged.
- AC-ROUTE-BUILDER-004: Revoke client and Retract update route through phrase-typing (client Label / update Version respectively) + mandatory Reason; concurrent rotations resolve as one apply + one `409 ClientSecretAlreadyRotated`.
- AC-ROUTE-BUILDER-005: All mutations send `Idempotency-Key` AND `If-Match: <ClientEtag>` where applicable; `412` renders inline refresh Banner.
- AC-ROUTE-BUILDER-006: KeyHealth tone renders color-plus-glyph-plus-text at 180 d amber and 365 d red thresholds per `35-security-events.md`.
- AC-ROUTE-BUILDER-007: One `invalidateQueries(["Builder.Clients"])` refreshes list, detail, audit; Rotate ALSO invalidates KeyHealth + Overview.
- AC-ROUTE-BUILDER-008: Axe zero `serious`/`critical` at 360/768/1440; SemVer Field carries plain-language `aria-describedby` helper.

## 14. Open items (for follow-up commits)

- Update-detail route (`/builder/updates/:UpdateVersion`) deferred; when added, `Version` cell becomes a full-cell `<Link>` per §5 correction and §12 anti-pattern 12.
- Client sub-user model (per-client API keys with fine-grained scopes) deferred.
- CSV export of Clients list deferred to v2 with mandatory `Idempotency-Key`.
- Cross-builder promotion Admin queue deferred; when built, MUST route through the Admin quota-request queue pattern per `37-`.
