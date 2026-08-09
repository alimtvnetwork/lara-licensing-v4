# Icon and Illustration Registry

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for the closed icon set, sizing scale, tinting rules, ARIA contract, licensing provenance, and the illustration-usage boundary.
**Owner:** Icon + illustration governance. Any icon or illustration referenced in a component / route blueprint MUST appear in this registry.
**Related:** [`10-token-registry.md`](./10-token-registry.md), [`13-typography.md`](./13-typography.md), [`17-component-button.md`](./17-component-button.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`25-component-badge-chip.md`](./25-component-badge-chip.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`51-motion-and-reduced-motion.md`](./51-motion-and-reduced-motion.md).

---

## 1. Purpose and scope

Defines the closed icon set sourced from a SINGLE provider (lucide-react), the size scale, the tinting contract, the ARIA `aria-hidden` / `aria-label` decision tree, the licence provenance record, and the illustration-usage boundary (LaraLicensingV1 is a console app; illustrations are strictly bounded to empty-states + terminal-states).

Out of scope: brand mark and logotype (owned by `.lovable/overview.md`); flag icons for locale switching (deferred; the app is single-locale in v1); animated Lottie files (BANNED in v1 per §11).

## 2. Provider

- Single icon library: `lucide-react` (ISC licence, tree-shakeable, PascalCase named exports).
- Mixing providers (react-icons, heroicons, phosphor, custom SVGs) is BANNED for iconography that appears in the component registry; visual inconsistency across an app is the single biggest tell of low quality per `27-content-voice.md` §2 tone rules.
- Custom SVGs are permitted ONLY for brand mark, illustration set (§10), and product-specific glyphs (e.g. serial-number icon) documented explicitly in this registry.
- Adding a new icon means adding a row to §5 in the same commit; ad-hoc `import { Foo } from 'lucide-react'` without a registry row is a lint failure per §12.

## 3. Size scale (closed set)

```
--icon-size-xs:   12px   # inline with body-xs text; e.g. inside a Chip
--icon-size-sm:   14px   # inline with body-sm text; e.g. inside a Button-sm
--icon-size-md:   16px   # DEFAULT; inline with body-md text
--icon-size-lg:   20px   # inline with body-lg / section header
--icon-size-xl:   24px   # standalone action icons; e.g. Sidebar tabs
--icon-size-2xl:  32px   # empty-state header icons
--icon-size-3xl:  48px   # terminal-state (403/404/500) header icons
```

- Seven closed values; hand-picked pixel sizes (`w-[18px]`) BANNED.
- The DEFAULT is `--icon-size-md` (16 px); every icon in body copy uses this unless otherwise specified in its row.
- Icons above 48 px BANNED (they compete with text hierarchy; use an illustration if a larger visual is needed per §10).
- Icons scale VISUALLY with `stroke-width: 1.75` at every size (lucide default); overriding stroke-width per usage BANNED so weight stays consistent across the app.

## 4. Tinting rules

- Icons INHERIT `currentColor` from their text parent. Setting `color:` on the icon directly is BANNED except for status glyphs (§7).
- Status icons (success / warning / error / info) MUST use their semantic token (`--color-status-success`, `--color-status-warning`, `--color-status-error`, `--color-status-info`) directly, NEVER a raw hex or Tailwind palette class.
- Icons in a disabled control inherit the disabled foreground token (opacity handled by parent; do NOT apply opacity on the icon separately, which would double-fade against a disabled parent).
- Multi-colour icons (gradients, dual-tone) BANNED. lucide is single-stroke by design; any icon needing a second tone belongs in the illustration set.

## 5. Icon registry (closed table)

Every icon consumed by the app MUST be in this table. Adding an icon in a runtime commit without adding a row is a lint failure.

| Icon (lucide name) | Purpose | Default size | Used by | ARIA default |
|---|---|---|---|---|
| `LayoutDashboard` | Overview / Home | xl | Sidebar tabs | hidden |
| `KeyRound` | License | xl | Sidebar Licenses tab, Card headers | hidden |
| `Barcode` | Serial | xl | Sidebar Serials tab, Serial detail | hidden |
| `Gauge` | Quota | xl | Sidebar Quotas tab, Reseller portal | hidden |
| `SlidersHorizontal` | Feature | xl | Sidebar Features tab, Tier matrix | hidden |
| `Layers` | Environment | xl | Sidebar Environments tab | hidden |
| `LayersFilled` (custom) | Environment (active) | xl | Sidebar Environments tab (selected) | hidden |
| `Award` | Tier | xl | Sidebar Tiers tab | hidden |
| `Users` | Users / roles | xl | Sidebar Users tab | hidden |
| `Building2` | Reseller | xl | Sidebar Reseller tab, Reseller portal header | hidden |
| `Hammer` | Builder | xl | Sidebar Builder tab, Builder console | hidden |
| `ShieldCheck` | Admin scope | xl | Sidebar Admin tab | hidden |
| `User` | End-user / Me | xl | `/me` header, Sessions Table | hidden |
| `Bell` | Notifications | md | App bar | label `Notifications` |
| `Search` | Search input | md | Table filter-bar, Command Palette trigger | hidden (paired with `<label>` for input) |
| `Command` | Command Palette shortcut hint | sm | App bar Command Palette Button | hidden |
| `ChevronDown` | Menu / Select trigger | sm | Buttons opening Menu, Select | hidden |
| `ChevronRight` | Row expand / breadcrumb sep | sm | Table row expand, Breadcrumb | hidden |
| `ChevronUp` | Menu / Sort ascending | sm | Table sort header (asc) | label `Sorted ascending` on sorted header |
| `ChevronsUpDown` | Sort unset | sm | Table sort header (unset) | label `Sort` |
| `X` | Close | md | Dialog / Sheet / Toast close Button | label `Close` |
| `Check` | Confirmation, checkbox | md | Confirmation Toast, Checkbox | hidden |
| `Copy` | Copy to clipboard | md | Reveal-once cards, code blocks | label `Copy` |
| `ClipboardCheck` | Copy success | md | Reveal-once cards (post-copy) | label `Copied` |
| `Eye` | Reveal secret | md | Reveal-once Buttons | label `Reveal` |
| `EyeOff` | Hide secret | md | Reveal-once Buttons (post-reveal) | label `Hide` |
| `Info` | Info status | md | Banner info variant, Popover trigger | hidden (paired with text) |
| `CircleCheck` | Success status | md | Toast success, Badge Active | hidden (paired with text) |
| `TriangleAlert` | Warning status | md | Toast warning, Banner warning | hidden (paired with text) |
| `CircleX` | Error status | md | Toast error, Banner error, Form error | hidden (paired with text) |
| `CircleHelp` | Help / tooltip | md | Field label help trigger | label `Help` |
| `ExternalLink` | Opens new tab | sm | Any anchor with `target="_blank"` | label `Opens in new tab` |
| `Download` | Download action | md | Download Buttons (PDF, CSV) | label `Download` |
| `Upload` | Upload action | md | Import Buttons | label `Upload` |
| `Filter` | Filter panel toggle | md | Table filter-bar | label `Filter` |
| `RefreshCw` | Reload / retry | md | Retry Button, Refresh Button | label `Retry` or `Refresh` |
| `Plus` | Create / add | md | Primary create Buttons | hidden (paired with text `New ...`) |
| `Pencil` | Edit | md | Row action, Menu item | label `Edit` |
| `Trash2` | Delete | md | Row action, Menu item | label `Delete` |
| `Ban` | Revoke / disable | md | Menu item Revoke | label `Revoke` |
| `RotateCcw` | Rotate secret | md | Menu item Rotate | label `Rotate secret` |
| `Send` | Submit request | md | Reseller quota request Button | hidden (paired with text) |
| `LogOut` | Sign out | md | User menu | label `Sign out` |
| `Sun` | Light theme | md | Theme switcher | label `Light theme` |
| `Moon` | Dark theme | md | Theme switcher | label `Dark theme` |
| `Monitor` | System theme | md | Theme switcher | label `Match system theme` |
| `Loader2` | Loading spinner (rotates per `51-` registry) | md | Buttons in loading state, inline spinners | label `Loading` |
| `Lock` | Restricted / disabled by permission | md | Sidebar tabs the caller cannot see | label `Restricted` |
| `Fingerprint` | Device binding | md | Rebind Dialog, Device row | hidden (paired with text) |
| `Clock` | Pending / scheduled | md | Badge Pending, Quota-request row | hidden (paired with text) |
| `FileSignature` | Audit event | md | Audit log rows | hidden (paired with text) |

- 50 rows; every subsequent registry addition MUST cite (a) purpose, (b) at least one consumer, (c) ARIA default.
- Icons appearing ONLY in a lint fixture or Storybook example do NOT need a row (they are not in the shipped surface).

## 6. ARIA contract

- Icon INSIDE a labelled parent (Button with visible text, table cell with cell text, chip with visible text): `aria-hidden="true"`. The visible text is the accessible name; the icon is decorative.
- Icon as the SOLE content of a control (icon-only Button, icon-only tab): the control MUST carry an `aria-label` matching the registry row's `ARIA default`. The icon itself carries `aria-hidden="true"` because the label lives on the interactive parent, not the SVG.
- Status icons paired with visible status text: `aria-hidden="true"` on the icon; the surrounding `role="status"` / `role="alert"` region announces the text.
- Status icons WITHOUT visible text (rare; e.g. a green dot beside a table row): the icon parent MUST carry an `aria-label` describing the state (`Active`, `Pending`, `Revoked`); the icon itself is `aria-hidden="true"`.
- Applying `aria-label` DIRECTLY to a `<svg>` element is permitted but discouraged; prefer labelling the interactive parent so the reading order stays natural.
- NEVER combine `aria-hidden="true"` with `focusable="false"` missing; every SVG in the app MUST render with `focusable="false"` to prevent IE-legacy tab traps (lucide-react does this by default; audit any custom SVG).

## 7. Status glyph contract

- Success, warning, error, info are the ONLY four status states.
- Each maps to exactly one icon (`CircleCheck`, `TriangleAlert`, `CircleX`, `Info`) and one semantic colour token.
- Substituting alternative icons per surface (e.g. `CheckCircle2` in one place, `Check` in another for the same state) BANNED.
- Do NOT invent additional status states (loading, blocked, unknown, pending) with new icons; use the existing four plus text.
- Pending state uses `Clock`, NOT a status-colour token; pending is temporal, not evaluative.

## 8. Sizing / alignment recipes

- Inline with text: icon vertically centred against the text cap-height, NOT the line-box. Concretely: wrap the icon in a `<span class="inline-flex items-center">` and set the parent's line-height per §13 typography; do NOT apply `vertical-align` values by hand.
- Icon + label spacing: `gap: 0.5ch` for inline icons in body text; `gap: 8px` for icons inside Buttons per `17-component-button.md` §5.
- Right-hand icons (e.g. ChevronDown on a Select): fixed `gap: 8px`; NEVER `justify-content: space-between` because the total control width would grow with label length and the chevron would drift.
- Sidebar icons: `xl` size, left of label, `gap: 12px`; collapsed Sidebar renders the icon only with the label as an `aria-label` on the anchor.

## 9. Focus + hover motion

Icon-only Buttons focus + hover motion follow `17-component-button.md` §7 and `51-motion-and-reduced-motion.md` §6 rows (Button hover xs, press xs). Icons themselves NEVER animate on hover (no rotate, no wiggle, no colour interpolation). The Chevron toggle rotation (`51-` §6 row) is the ONLY registered icon rotation and applies to accordion / expand controls only.

## 10. Illustrations (bounded set)

Illustrations are BANNED in dashboards, tables, forms, and Dialogs. They appear in exactly two surface families:

- Empty-states (per `43-empty-state-catalog.md`, coming next step).
- Terminal-states (`/403`, `/404`, `/500` per `42-` blueprint §5).

Rules:

- Single illustrator, single style. Line-art with a monochrome fill on the primary tint; no photorealism, no gradients beyond the primary tint, no emoji as illustrations.
- Format: SVG with a viewBox and no fixed width/height (parent sizes it via `--illustration-size-md: 160px` or `--illustration-size-lg: 240px`).
- Placement in the tree: `src/assets/illustrations/*.svg` imported as a React component via `?react` suffix (Vite SVGR).
- Every illustration MUST carry `role="img"` on its root `<svg>` plus an `<title>` element for the accessible name; a decorative illustration is permitted only when adjacent visible text carries the state and the illustration is `aria-hidden="true"`.
- Illustrations MUST NOT animate; motion in illustration is BANNED per `51-` §12.
- No stock illustration providers (unDraw, Storyset, Absurd Design) in v1: the single-illustrator rule keeps visual coherence.

## 11. Licence provenance

- `lucide-react`: ISC licence, provenance recorded in `THIRD-PARTY-NOTICES.md` at repo root.
- Custom SVGs (illustrations, brand mark, product glyphs): copyright the project owner, licence proprietary; each `.svg` file carries an XML comment header with `<!-- (c) LaraLicensing project. All rights reserved. -->`.
- Third-party illustrations BANNED (see §10) so no external licence obligations accrue in v1.
- The `THIRD-PARTY-NOTICES.md` file is regenerated by a `check-third-party-notices.py` linter on every release; drift between installed packages and the notices file is a lint failure.

## 12. Linter (`check-icon-registry.py`)

New linter under `linter-scripts/check-icon-registry.py`:

- Scans `src/**/*.tsx` for `from 'lucide-react'` imports and every custom SVG import from `src/assets/`.
- Cross-references every imported icon with the §5 registry.
- Fails on: icon not in registry, icon imported but unused, mixed providers (react-icons, heroicons, phosphor detected in imports), hand-picked pixel size on an icon (`w-[NNpx]`, `h-[NNpx]`), `color:` on an icon that is not a status glyph, `<svg>` without `focusable="false"`, `<svg>` inside an interactive parent without either `aria-hidden="true"` or the parent carrying `aria-label`.
- Runs in CI and via `./linter-scripts/run.sh check-icon-registry`.

## 13. Anti-patterns (BANNED)

1. Mixing icon providers.
2. Hand-picked pixel sizes for icons.
3. Overriding `stroke-width` per usage.
4. Multi-colour / dual-tone icons.
5. Status glyph without semantic colour token.
6. `<svg>` without `focusable="false"`.
7. Icon-only Button without `aria-label` on the parent.
8. Applying `aria-label` to an SVG inside a labelled parent (double-labelling).
9. Icon animations on hover (rotate, wiggle, colour interpolation).
10. Illustrations in dashboards, tables, forms, or Dialogs.
11. Third-party illustration providers.
12. Animated Lottie or GIF files (motion registry does not admit them).
13. Emoji as substitute for status glyphs.
14. Icons above 48 px (use an illustration).
15. `w-[18px] h-[18px]` sizing.

## 14. Acceptance criteria

- AC-ICON-001: Every icon consumed by the app has a row in §5.
- AC-ICON-002: Every icon-only interactive control carries an `aria-label` matching the registry.
- AC-ICON-003: Status glyphs use semantic colour tokens, never raw hex.
- AC-ICON-004: `check-icon-registry.py` passes.
- AC-ICON-005: `THIRD-PARTY-NOTICES.md` is up to date with installed packages.
- AC-ICON-006: Illustrations appear ONLY in empty-state and terminal-state surfaces.
- AC-ICON-007: No custom SVG uses gradients, photorealism, or animation.

## 15. Open items

- Product-specific glyphs (custom serial-number icon at `xl` for the Serial detail hero) deferred until brand mark work lands.
- Locale flag icons deferred (single-locale v1).
- Animated Lottie for onboarding tutorial deferred and gated on `51-` §16 onboarding-first-run motion review.
