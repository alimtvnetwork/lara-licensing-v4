# Error Management Implementation Compliance Matrix

**Version:** 1.1.0  
**Verified:** 2026-07-21  
**Status:** Substantially implemented, not fully compliant

## Honest result

The Laravel and React error axis is substantially integrated, but the folder cannot honestly be called fully implemented. Core envelope, taxonomy, correlation, redaction, diagnostic trace, typed frontend error, shared store, modal, toast, and retry behavior exist. The remaining failures are specification drift, incomplete acceptance-criteria coverage, and an incomplete CI test selection that v1.1 corrects.

## Compliance matrix

| Invariant | Evidence | Enforcement | Result |
|---|---|---|---|
| Canonical `{Status, Attributes, Results}` envelope | `ApiEnvelope`, envelope matrix tests | Backend tests | Compliant |
| Closed-set backend and frontend error codes | Laravel config and `ApiErrorCodeType` | Two-way parity script plus taxonomy tests | Compliant |
| Typed domain exception path | `LaraException::make` | Architecture tests ban generic throws in controllers/services | Compliant in scanned layers |
| Framework validation and auth errors use envelope | Exception renderers in `bootstrap/app.php` | Core exception and envelope tests | Compliant |
| Unknown exceptions use safe 500 envelope | Throwable renderer | Trace and envelope tests | Compliant |
| Caller responses do not expose stack traces | Renderer and redactors | Trace logging tests | Compliant |
| Server diagnostics retain redacted stack traces | `lara-diag`, `TraceRedactor`, `DetailsRedactor` | Diagnostic-channel and redaction tests | Compliant |
| RequestId travels through header, envelope, and logs | `RequestIdMiddleware` | Propagation E2E test | Compliant: v0.670.0 mints a fallback UUIDv4, binds it to attribute + Log context, echoes X-Request-Id on the error response, and envelope Attributes.RequestId matches |
| ErrorId correlates 5xx response and diagnostic log | Renderer and `LaraException` | UUID and trace-correlation tests | Compliant |
| ErrorId correlates response and diagnostic log on 4xx | `LaraException` mints it and the renderer now returns it on every status | v0.671.0 renderer + EnvelopeShapeMatrixTest 4xx assertion | Compliant |
| Retry-After propagation | Exception headers and frontend metadata | Backend and frontend tests | Compliant |
| Frontend uses typed `LaraApiError` | `laraFetch`, envelope decoder | ESLint raw-fetch and raw-throw bans, Vitest | Compliant in linted source |
| Global error store | `error-store.ts` | Unit tests | Compliant |
| Global modal | `GlobalErrorModal.tsx` mounted at root | Unit and browser tests | Partial |
| Modal shows code, source component, timestamp | Modal shows code and correlation IDs | No source-component field; timestamp is stored but not displayed | Missing |
| Copy All includes frontend and backend context | Modal copies ErrorId only | No fallback textarea test or implementation | Missing |
| Synthetic `GEN-1000` for missing backend Code | Current decoder uses the Lara closed set | No matching implementation or test | Missing or obsolete requirement |
| Every API and server route uses the error axis | Laravel surface is strongly covered | No complete TanStack server-function and server-route inventory gate | Partial |
| Full error suite is required in CI | Workflow previously ran three selected backend files | v1.1 now runs all Architecture and Errors suites | Corrected |

## Specification contradictions requiring resolution

1. The active app backend is Laravel, while major parts of the error folder still mandate a Go `apperror` tier and delegated WordPress server. Those documents describe a different architecture and cannot serve as executable acceptance criteria for this application without an applicability marker.
2. The top-level acceptance criteria require caller-visible `Details` and `Stack` up to 40 frames, while the implemented security contract intentionally keeps traces server-side. Returning stack traces would contradict the redaction and correlation design.
3. The acceptance criteria require `GEN-1000`, while the app uses PascalCase closed-set Lara codes and `ServerError` or `UnknownServerError` fallbacks.
4. The top-level overview says ambiguity is medium and the health score is unscored. Earlier implementation reports treated passing selected tests as proof of full compliance despite those explicit warnings.
5. Resolved in v0.671.0: the renderer now returns Attributes.ErrorId on 4xx as well as 5xx, and EnvelopeShapeMatrixTest asserts the UUIDv4 shape. The correlation-first policy replaces the old "hide below 500" stance.

## Enforcement added in v1.1

The aggregated error-contract workflow now runs the complete backend `Architecture` and `Errors` suites instead of only three hand-picked files. Changes anywhere under this specification folder also trigger the workflow.

## Remaining closure rule

Full compliance requires resolving the contradictory acceptance criteria, implementing or retiring the missing modal requirements, fixing the strict-path RequestId gap, and adding a complete API/server-function inventory gate. Until those are complete, this folder must remain marked partial.