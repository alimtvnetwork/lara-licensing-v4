# Error Management, Changelog

**Version:** 3.4.0
**Last Updated:** 2026-07-19

---

## v0.432.0 - v0.444.0 - 2026-07-19 (app-repo Plan 11 steps 35-47 integration bump)

Rollup of the error-management integration work landed across app-repo minor releases v0.432.0 through v0.444.0. Each app-repo release is tied to a Plan 11 step; this section records the spec-visible effect so `spec/03-error-manage/` stays authoritative.

- **v0.432.0 (step 35), retry classifiers + FE error store:** canonical `isRetryable(status, code)` + Zustand error store feed both `GlobalErrorModal` and `useLaraErrorToast`. Registry now assumes the FE surfaces `Retryable429`, `Forbidden403`, `Generic500`, `Validation400`, and `Offline` from a single store.
- **v0.433.0 (step 36), `GlobalRateLimitBanner` + `useSubmitLock`:** `Retry-After` seconds propagate from BE envelope headers into a countdown banner and a per-form submit lock. Spec section on 429 handling now requires this pair on any form that can trigger `RateLimited`.
- **v0.434.0 (step 37), operator runbook + contributor cheatsheet:** `docs/error-manage/` gained `runbook-error-id-correlation.md` and `cheatsheet-canonical-seams.md`. Spec cross-references these as the operator-facing docs for `ErrorId` -> log correlation.
- **v0.435.0 (step 39), FE `no-restricted-syntax` ban on raw `Error`:** ESLint rejects `throw new Error/TypeError/RangeError/SyntaxError` in `src/`. Spec now treats `LaraApiError` (client-synthesized) and envelope-parsed errors as the only permitted FE error surfaces.
- **v0.436.0 (step 40), BE `RawExceptionBanArchitectureTest`:** Pest architecture test scans `backend/app/` for raw `throw new Exception(...)`. Mirror parity with the FE gate; spec closes the "raw throw" loophole on both stacks.
- **v0.437.0 (step 41), aggregated `error-contract.yml` GitHub workflow:** single required status check runs parallel FE (parity + lint + Vitest) and BE (Pest architecture + envelope matrix) jobs. Spec references this workflow as the branch-protection gate for the error contract.
- **v0.438.0 (step 42), `error-contract` badge in README Status:** contract gate visibility guarantee; spec now assumes new sessions can see the gate status without reading workflow yaml.
- **v0.439.0 (step 43), branch-protection docs update:** `docs/ci/branch-protection.md` lists five required checks including `error-contract`. Spec references this as the authoritative "which checks must be required" document.
- **v0.440.0 (step 44), `GlobalErrorModal` stories:** `src/components/errors/GlobalErrorModal.stories.tsx` covers `Generic500ServerError`, `Validation400ThreeFieldErrors`, `Retryable429RateLimited`, `Forbidden403`, `OfflineNetworkFailure`. Spec now cites these as the visual reference for the five canonical FE failure surfaces.
- **v0.441.0 / v0.442.0 (step 45 + rev 2), SSR error-capture path:** `src/lib/error-capture.ts` arms `globalThis` `error`/`unhandledrejection` listeners under Cloudflare workerd so `src/server.ts` can recover the real stack behind h3-swallowed HTTPError bodies. Spec section on SSR error observability now normative: SSR listeners bind on `globalThis`, not `window`, and consume-on-read TTL prevents cross-request leaks.
- **v0.443.0 (step 46), canonical `src/lib/error-messages.ts`:** `errorMessages` typed `as const satisfies Record<ApiErrorCodeType, string>` moves the exhaustiveness gate off `copyForErrorCode` onto a dedicated static seam. Spec now points here as the FE ground truth for closed-set copy.
- **v0.444.0 (step 47), BE Pest suite `CoreExceptionEnvelopeParityPest`:** iterates the full `config('lara.error_http_status')` catalog, throws `LaraException::make($code)` per code, and asserts canonical envelope + matching `Attributes.Error.ErrorCode` + HTTP status. Complements `EnvelopeShapeMatrixTest` (one code per status) with full-catalog drift coverage; spec's closed-set taxonomy is now guarded end-to-end.

Net effect on spec: the error-management contract is now enforced by parallel FE and BE gates on every PR, every closed-set code is exercised by tests (not just one per bucket), SSR errors surface their real stack in Server Logs, and operators have a documented `ErrorId` correlation path from envelope to log line.

---



## v0.431.0 - 2026-07-19 (app-repo Plan 11 step 34)

- Locked the `laraFetch` failure surface with a fifth Vitest case: an upstream 200 with a non-envelope (HTML) body now has an explicit assertion that it exits as `LaraApiError(ServerError)`, so the error store, Global Error Modal, and toast surface never see a raw parse error.
- Documented the "no NetworkUnavailable code" decision in-code: adding a FE-only code would break BE/FE closed-set parity (`scripts/check-error-code-parity.mjs`); `ServerError + httpStatus === 0` remains the canonical client-synthesized marker for pure network unreachability, while malformed envelopes preserve the upstream HTTP status so ops can distinguish "no connection" from "bad gateway body".

## v3.3.0 - 2026-07-15


### Verification claims corrected

- Replaced unsupported production-ready and 100/100 claims with evidence-based status.
- Corrected the root inventory and documented known naming exceptions.
- Limited link verification to the root overview links actually checked against disk.

---

## v2.2.0 — 2026-04-02

### Domain Convenience Constructors + Error Merge

#### Added — Domain convenience constructors (in `02-apperror-struct.md`)
- `UrlError(errType, url)` / `WrapUrlError(cause, errType, url)` — auto-sets `WithUrl()`
- `SlugError(errType, slug)` / `WrapSlugError(cause, errType, slug)` — auto-sets `WithSlug()`
- `SiteError(errType, siteId)` / `WrapSiteError(cause, errType, siteId)` — auto-sets `WithSiteId()`
- `EndpointError(errType, method, ep, statusCode)` / `WrapEndpointError(...)` — auto-sets `WithEndpoint()` + `WithMethod()` + `WithStatusCode()`
- Convenience summary table (section 2.2.6)

#### Added — Error merge methods (in `02-apperror-struct.md`)
- `Merge(errors)` — combines multiple `AppError` into one, uses first error's code
- `MergeWithCode(code, message, errors)` — merges under a specific error code
- Batch validation and multi-step processing examples

---

## v2.1.0 — 2026-04-02

### WrapTypeMsg Constructor + Path Convenience Methods

#### Added — `WrapTypeMsg` constructor (in `02-apperror-struct.md`)
- `WrapTypeMsg(cause error, errType ErrorType, message string)` — wraps with enum code but custom message
- Enables 3-level progression: `Wrap()` → `WrapType()` → `WrapTypeMsg()`

#### Added — Path convenience constructors (in `02-apperror-struct.md`)
- `PathError(errType, path)` — creates path-related AppError with automatic `WithPath()` diagnostic
- `WrapPathError(cause, errType, path)` — wraps cause with path variant + automatic `WithPath()` diagnostic

#### Added — New path variants (in `05-apperrtype-enums.md`)
- `PathMissing` (E4016) — required path is missing
- `PathFailedToCreate` (E4017) — failed to create path
- `PathFailedToRead` (E4018) — failed to read path
- `PathFailedToWrite` (E4019) — failed to write to path
- `PathFailedToDelete` (E4020) — failed to delete path

#### Changed — Root `readme.md`
- Expanded CODE-RED-005/006 example from 2 levels to 3-level progression (✅ → ✅✅ → ✅✅✅)
- Added `PathError` / `WrapPathError` usage examples

---

## v2.0.0 — 2026-04-02

### `apperrtype` v2 Migration — Single Variation Enum

**Breaking change:** Migrated from per-domain `byte` enums to a single `uint16 Variation` enum with global registry. Inspired by [evatix-go/errorwrapper/errtype](https://gitlab.com/auk-go/errorwrapper/-/tree/develop/errtype).

#### Changed — `05-apperrtype-enums.md` (full rewrite)
- Replaced 14 per-domain `byte` enums (`PluginError`, `ConfigError`, etc.) with single `Variation uint16`
- Replaced `ErrorDetail{Code, Message}` with `VariantStructure{Name, Code, Message, Variant}`
- Replaced per-domain detail maps with single `variantRegistry map[Variation]VariantStructure`
- `ErrorType` interface gains `Name() string` method
- Added display methods on `Variation`: `String()`, `CodeTypeName()`, `CodeTypeNameWithReferences()`
- Added display methods on `VariantStructure`: `TypeNameCodeMessage()`, `CodeTypeNameWithMessage()`, `Error()`, `ErrorNoRefs()`, `Panic()`
- Added `IsValid()` and `Structure()` methods on `Variation`
- Expanded domains: E15xxx (Network), E16xxx (Process), E17xxx (Encoding), E18xxx (Permission)
- Added migration table documenting v1→v2 mapping

#### Added — `StringToVariantMap` (in `05-apperrtype-enums.md`)
- New `string_to_variant_map.go` — reverse-lookup from PascalCase name → `Variation`
- `VariationFromName(name) (Variation, bool)` — safe lookup
- `MustVariationFromName(name) Variation` — panics if not found

#### Added — `CodeToVariantMap` (in `05-apperrtype-enums.md`)
- New `code_to_variant_map.go` — reverse-lookup from string code (e.g. `"E2010"`) → `Variation`
- `VariationFromCode(code) (Variation, bool)` — safe lookup
- `MustVariationFromCode(code) Variation` — panics if not found

#### Changed — `02-apperror-struct.md`
- Updated `NewType` / `WrapType` constructor signatures to accept `apperrtype.ErrorType`
- Added section 2.3.1: Variation display methods with corrected signatures and examples
- Added section 2.3.2: `Structure()` lookup with `VariantStructure` display method table
- Added section 2.3.3: Direct error creation from `VariantStructure` (`Error()`, `ErrorNoRefs()`, `Panic()`)
- Fixed all example output formats to match actual `05-apperrtype-enums.md` implementations
- Replaced non-existent variants (`DatabaseTimeout`, `ConfigMissing`) with valid ones

#### Changed — `04-codes-and-policy.md`
- Replaced v1 `PluginError byte` + `ErrorDetail` + per-domain map examples with v2 `Variation` + `VariantStructure` + `variantRegistry`
- Updated rules section to reflect single-enum architecture
- Fixed spec cross-reference link to point to `05-apperrtype-enums.md`

#### Changed — Root `readme.md`
- Updated `apperrtype` package section from v1 pattern to v2
- Added `VariantStructure`, `variantRegistry`, `StringToVariantMap` documentation
- Added `VariationFromName()` reverse-lookup example
- Fixed spec link from `04-codes-and-policy.md` to `05-apperrtype-enums.md`

#### Files Modified
| File | Change |
|------|--------|
| `02-error-architecture/06-apperror-package/01-apperror-reference/05-apperrtype-enums.md` | Full rewrite to v2 |
| `02-error-architecture/06-apperror-package/01-apperror-reference/02-apperror-struct.md` | Display methods + signature fixes |
| `02-error-architecture/06-apperror-package/01-apperror-reference/04-codes-and-policy.md` | v1→v2 examples |
| `readme.md` (project root) | v1→v2 apperrtype section |

---

## v1.0.0 — 2026-03-31

### Initial Consolidation

#### Added
- Created `04-error-manage/` as the single canonical location for all error management specs
- Organized into 3 categories: Error Resolution, Error Architecture, Error Code Registry
- New `00-overview.md` with core principles, common pitfalls, and cross-references

#### Consolidated From

#### Structure
- `01-error-resolution/` — Retrospectives, verification patterns, debugging guides, cheat sheet, cross-reference diagram
- `02-error-architecture/` — Error handling reference, delegation fix, notification colors, error modal, response envelope, apperror, logging
- `03-error-code-registry/` — Master registry, integration guide, collision resolution, utilization report, overlap validator, schemas, scripts, templates

---

*Keep this file updated when specs change.*
