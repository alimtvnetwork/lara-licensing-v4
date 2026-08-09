# SS-01 Boot sequencing for preview mode

Slug: boot-sequencing
Parent: 17-seed-mode-reliability-and-config-db
Status: completed
Created: 2026-07-21
Completed: 2026-07-21
Resolution: Superseded by router-level implementation in `src/router.tsx` (lines 19-53). Preview handlers are eagerly registered at module load via `registerAllPreviewHandlers()` (Step 2), `bootRuntimeConfig()` freezes the store, then `assertPreviewBootReady(PREVIEW_FIXTURE_MODULES.length)` fails loud on empty registry (Step 17), then `dispatchPreviewSeed()` hydrates IndexedDB. The proposed `PreviewBootGate`/`React.use()` shape was dropped because eager registration makes suspense unnecessary: loaders that fire before boot resolution still find handlers under `apiClient.call()`. Invariants INV-RM-04 / INV-RM-11 / SA-013 satisfied. Locked by `tests/preview-boot-invariant.test.ts` (Step 17) and `tests/preview-transport-registration.test.ts`.

## Goal

Guarantee that in `Mode=preview`, no `useApi` query, no route loader, and no
component render fires before:

1. `bootRuntimeConfig()` has resolved and frozen the runtime store.
2. `dispatchPreviewSeed()` has completed IndexedDB hydration for the active seed.
3. Every module under `src/lib/preview-fixtures/*` has been imported (side-effect
   registered its handlers on the `preview-transport` registry).

## Shape

- Introduce `src/lib/preview-boot.ts` exporting `previewBoot(): Promise<void>`.
- `previewBoot()` sequence:
  1. `await bootRuntimeConfig()`.
  2. If mode is `preview`: dynamic-import a central `preview-fixtures/index.ts`
     that statically imports every fixture module (so bundlers include them and
     handler registration happens exactly once).
  3. `await dispatchPreviewSeed()`.
  4. Assert `registeredHandlerCount() > 0`; else throw `PREVIEW_BOOT_INCOMPLETE`.
- In `src/routes/__root.tsx`, wrap `<Outlet />` in a `<PreviewBootGate>` component
  that uses `React.use(previewBootPromise)` (or a `useSyncExternalStore` shim for
  SSR) so children suspend until boot resolves. In `dev`/`production` modes the
  gate is a no-op passthrough.
- `previewBoot()` must be idempotent: repeated calls return the same promise.

## Failure handling

- Any thrown error inside `previewBoot()` bubbles to the root `errorComponent`
  with `PREVIEW_BOOT_FAILED` and details (which phase, seed id, handler count).
  No silent fallback (INV-RM-11 / SA-013).

## Tests

- Vitest: `previewBoot()` resolves once; second call returns the same promise;
  handler count > 0 after resolution; unknown seed id logs + falls back to
  `default` and still resolves.
- Playwright: hard reload `/admin/audit` under `default` seed → no error banner,
  first row rendered within 400 ms of `DOMContentLoaded`.
