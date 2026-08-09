---
Slug: 01-overlays
Status: completed
Created: 2026-07-20
Completed: 2026-07-21
Parent: 15-ui-modernization-pass
Resolution: |
  Shipped at v0.498.0. See `src/components/ui/dialog.tsx` lines 39-42 (overlay
  backdrop: `bg-[color-mix(in oklab, var(--background) 52%, transparent)]
  backdrop-blur-[10px] backdrop-saturate-[140%]`, `fade-in-0` on state open),
  line 89 (content fade + zoom), and `src/components/ui/sheet.tsx` lines 38-39
  (identical overlay contract). Radix primitives untouched; every existing
  `data-slot` attribute and aria wiring preserved. Visual verification carried
  by SS-04 screenshots.
---

# Overlay Modernization (Dialog + Sheet)

Refit `src/components/ui/dialog.tsx` and `src/components/ui/sheet.tsx`:

- Overlay backdrop: `bg-background/70` + `backdrop-blur-md saturate-140`.
- Content: `rounded-2xl border border-border/70`, `box-shadow: var(--shadow-elevation-3)`, `fade-in` on mount.
- Close button: 32px hit target, focus ring uses `--ring-focus-strong`.
- Preserve every existing data-slot attribute and aria wiring; do not touch Radix primitives beyond className.

Verification: `tests/overlay-primitives.test.tsx` passes untouched; visual pass via Playwright screenshot in SS-04.
