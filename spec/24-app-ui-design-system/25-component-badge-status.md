# Component: Badge and Status

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI.
**Owner:** This file is the sole normative source for the Badge primitive and the closed-set status mapping (LicenseState, SerialState, BuilderKeyState, QuotaRequestStatus, UserRole) used across every table row, detail Sheet, and dashboard card. It binds the enums declared in [`../21-app/15-license-lifecycle.md`](../21-app/15-license-lifecycle.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), and [`../21-app/05-license-categories.md`](../21-app/05-license-categories.md) to a single tone/icon/label registry consumed by `src/components/badge/*` (to be created).
**Related:** [`08-token-registry.md`](./08-token-registry.md), [`09-typography-scale.md`](./09-typography-scale.md), [`19-component-select.md`](./19-component-select.md), [`24-component-table.md`](./24-component-table.md), [`../21-app/15-license-lifecycle.md`](../21-app/15-license-lifecycle.md).

---

## 1. Purpose and non-purpose

Badge encodes a single closed-set enum value as a compact, non-interactive visual token. It is READ-ONLY: clicking a Badge does nothing. Any interactive filter chip lives in `24-component-table.md` §7 (FilterChip) and is a different primitive.

Badge is NOT for counts (use plain `tabular-nums` text), NOT for free-text tags (v1 has no tags), and NOT for notifications (use unread dot on the bell icon per navigation IA).

## 2. Variants (tone)

| Tone | Foreground token | Background token | Border | When |
|------|------------------|------------------|--------|------|
| neutral | `--fg-muted` | `color-mix(in oklch, var(--surface) 100%, var(--fg) 4%)` | none | Steady, uninteresting state (`Draft`, `Pending`). |
| info | `--info` | `color-mix(in oklch, var(--info) 12%, var(--surface))` | none | Informational lifecycle (`Issued`, `Requested`). |
| success | `--success` | `color-mix(in oklch, var(--success) 12%, var(--surface))` | none | Healthy terminal state (`Active`, `Approved`). |
| warning | `--warning` | `color-mix(in oklch, var(--warning) 14%, var(--surface))` | none | Attention-required state (`GracePeriod`, `NearExpiry`, `Throttled`). |
| destructive | `--destructive` | `color-mix(in oklch, var(--destructive) 12%, var(--surface))` | none | Terminal negative state (`Revoked`, `Expired`, `Rejected`). |
| accent | `--accent` | `color-mix(in oklch, var(--accent) 12%, var(--surface))` | none | Reserved for role/tier tags (`Admin`, `Reseller`, `Tier:Enterprise`). |

Color-only differentiation is BANNED; every Badge renders a leading icon (§4).

## 3. Anatomy and geometry

```
[icon] Label
```

- Height: `24px` (`--space-6`); inline-padding `--space-2` (`8px`); gap `--space-1` (`4px`).
- Radius: `--radius-sm` (`4px`). Pills (`--radius-full`) are BANNED (drift with FilterChip).
- Typography: `Label/Small` (12 px, 600 weight); MUST NOT wrap; overflow beyond `20ch` is BANNED (rename the enum first).
- Icon: 14 px Lucide outline, `aria-hidden="true"`; the accessible name is carried by the Label text alone.

Density variants (`sm` at 20 px height for dense Tables, `md` default) share the same tone tokens; larger variants are BANNED.

## 4. Closed-set registry (normative)

Every enum below MUST resolve to exactly one (tone, icon, label) tuple. Renderers MUST fail loudly (dev throw; prod `logger.warn` with `BadgeUnknownValue`) on unmapped values; falling back to `neutral` silently is BANNED.

### 4.1 LicenseState (source: [`../21-app/15-license-lifecycle.md`](../21-app/15-license-lifecycle.md))

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `Draft` | neutral | `FileText` | Draft |
| `Issued` | info | `Sparkles` | Issued |
| `Active` | success | `CheckCircle2` | Active |
| `GracePeriod` | warning | `Clock` | Grace period |
| `Expired` | destructive | `CalendarX2` | Expired |
| `Revoked` | destructive | `Ban` | Revoked |
| `Suspended` | warning | `PauseCircle` | Suspended |

### 4.2 SerialState

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `Unbound` | neutral | `Circle` | Unbound |
| `Bound` | success | `Link2` | Bound |
| `Rebinding` | warning | `RefreshCw` | Rebinding |
| `Retired` | destructive | `Archive` | Retired |

### 4.3 BuilderKeyState

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `Active` | success | `KeyRound` | Active |
| `Rotating` | warning | `RefreshCw` | Rotating |
| `Revoked` | destructive | `Ban` | Revoked |

### 4.4 QuotaRequestStatus

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `Pending` | info | `Hourglass` | Pending |
| `Approved` | success | `CheckCircle2` | Approved |
| `Rejected` | destructive | `XCircle` | Rejected |
| `Withdrawn` | neutral | `Undo2` | Withdrawn |

### 4.5 UserRole

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `SuperAdmin` | accent | `ShieldCheck` | Super admin |
| `Admin` | accent | `Shield` | Admin |
| `Reseller` | accent | `Store` | Reseller |
| `AppBuilder` | accent | `Wrench` | App builder |
| `EndUser` | neutral | `User` | End user |

### 4.6 LicenseTier (source: [`../21-app/43-license-tiers.md`](../21-app/43-license-tiers.md))

| Value | Tone | Icon | Label |
|-------|------|------|-------|
| `Trial` | neutral | `Beaker` | Trial |
| `Standard` | info | `Package` | Standard |
| `Professional` | info | `Boxes` | Professional |
| `Enterprise` | accent | `Building2` | Enterprise |

Any change to a source enum (adding, renaming, removing a value) MUST update §4 in the same commit; the `tests/badge-closed-sets.test.ts` (future) asserts parity with the runtime enum modules.

## 5. Placement rules

- Exactly ONE state Badge per row (the primary lifecycle state). Secondary attributes render as plain text in adjacent columns, NOT as extra Badges.
- Detail Sheets may render up to THREE Badges in the header: primary state, role/tier, environment. More than three is BANNED (Badge inflation).
- Badges MUST NOT appear in navigation labels, breadcrumb items, or Button labels. Use text and let the surrounding surface convey meaning.
- Table cell containing a state Badge is center-aligned; row identifier remains start-aligned per `24-component-table.md` §5.

## 6. Accessibility

- Badge renders a `<span>` (no interactive role). Screen readers read only the Label text; the icon is `aria-hidden`.
- Do NOT set `title` on Badge (tooltip anti-pattern per `17-component-button.md` §8).
- Color contrast MUST meet WCAG 2.2 AA against the surface it sits on; the tone tokens in `08-token-registry.md` are pre-validated for `--surface` and `--surface-raised`. Rendering a Badge over an arbitrary background (image, colored banner) is BANNED without a re-validation entry in `08-token-registry.md`.

## 7. Motion

Badges do NOT animate. State transitions are conveyed by re-render (the row's underlying data changes), not by cross-fading the Badge in place. `prefers-reduced-motion` therefore has no Badge-specific rule.

## 8. Logging

Badge itself does not log. When a state transition is presented (e.g. after a mutation), the mutation's success handler emits the state-change log line per `../21-app/22-log-line-contract.md`; the Badge is a passive projection.

## 9. Anti-patterns

1. Free-text or user-supplied Badge labels.
2. More than one state Badge per row.
3. Interactive Badge (`onClick`, `<button>`).
4. Tooltip on Badge to reveal the "real" state.
5. Color-only tone (no icon).
6. Silent fallback to `neutral` for unmapped values.
7. Badge inside Button label.
8. Custom tones outside §2.
9. Rounded-full pills (drift with FilterChip).
10. Animating Badge in/out on state change.

## 10. Acceptance criteria

- AC-BDG-001: Every LicenseState, SerialState, BuilderKeyState, QuotaRequestStatus, UserRole, LicenseTier value from `../21-app/*` resolves to exactly one row in §4.
- AC-BDG-002: Rendering a Badge with an unmapped value throws in dev and emits `BadgeUnknownValue` at warn in prod; visual output is a `destructive` outline placeholder, NOT `neutral`.
- AC-BDG-003: Every state Badge renders a leading Lucide outline icon; icon-less Badges fail the visual regression test.
- AC-BDG-004: Detail Sheet headers render at most three Badges.
- AC-BDG-005: `tests/badge-closed-sets.test.ts` (future) asserts §4 registry equals the union of runtime enum modules; no orphan mappings on either side.

## 11. Verification

- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- Manual: every enum linked in §4 resolves in its source `spec/21-app/*` file; the source enum's value list is a strict subset of §4.
