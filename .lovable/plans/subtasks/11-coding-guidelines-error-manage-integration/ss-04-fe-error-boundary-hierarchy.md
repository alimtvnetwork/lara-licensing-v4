# SS-04: FE error boundary hierarchy per spec/03-error-manage/02-error-architecture/04-error-modal

Parent: 11-coding-guidelines-error-manage-integration
Slug: fe-error-boundary-hierarchy
Status: pending
Created: 2026-07-19

## Goal
Wire the Global Error Modal (Tier 3) so any unhandled LaraApiError surfaces with ErrorCode, RequestId (copy button), and a Retry action when the code is retryable.

## Changes
- `src/components/errors/GlobalErrorModal.tsx`: reads from `useErrorStore` (Zustand), renders modal with:
  - Title from ErrorCode -> human message map (`src/lib/error-messages.ts`).
  - RequestId + ErrorId with copy-to-clipboard.
  - Retry button only when code in `RETRYABLE_CODES` closed set.
- `src/lib/error-store.ts`: Zustand store, `pushError(err: LaraApiError)`, `dismiss()`.
- Wire in `src/routes/__root.tsx` next to `<Toaster />`.
- Route-level `errorComponent` in every route with a loader delegates to store via `reportLovableError` + `pushError`.
- Update `useLaraErrorToast` to skip toast when the error is already in the modal store (avoid double surface).

## Verification
- Playwright spec `tests/e2e/specs/error-modal.spec.ts`:
  - Force a 500 via `/api/public/e2e/throw`; assert modal appears with RequestId copied.
  - Force a retryable 429; assert Retry button visible.
- Axe run on modal open: zero WCAG violations.
