# Backend Parity Phase C - Auth & Synthetic Sessions

Slug: backend-parity-phase-c
Steps: 100
Status: completed
Created: 2026-08-06

## Context

Fulfills Phase C (Steps 41-60 originally, now expanded to 100 per protocol) of Plan 18. Focuses on the synthetic auth layer that enables "demo login" in preview mode. This plan implements the frontend bridge that allows reviewers to bypass the backend for auth while keeping claim shapes valid.

Links:
- Spec: [[.lovable/spec/tasks/21-backend-parity-phase-c.md]]
- Command: [[.lovable/spec/commands/11-backend-seed-login-e2e-error-manage.md]]
- Issue: [[.lovable/issues/04-preview-cannot-exercise-ui-without-backend.md]]

Release Policy: No version bump or changelog entry on any intermediate step. The minor version bump occurs only when the ENTIRE Plan 18 is completed.

## Steps

1. Read `src/lib/demo-identities.ts` to confirm stable Admin, Reseller, and Portal credentials. (See spec-21)
2. Read `src/routes/_authenticated.tsx` to identify the session check and redirect logic. (See spec-21)
3. Create `src/lib/preview-auth.ts` skeleton with `signInWithSeedIdentity` and `clearPreviewSession`. (See spec-21)
4. Implement `signInWithSeedIdentity` to generate a synthetic JWT and user object. (See spec-21)
5. Map `AuthSessionResource` Zod schema from `src/generated/api/schema.d.ts` to synthetic claims. (See spec-21)
6. Ensure `MeUser` claims in the synthetic session include all required RBAC permissions for Admin. (See spec-21)
7. Ensure `MeUser` claims in the synthetic session include all required RBAC permissions for Reseller. (See spec-21)
8. Ensure `MeUser` claims in the synthetic session include all required RBAC permissions for Portal. (See spec-21)
9. Implement `getPreviewSession` to read from `sessionStorage` (preview sessions must not persist to local storage). (See spec-21)
10. Integrate `getPreviewSession` with the existing `useAuth` hook in `src/hooks/use-auth.ts`. (See spec-21)
11. Add a conditional check in `useAuth` to prefer the preview session if `isPreview()` is true. (See spec-21)
12. Create `src/components/auth/DemoLoginPanel.tsx` skeleton. (See spec-21)
13. Design `<DemoLoginPanel />` with a "surface-elevated" background and subtle border. (See spec-21)
14. Add "Sign in as Admin" button to `<DemoLoginPanel />` with a distinctive icon. (See spec-21)
15. Add "Sign in as Reseller" button to `<DemoLoginPanel />` with a distinctive icon. (See spec-21)
16. Add "Sign in as Portal" button to `<DemoLoginPanel />` with a distinctive icon. (See spec-21)
17. Implement the `onClick` handler for each button to call `signInWithSeedIdentity`. (See spec-21)
18. Add a "Mode: Preview" badge to the panel to clarify context. (See spec-21)
19. Ensure the panel uses the project's semantic tokens for typography and colors. (See spec-21)
20. Add `aria-label` to the demo panel container for accessibility. (See spec-21)
21. Add `aria-description` explaining that these are seed-mode credentials. (See spec-21)
22. Implement `Shift+D Shift+D` hotkey listener inside the login route to toggle the panel. (DONE - Ctrl+Shift+D implemented in panel instead)
23. Ensure the hotkey listener is only registered when `isPreview()` is true. (See spec-21)
24. Read `src/routes/admin.login.tsx` to determine the best insertion point for the panel. (See spec-21)
25. Modify `src/routes/admin.login.tsx` to conditionally render `<DemoLoginPanel />`. (See spec-21)
26. Use `React.lazy` for `<DemoLoginPanel />` to keep it out of the main bundle. (See spec-21)
27. Add a `<Suspense>` fallback for the lazy-loaded panel. (See spec-21)
28. Wrap the panel in a `ClientOnly` guard to prevent SSR hydration mismatches. (See spec-21)
29. Verify the panel is hidden when `isPreview()` is false in `admin.login.tsx`. (See spec-21)
30. Read `src/routes/portal.login.tsx` (if exists) and repeat the panel integration. (See spec-21)
31. Read `src/routes/reseller.login.tsx` (if exists) and repeat the panel integration. (See spec-21)
32. Update `src/lib/preview-transport.ts` to export a `hasActivePreviewSession` helper. (See spec-21)
33. Modify the logout handler in `src/hooks/use-auth.ts` to also call `clearPreviewSession`. (See spec-21)
34. Add a listener to the runtime mode toggle to clear the session when switching to "Production". (See spec-21)
35. Create `tests/preview-auth.test.ts` for unit testing the synthetic auth logic. (See spec-21)
36. Test: `signInWithSeedIdentity('admin')` produces correct claims. (See spec-21)
37. Test: `signInWithSeedIdentity('reseller')` produces correct claims. (See spec-21)
38. Test: `signInWithSeedIdentity('portal')` produces correct claims. (See spec-21)
39. Test: `clearPreviewSession` removes data from `sessionStorage`. (See spec-21)
40. Test: `getPreviewSession` returns null if nothing is stored. (See spec-21)
41. Create `tests/demo-login-panel.test.tsx` for component testing. (See spec-21)
42. Test: `<DemoLoginPanel />` renders all three identity buttons. (See spec-21)
43. Test: Clicking the admin button triggers the expected sign-in call. (See spec-21)
44. Test: Hotkey `Shift+D Shift+D` toggles visibility. (See spec-21)
45. Test: Panel is not visible if `isPreview()` returns false. (See spec-21)
46. Read `linter-scripts/check-preview-in-prod-bundle.py`. (See spec-21)
47. Extend the linter to flag any static import of `DemoLoginPanel.tsx` in a non-preview file. (See spec-21)
48. Add a linter rule to ensure `DemoLoginPanel` is always behind a `React.lazy` or runtime mode gate. (See spec-21)
49. Run the linter to verify the current baseline. (See spec-21)
50. Update `docs/testing/test-data.md` with the demo identity details. (See spec-21)
51. Document the `Shift+D Shift+D` shortcut in the project's keyboard shortcut registry (if exists). (See spec-21)
52. Create a subtask for the synthetic JWT generator implementation. (See ./subtasks/21-backend-parity-phase-c/SS-01-jwt-gen.md)
53. Create a subtask for the login UI styling and animations. (See ./subtasks/21-backend-parity-phase-c/SS-02-ui-styling.md)
54. Read `src/lib/preview-store.ts` to see if auth state should be synced there. (See spec-21)
55. Add `auth` key to `PreviewStore` to track session status globally. (See spec-21)
56. Implement `usePreviewAuth` hook to wrap the preview session logic. (See spec-21)
57. Refactor `useAuth` to use `usePreviewAuth` internally when in preview mode. (See spec-21)
58. Add a "Seed Mode Active" indicator to the top bar when authenticated as a demo user. (See spec-21)
59. Ensure the indicator links to a "Sign Out" or "Switch Mode" action. (See spec-21)
60. Read `src/components/ui/sonner.tsx` for toast integration. (See spec-21)
61. Trigger a success toast when a demo login succeeds. (See spec-21)
62. Trigger an info toast when switching runtime modes clears a session. (See spec-21)
63. Verify the `data-testid` attributes are present on all login buttons for Playwright. (See spec-21)
64. Create `tests/e2e/specs/plan-21/demo-login.spec.ts` skeleton. (See spec-21)
65. Playwright: Navigate to login, flip to preview mode, verify panel appears. (See spec-21)
66. Playwright: Click "Admin", verify redirect to `/admin`. (See spec-21)
67. Playwright: Verify the top bar shows "Admin" as the current user. (See spec-21)
68. Playwright: Log out, verify return to login screen. (See spec-21)
69. Playwright: Flip back to production mode, verify panel is gone. (See spec-21)
70. Ensure the synthetic session includes a unique `requestId` for error tracking parity. (See spec-21)
71. Add `operationId: 'auth.demo.login'` to the synthetic event log. (See spec-21)
72. Read `src/lib/lara-api-error.ts` to ensure synthetic auth errors are handled correctly. (See spec-21)
73. Mock a "locked account" scenario for a demo user to test error rendering. (See spec-21)
74. Add a tooltip to each demo login button describing the permissions it grants. (See spec-21)
75. Ensure the "Portal" demo user is redirected to `/portal` after login. (See spec-21)
76. Ensure the "Reseller" demo user is redirected to `/reseller` after login. (See spec-21)
77. Read `src/router.tsx` to ensure demo redirects are handled by the router. (See spec-21)
78. Add a `onSuccess` callback to `signInWithSeedIdentity` for post-login navigation. (See spec-21)
79. Implement a "Copy Password" button for each identity in the panel. (See spec-21)
80. Ensure the "Portal" demo user has at least one associated subscription in their claims. (See spec-21)
81. Ensure the "Reseller" demo user has a non-zero balance in their claims. (See spec-21)
82. Audit `src/lib/preview-auth.ts` for any hardcoded strings that should be in constants. (See spec-21)
83. Move identity strings to `src/lib/demo-identities.ts` if not already there. (See spec-21)
84. Add a "Refresh Session" button to the demo panel (simulates token refresh). (See spec-21)
85. Test: Refreshing the synthetic session updates the timestamp but keeps the user identity. (See spec-21)
86. Verify `Zod` validation on the synthetic session object before storing in `sessionStorage`. (See spec-21)
87. Add a "Clear Session" button to the demo panel for manual cleanup. (See spec-21)
88. Ensure the panel works in mobile viewport (responsiveness check). (See spec-21)
89. Add a "Close" icon to the panel (distinct from the hotkey toggle). (See spec-21)
90. Ensure the panel does not overlap with the main login form on small screens. (See spec-21)
91. Add a subtle animation (fade-in/slide-up) when the panel appears. (See spec-21)
92. Verify the animation respects `prefers-reduced-motion`. (See spec-21)
93. Read `src/styles.css` to confirm availability of transition utility classes. (See spec-21)
94. Add a developer note to `src/lib/preview-auth.ts` explaining the purpose of synthetic auth. (See spec-21)
95. Audit all new files for proper license headers and formatting. (See spec-21)
96. Run `tsgo` on all new files to verify type safety. (See spec-21)
97. Run the full test suite `bunx vitest run tests/preview-auth.test.ts tests/demo-login-panel.test.tsx`. (See spec-21)
98. Final check of the linter `check-preview-in-prod-bundle.py` against the final implementation. (See spec-21)
99. Record the successful implementation evidence in `docs/ui-baselines/plan21-auth-complete.json`. (See spec-21)
100. Close Plan 21: No release ceremony (Plan 18 is still in progress). (See spec-21)

## Verification

1. `bunx vitest run tests/preview-auth.test.ts`
2. `bunx vitest run tests/demo-login-panel.test.tsx`
3. `linter-scripts/run.sh` (must pass preview-in-prod-bundle check)
4. Playwright spec `tests/e2e/specs/plan-21/demo-login.spec.ts`
5. Visual inspection of the login screen in preview mode.

## Appended from prior pending tasks

None.
