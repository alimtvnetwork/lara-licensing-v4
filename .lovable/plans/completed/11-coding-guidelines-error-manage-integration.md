# Coding-Guidelines + Error-Manage Full Integration (BE + FE)

Slug: coding-guidelines-error-manage-integration
Steps: 50
Status: completed
Created: 2026-07-19
Completed: 2026-07-19 (v0.447.0, step 50)
Step-to-version signoff: 01-32 -> v0.404.0..v0.429.0, 33 -> v0.430.0, 34-38 -> v0.431.0..v0.434.0, 39 -> v0.435.0, 40 -> v0.436.0, 41 -> v0.437.0, 42 -> v0.438.0, 43 -> v0.439.0 (branch-protection doc), 44 -> v0.440.0, 45 -> v0.441.0 + v0.442.0 (rev), 46 -> v0.443.0, 47 -> v0.444.0, 48 -> v0.445.0, 49 -> v0.446.0, 50 -> v0.447.0.

## Context

Integrate spec/02-coding-guidelines/ and spec/03-error-manage/ into backend (Laravel) and frontend (TanStack Start) so every error flows through LaraException -> canonical envelope -> LaraApiError -> Global Error Modal / toast, with RequestId + ErrorId correlation, gated stack-trace logging, closed-set parity, and lint gates in CI. Files involved: backend/bootstrap/app.php, backend/app/Exceptions/LaraException.php, backend/app/Http/Controllers/**, backend/config/lara.php, backend/config/logging.php, src/lib/lara-api-error.ts, src/lib/lara-fetch.ts, src/lib/use-lara-error-toast.ts, src/lib/error-capture.ts, src/lib/lovable-error-reporting.ts, src/routes/__root.tsx, src/components/errors/**, scripts/**, .github/workflows/**.

Related captured artifacts:
- Command: .lovable/spec/commands/07-error-manage-and-coding-guidelines-integration.md
- Prior pending plans pulled into "Appended" section below.

## Steps

1. Read every file under spec/02-coding-guidelines/ (all subfolders 01..24) and produce spec/03-error-manage/98-audit-input.md summarizing rules that affect error paths.
2. Read every file under spec/03-error-manage/ and record the closed-set error taxonomy source of truth path in the same audit file.
3. Diff current `backend/config/lara.php::error_codes` vs `src/lib/lara-api-error.ts::ApiErrorCodeType` and record the mismatch table.
4. Implement closed-set parity CI check. See ./subtasks/11-coding-guidelines-error-manage-integration/SS-02-error-code-parity-ci.md.
5. Wire `bun run check:error-codes` into `.github/workflows/lint.yml` as a required job.
6. Add `lara-diag` Monolog channel in `backend/config/logging.php` (daily, 14-day retention).
7. Add gated stack-trace logging on LaraException + Throwable renderers. See ./subtasks/11-coding-guidelines-error-manage-integration/SS-01-stack-trace-logging.md.
8. Assert via feature test that Trace never appears in response bodies (grep the JSON for `"Trace"`).
9. Introduce `App\Http\Middleware\RequestIdMiddleware` and register globally. See ./subtasks/11-coding-guidelines-error-manage-integration/SS-05-request-id-propagation.md.
10. Update `ApiEnvelope::success/failure` to always inject RequestId + ErrorId (when 5xx) from Log context.
11. Add BE Pest test `tests/Feature/Errors/EnvelopeShapeTest.php` asserting {Status, Attributes:{RequestId}, Results} on 200/400/401/403/404/409/422/429/500.
12. Extend the same test to assert every 5xx envelope carries `Attributes.ErrorId` and matches the logged ErrorId.
13. Sweep `backend/app/Http/Controllers/**` for `abort(...)` and raw `response()->json([...], 4xx)` calls; replace each with `throw LaraException::make('CodeName', ...)`.
14. Sweep `backend/app/Services/**` for `throw new RuntimeException` / `InvalidArgumentException` in domain paths; replace with `LaraException::make` referencing a closed-set code.
15. Add PHPStan/Larastan rule (or a Pest architecture test) that forbids `abort(` and bare `response()->json` with a 4xx/5xx literal in controllers.
16. Enforce PascalCase JSON keys via a serialization test that inspects every `JsonResource::toArray` output and rejects snake_case keys.
17. Enforce 15-line function body cap in PHP: add a Pest architecture test using PhpParser on `backend/app/`; whitelist only the closed set of exceptions in `spec/02-coding-guidelines/04-php/`.
18. Add magic-literals linter run (BE + FE) to CI. See ./subtasks/11-coding-guidelines-error-manage-integration/SS-03-magic-literals-audit.md.
19. Fix all violations reported by step 18; commit report to spec/03-error-manage/98-magic-literals-report.md.
20. Add `Retry-After` header propagation test for `RateLimited` and `QuotaExhausted` codes; assert header equals `LaraException::$headers['Retry-After']`.
21. Add `errorId` UUID-v4 shape test on `LaraException::newErrorId()` and assert uniqueness across 10k invocations.
22. Backfill `spec/03-error-manage/03-error-code-registry/` with any codes missing from the enum by scanning grep of throws.
23. Add BE middleware `AssertEnvelopeMiddleware` (dev+test only) that fails the response if body does not match envelope schema; wire into `Api` middleware group.
24. Introduce `src/lib/lara-fetch.ts` (or update the existing fetch wrapper) to: generate X-Request-Id, parse envelope, throw `LaraApiError` on non-2xx, preserve RequestId + ErrorId + Details.
25. Replace every direct `fetch(` and `axios(` call in `src/` with `laraFetch(...)`; add ESLint rule `no-restricted-globals: ["fetch"]` scoped to `src/` (allow-list `lara-fetch.ts`).
26. Add TS strict type for envelope: `src/lib/lara-envelope.ts` with `LaraEnvelope<T>` and a Zod parser used inside `laraFetch`.
27. Update `src/lib/lara-api-error.ts` to expose `isRetryable()` returning true for codes in `RETRYABLE_CODES` closed set.
28. Introduce `src/lib/error-store.ts` (Zustand) with `pushError`, `dismiss`, `current` selectors.
29. Build `src/components/errors/GlobalErrorModal.tsx`. See ./subtasks/11-coding-guidelines-error-manage-integration/SS-04-fe-error-boundary-hierarchy.md.
30. Mount GlobalErrorModal in `src/routes/__root.tsx` alongside `<Toaster />`.
31. Update `useLaraErrorToast` to suppress toast when the same LaraApiError instance is already in the modal store.
32. Set every route-level `errorComponent` to call `reportLovableError` AND `pushError` so React errors and API errors share the same surface.
33. Ensure `src/lib/error-capture.ts` `globalThis` listeners forward captured errors to `pushError` when they are LaraApiError instances.
34. Add FE unit test (Vitest) covering `laraFetch` happy path, 4xx (Details preserved), 5xx (ErrorId preserved), network failure (synthesizes `NetworkUnavailable` code).
35. Add Playwright spec `tests/e2e/specs/error-modal.spec.ts` covering: 500 shows modal, 429 shows Retry, RequestId copy button copies to clipboard.
36. Add Playwright axe check on the open modal; require zero WCAG 2.1 AA violations.
37. Add `spec/03-error-manage/02-error-architecture/07-logging-and-diagnostics/` runbook: how to grep `lara-diag-*.log` by ErrorId.
38. Add `docs/error-handling-cheatsheet.md` for contributors: BE throw pattern, FE surface pattern, DO/DONT list.
39. Add ESLint rule `no-restricted-syntax` blocking `throw new Error(` inside `src/` outside `lara-api-error.ts` (force typed errors).
40. Add PHP CS Fixer rule / Rector rule blocking `throw new \Exception(` and `throw new \RuntimeException(` outside `App\Exceptions\`.
41. Add CI job `error-contract.yml` chaining: check-error-codes, envelope-shape tests, magic-literals lint, PHP architecture tests, Vitest error tests, Playwright error-modal spec.
42. Add status badge for `error-contract.yml` to README.md.
43. Backfill Pest tests for every controller that throws a domain error: assert HTTP status, envelope shape, ErrorCode value, Details schema.
44. Add FE storybook (or MDX) entry `src/components/errors/GlobalErrorModal.stories.tsx` covering: generic 500, validation 400 with 3 field errors, retryable 429, forbidden 403, offline.
45. Add SSR-safe guard: `error-capture.ts` and `lovable-error-reporting.ts` must no-op when `typeof window === 'undefined'`; add Vitest SSR simulation test.
46. Add `src/lib/error-messages.ts` mapping every `ApiErrorCodeType` value to a human message; enforce exhaustiveness via TS `satisfies Record<ApiErrorCodeType, string>`.
47. Add BE feature test that fires ValidationException, AuthenticationException, AuthorizationException, ThrottleRequestsException and asserts each is re-shaped into the canonical envelope with the correct ErrorCode.
48. Add CHANGELOG entry to `spec/03-error-manage/98-changelog.md` documenting v0.4XX.0 integration bump.
49. Run full CI locally (`bun run check:error-codes && cd backend && ./vendor/bin/pest && cd .. && bun run test && bun run test:e2e:smoke`) and record pass/fail per step in `spec/03-error-manage/99-integration-report.md`.
50. Move this plan from `.lovable/plans/pending/11-...md` to `.lovable/plans/completed/11-...md` and flip `Status: pending` -> `Status: completed`; bump project version and update mem://index.md to reference the new error-contract axis.

## Verification

- CI job `error-contract.yml` green on the integration branch.
- `check-error-codes` script exits 0; BE and FE closed sets are identical.
- Pest `EnvelopeShapeTest` passes across 200/400/401/403/404/409/422/429/500.
- `storage/logs/lara-diag-*.log` contains ErrorId + stack for a forced 500; response body contains no `Trace` key.
- Playwright `error-modal.spec.ts` passes with axe = 0 violations.
- ESLint + PHP CS Fixer + magic-literals linter all exit 0 in CI.
- Manual: `curl -H "X-Request-Id: abc" .../Api/BadRoute` returns envelope with `Attributes.RequestId == "abc"` and log line shares the same id.

## Appended from prior pending tasks

Pulled from `.lovable/plans/pending/` scan (not yet completed):
- 05-rbac-quota-tier-environment.md (runtime RBAC/quota enforcement) - out of scope for this plan; tracked separately.
- 06-laravel-be-fe-and-publish.md (publish wiring) - out of scope; tracked separately.
- 07-ui-spec-conformance-and-code-finetune.md - out of scope; tracked separately.
- 09-fluid-ui-and-cpanel-release.md - out of scope; tracked separately.

None of the above are folded into the 50 steps; they remain in `pending/` and will be addressed in their own plans. This plan is scoped strictly to coding-guidelines + error-manage integration per user command 07.
