# SS-03 - Sonner toast theme override

Parent: Plan 15 - UI Modernization Pass
Parent step: 21
Status: completed
Completed: 2026-07-21

## Scope

Refit the Sonner toast surface to Spec 24 §23.2 geometry: card fill,
rounded-xl, elevation-2, hairline border, 3px inline-start intent accent,
`fade-in`, `motion-reduce` respected. No changes to the routing contract
in `src/hooks/use-app-toast.ts`.

## Resolution

The theme override lives in `src/components/ui/sonner.tsx` (v0.487.0
refit). Verified all four contract points on that surface:

- `group-[.toaster]:rounded-[var(--radius-xl)]` (line 27) - rounded-xl.
- `group-[.toaster]:shadow-[var(--shadow-elevation-2)]` (line 28) - elevation-2.
- `group-[.toaster]:border group-[.toaster]:border-[var(--border)]` (line 26)
  plus `border-l-[3px]` (line 29) - hairline border + intent accent stripe.
- `fade-in` in the base class list (line 23) with `motion-reduce`
  overrides (lines 31-32) for prefers-reduced-motion.

Fix applied this turn: removed `richColors` from the root Toaster call
site (`src/routes/__root.tsx:99`). `richColors` re-enables Sonner's
built-in colored backgrounds, which overrides the neutral card fill and
3px accent-border contract configured in `sonner.tsx`. Also dropped the
redundant `position="top-right"` at the call site since it is already
set in the primitive.

Before: `<Toaster richColors position="top-right" />`
After:  `<Toaster />`

## Verification

- `bunx vitest run` full suite: 130 files / 824 tests pass.
- Toast primitive tests remain untouched.
- Sonner theme override is now authoritative; no call-site prop can
  silently override the Spec 24 §23.2 geometry.
