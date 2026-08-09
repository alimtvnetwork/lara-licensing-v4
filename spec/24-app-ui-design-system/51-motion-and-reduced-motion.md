# Motion and Reduced-Motion Catalog

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Single normative source for motion tokens (duration, easing, distance) and the reduced-motion contract that governs how every animated component MUST degrade when `prefers-reduced-motion: reduce` is set.
**Owner:** Motion design tokens plus the WCAG 2.2 AA + 2.3.3 conformance contract for motion.
**Related:** [`10-token-registry.md`](./10-token-registry.md), [`13-typography.md`](./13-typography.md), [`14-navigation-ia.md`](./14-navigation-ia.md), [`16-route-shell-states.md`](./16-route-shell-states.md), [`17-component-button.md`](./17-component-button.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`23-component-toast-banner.md`](./23-component-toast-banner.md), [`24-component-table.md`](./24-component-table.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`29-responsive-matrix.md`](./29-responsive-matrix.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

---

## 1. Purpose and scope

Defines the closed sets of motion tokens (durations, easings, distances) and the mandatory reduced-motion degradation contract. Every animated component in `17-`..`25-` MUST source its motion values from this file; hand-picked `transition` values in component CSS are BANNED and lint-failed.

Out of scope: micro-illustration animation and marketing hero motion (LaraLicensingV1 is a console app; illustrations are not part of the surface). Onboarding motion tutorials deferred to `/onboarding/*` (see §12).

## 2. Duration tokens (closed set)

```
--motion-duration-instant:  0ms     # No animation; property change is atomic.
--motion-duration-xs:      80ms     # Focus rings, hover-tone swaps, Switch commit.
--motion-duration-sm:     140ms     # Button press, chip appear, Toast slide-in.
--motion-duration-md:     220ms     # Dialog open, Popover open, Sheet slide-in.
--motion-duration-lg:     320ms     # Route transition scrim, Skeleton fade-in.
--motion-duration-xl:     480ms     # Reserved for Command Palette open; MUST NOT be used elsewhere.
```

- Six closed values; hand-picked durations (`transition: 250ms`) BANNED.
- Durations above 480 ms are BANNED for any UI motion (Nielsen threshold for perceived responsiveness).
- Any motion under `--motion-duration-instant` collapses to zero; do NOT invent shorter values.
- The `xl` bucket is reserved for the Command Palette open only per `32-command-registry.md`; the value is documented here so it participates in the registry, but no other component MAY consume it.

## 3. Easing tokens (closed set)

```
--motion-easing-standard:   cubic-bezier(0.2, 0, 0, 1)   # UI defaults (enter + exit)
--motion-easing-accelerate: cubic-bezier(0.3, 0, 1, 1)   # Exit-only (Toast dismiss, Popover close)
--motion-easing-decelerate: cubic-bezier(0, 0, 0.2, 1)   # Enter-only (Dialog open, Sheet slide-in)
--motion-easing-linear:     linear                        # Progress bars, spinners; NEVER for enter/exit
```

- Four closed values; hand-picked easings (`ease-in-out`, `cubic-bezier(...)` inline) BANNED except in this file.
- `--motion-easing-standard` is the default for any motion that both enters AND exits with the same curve.
- Split-curve motion (different enter vs exit) is the DEFAULT for surfaces that dismiss (Dialogs, Popovers): use `decelerate` for enter and `accelerate` for exit.
- Bouncy / overshoot easings BANNED (they misrepresent state confirmation as playful; console-app tone per `27-content-voice.md` §3).

## 4. Distance tokens (closed set)

```
--motion-distance-xs:  2px    # Focus ring, Button press displacement (translateY).
--motion-distance-sm:  4px    # Skeleton pulse displacement (transform: translateY 4px).
--motion-distance-md:  8px    # Toast slide-in, Popover slide-in.
--motion-distance-lg: 16px    # Sheet slide-in on XS/SM per 29- responsive rules.
--motion-distance-xl: 24px    # Dialog slide-up on desktop; upper bound.
```

- Distances above 24 px BANNED (readable-transition threshold; larger jumps break the sense of continuity per `28-` §7 vestibular guidance).
- Motion distances SHOULD scale down on smaller viewports; each surface's blueprint documents its own XS/SM override where relevant.
- Rotate + scale distances are NOT tokenised in v1 (LaraLicensingV1 does not use rotate / scale for UI motion; icons that toggle direction, e.g. chevron in an accordion, use `--motion-duration-sm` + `--motion-easing-standard` on a plain `transform: rotate(-90deg)` with the target angle in the component CSS, not tokenised because there is only one such rotate in the app).

## 5. Reduced-motion contract

When `prefers-reduced-motion: reduce` is set, every motion in the app MUST degrade per one of these three strategies:

- **Strategy A (Collapse to instant):** duration becomes `0ms`, opacity + transform become their end-state values immediately. Default for Toast slide-in, Popover open, Menu open, Skeleton pulse.
- **Strategy B (Fade only):** duration retains its original value; transform is REMOVED; only `opacity` interpolates. Default for Dialog open, Sheet open, Route transition scrim. This preserves the perceived cognitive continuity (a surface appearing) without the vestibular hazard of translation.
- **Strategy C (Cross-fade, no scrim):** an outgoing surface fades out and an incoming surface fades in without any translation OR scrim. Reserved for the Command Palette open transition per `32-` §4.

Rules:

- Every component that declares motion MUST also declare which strategy it uses under `prefers-reduced-motion: reduce`. Undeclared behaviour is a lint failure per §13 rule 3.
- Removing motion entirely under reduced-motion is FORBIDDEN when the motion carries state information (e.g. Dialog open); Fade-only is the correct choice because it retains the affordance without translation.
- Auto-play, loop, and infinite motion under reduced-motion MUST pause (Skeleton pulse becomes a static tinted placeholder; spinners become a static circle with `aria-live="polite"` text "Loading").
- The reduced-motion state MUST be re-checked on every route mount because users can toggle the OS preference mid-session; a `useReducedMotion()` hook (`src/hooks/use-reduced-motion.ts`) reads the media query with an event listener so re-renders happen.

## 6. Component motion registry

Closed table binding every animated component to a duration, easing, distance, and reduced-motion strategy. Any component NOT in this table is not animated. Any animation added to the app in a runtime commit MUST add a row here in the same commit.

| Component | Trigger | Duration | Easing (enter) | Easing (exit) | Distance | Reduced-motion strategy |
|---|---|---|---|---|---|---|
| Button (17-) | hover | xs | standard | standard | none | A |
| Button (17-) | press | xs | standard | standard | xs (translateY) | A |
| Focus ring | focus-visible | xs | standard | standard | none | A |
| Switch (20-) | commit | sm | standard | standard | none (color only) | A |
| Checkbox (20-) | check | xs | standard | standard | none | A |
| Radio (20-) | select | xs | standard | standard | none | A |
| Chip / Badge (25-) | appear | sm | decelerate | accelerate | sm (translateY, enter only) | B |
| Toast (23-) | slide-in | sm | decelerate | accelerate | md | A |
| Banner (23-) | slide-in | sm | decelerate | accelerate | sm | A |
| Menu / Popover (22-) | open | md | decelerate | accelerate | md | A |
| Dialog (21-) | open | md | decelerate | accelerate | xl (translateY) | B |
| Sheet | open | md | decelerate | accelerate | lg | B |
| Route scrim (16-) | during-nav | lg | standard | standard | none (opacity only) | B |
| Skeleton (16-) | pulse | md | linear | linear | none (opacity 0.6-1.0) | A (paused) |
| Spinner (17-) | loading | linear infinite (`--motion-duration-md` per cycle) | linear | linear | rotate 360deg | A (paused, static circle + aria-live text) |
| Command Palette (32-) | open | xl | decelerate | accelerate | md | C |
| Chevron toggle | rotate | sm | standard | standard | rotate 90deg | B (fade, no rotate) |
| Table row focus | keyboard nav | xs | standard | standard | none (background tint only) | A |

- 18 rows; adding a row without also updating this table AND the reduced-motion mapping is a lint failure.
- Two components (Skeleton, Spinner) have `paused` in the reduced-motion column because their motion carries no state information; the static replacement MUST include a text affordance (`Loading`, `Please wait`) to keep the a11y contract.

## 7. Route transitions

Route transitions per `16-route-shell-states.md` §6 use a scrim (opacity 0 -> 0.6 -> 0) on the outgoing shell with `--motion-duration-lg` + `--motion-easing-standard`, Strategy B under reduced-motion. The incoming route mounts immediately (no delay). Slide-in on route change is BANNED because it competes with the Sidebar tab state and creates a spurious `back-nav feels different from forward-nav` effect.

## 8. Focus motion

Focus rings MUST animate with `--motion-duration-xs` + `--motion-easing-standard` on `outline-offset` and `box-shadow`; the ring's colour MUST NOT interpolate (colour interpolation across large hue differences produces a muddy midpoint per `28-` §5 focus-ring guidance). Under reduced-motion the ring becomes atomic (Strategy A). Focus-within on containers MUST NOT animate at all (focus-within is a broad state that flickers on nested widgets; animation multiplies the flicker).

## 9. Motion cancellation

- Any motion in progress when the trigger is inverted (Dialog opening then closed before open completed) MUST cancel and jump to the target end-state via `transition: none` for the reversal frame, then re-enable the token for the return trip. `transition: all` is BANNED because it animates unintended properties on the reversal frame.
- Menu / Popover / Dialog / Sheet close under Escape MUST run the exit motion (not jump), then unmount; skipping the exit motion breaks the sense of dismissal for sighted users.
- Under reduced-motion the exit motion follows Strategy A (instant) except for Strategy B/C components which retain their fade.

## 10. Motion and `aria-live`

- Any `aria-live` region that gains a message MUST NOT animate its text (the screen-reader announcement is the affordance; visual motion is redundant and delays perceived latency).
- Toast + Banner slide-in animates the CONTAINER, not the text; the `aria-live` region announces immediately on mount, regardless of the container's motion state.
- Under reduced-motion Strategy A, the container mounts at end-state on the same frame the message is announced, so sighted + non-sighted users hit the same perceived latency.

## 11. Testing and observability

- Playwright integration test suite MUST include a `prefers-reduced-motion: reduce` variant that reruns the visual smoke tests; if any component's screenshot differs between the two runs by more than 2 px displacement, the test fails with the specific component and its strategy row.
- `RoutePresented` log line per `../21-app/22-log-line-contract.md` MUST include a `PrefersReducedMotion` boolean so triage can correlate motion-related bugs with the setting.
- Storybook (or equivalent visual catalog) MUST expose every animated component with a `Reduced motion` toggle that flips the media-query preference locally; toggling MUST NOT require a route reload (the hook already listens for `change` events per §5).

## 12. Motion tone

- Motion is INFORMATIVE, not decorative. Every animated transition in the registry corresponds to a state change the user needs to see (open, close, commit, error, focus). Decorative motion (breathing icons, animated hero backgrounds) BANNED.
- Motion never contradicts semantic state; a Button that has been pressed and disabled MUST NOT continue to hover-lift.
- Motion never plays as a reward or celebration (confetti, bounce, `motion.ok` chimes); this is a licensing console for operators, not a consumer app per `27-` §3 tone rules.

## 13. Anti-patterns (BANNED)

1. Hand-picked duration OR easing values in component CSS (must reference tokens from §2 / §3).
2. Motion added to a component that has no row in the §6 registry.
3. Reduced-motion behaviour undeclared for any registered motion.
4. `transition: all` on any element (property scope must be explicit).
5. Motion under 80 ms (below `--motion-duration-xs`) for any state change.
6. Motion over 480 ms (above `--motion-duration-xl`) for any UI element.
7. Overshoot / bounce easings.
8. Auto-play or infinite motion that does not pause under reduced-motion.
9. Removing motion entirely under reduced-motion when the motion carries state information (must Fade-only, Strategy B).
10. Route slide-in transitions (banned per §7).
11. Focus-within animation on containers (banned per §8).
12. Rotate + translate combined motion in the same component transition (banned; use one axis at a time to preserve legibility on small viewports).
13. Motion + `aria-live` text interpolation (announcement is the affordance).
14. Decorative or celebratory motion (breathing icons, confetti, bounce).

## 14. Acceptance criteria

- AC-MOTION-001: Every animated component in the app has a row in the §6 registry, and every row references a token from §2 / §3 / §4 (no hand-picked values in component CSS).
- AC-MOTION-002: `useReducedMotion()` reads `prefers-reduced-motion: reduce` with a live event listener; toggling the OS preference during a session flips the app without a route reload.
- AC-MOTION-003: Under reduced-motion, Playwright screenshots differ from the full-motion baseline by no more than 2 px displacement on every registry row (Fade-only surfaces retain identical geometry at both ends).
- AC-MOTION-004: `RoutePresented` log line carries a `PrefersReducedMotion` boolean.
- AC-MOTION-005: `check-motion-registry.py` (new linter under §15) passes: every component with a `transition:` or animation in CSS matches a §6 row; every §6 row is consumed by at least one component.
- AC-MOTION-006: Skeleton and Spinner pause under reduced-motion with a static replacement carrying visible `Loading` text + `aria-live="polite"`.

## 15. Linter

New linter `linter-scripts/check-motion-registry.py`:

- Scans `src/**/*.css` and `src/**/*.tsx` for `transition:`, `animation:`, `@keyframes`, and `Motion` / `motion` imports.
- Cross-references every match with the §6 registry.
- Fails on: hand-picked duration or easing, missing registry row, `transition: all`, motion outside 80..480 ms range, animated `aria-live` text.
- Runs in CI and via `./linter-scripts/run.sh check-motion-registry`.

## 16. Open items (for follow-up commits)

- Onboarding-first-run motion tutorial deferred to `/onboarding/*` (a Dialog series that walks new admins through key surfaces). When built, MUST follow §6 registry with `--motion-duration-md` + Strategy B.
- Data-viz motion (chart transitions on filter change) deferred; when built, MUST cap at `--motion-duration-md` and Strategy A under reduced-motion.
- Cross-fade route transitions for same-family navigations (e.g. `/admin/licenses` -> `/admin/licenses/:LicenseId`) deferred; the default scrim is sufficient in v1.
