# Issue 05: Seed mode breaks on admin routes (audit log fails to load)

Status: open
Created: 2026-07-21

## Symptom

With `Data source = Seed data` selected in the hero switch, navigating to `/admin/audit` renders the `StateCard` error: "Audit log could not be loaded — Something failed on our side. Try again in a moment." Retry does not recover. Screenshot: user-uploads://file-7.

Other admin routes (Resellers, Users, Licenses, Features, Quota requests, App updates) likely share the failure mode because they all funnel through the same preview transport / fixture dispatch path.

## Expected vs Actual

- Expected: preview seed (`default`) populates IndexedDB via `preview-seed-dispatcher.ts`, then `admin.audit.list` handler (see `src/lib/preview-fixtures/audit.ts`) returns rows and the table renders.
- Actual: the query rejects with a generic failure, StateCard shows the error state. No visible request goes to any backend (correct: seed mode should not call one), but the preview handler either isn't registered, isn't awaited before render, or IndexedDB hydration didn't run for this route.

## Suspected root causes (to confirm during Plan 17)

1. `bootRuntimeConfig()` + `dispatchPreviewSeed()` may not be awaited before `_authenticated` loaders / `useApi()` fires (race → handler missing → LaraApiError fallback).
2. Preview fixture modules may not be imported (registration side-effect missed) when only landing → admin path is taken.
3. IndexedDB seeded rows keyed as `audit::<Id>` may not exist under `default` seed (audit hydration missing from `loadDefaultSeed`).
4. `spec/06-seedable-config-architecture/` closed-set / config seeds are not wired into the preview seeds, so admin screens that read config lookups blow up.

## Related files

- `src/lib/preview-seed-dispatcher.ts`
- `src/lib/preview-seeds/default.ts`
- `src/lib/preview-fixtures/audit.ts`
- `src/lib/preview-transport.ts`
- `src/lib/version-json-loader.ts`
- `src/routes/_authenticated/admin.audit.tsx`
- `spec/06-seedable-config-architecture/**`
