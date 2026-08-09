# Task 21: Backend parity Phase C - Auth & Synthetic Preview Sessions

Slug: backend-parity-phase-c
Status: pending
Created: 2026-08-06
Parent: backend-seed-login-e2e-error-manage

## Intent

Implement the synthetic preview session layer and demo login UI defined in Phase C of Plan 18. This allows reviewers to authenticate in preview mode using pre-defined identities (Admin, Reseller, Portal) without needing a live backend response, while maintaining claim-shape parity with the real production auth flow.

## Scope

1. Auth bridge: `src/lib/preview-auth.ts` implementation for synthetic token generation and storage.
2. UI: `<DemoLoginPanel />` component for quick identity selection on the login screen.
3. Routing: Integration with `src/routes/_authenticated.tsx` and login routes to support mode-gated auth.
4. Validation: Zod schemas for synthetic claims matching `AuthSessionResource` and `MeUser`.
5. Testing: Vitest unit tests for session hydration and teardown.

## Inputs

- Plan 18 Phase C (Steps 41-60) from `.lovable/plans/pending/18-backend-seed-login-e2e-error-manage.md`.
- Identity constants in `src/lib/demo-identities.ts` (mirrors `DemoIdentities.php` from Phase B).
- `src/routes/_authenticated.tsx` auth-gate logic.
- `src/lib/preview-transport.ts` for runtime mode detection.

## Acceptance Criteria

- AC-01: Login screen in preview mode displays the `<DemoLoginPanel />`.
- AC-02: Clicking a demo identity pre-fills and authenticates locally.
- AC-03: `useAuth()` returns valid synthetic claims for the selected identity.
- AC-04: Synthetic sessions are cleared when switching runtime modes or logging out.
- AC-05: Linter `check-preview-in-prod-bundle.py` prevents panel inclusion in production builds.

## Affected files

- `src/lib/preview-auth.ts` (new)
- `src/components/auth/DemoLoginPanel.tsx` (new)
- `src/routes/admin.login.tsx`
- `src/routes/_authenticated.tsx`
- `tests/preview-auth.test.ts` (new)
- `tests/demo-login-panel.test.tsx` (new)
- `linter-scripts/check-preview-in-prod-bundle.py` (extension)

## Attachments

- none

## Non-goals

- Real backend auth implementation (deferred to later phases).
- Persistence of synthetic sessions across browser restarts (should be session-only).
