# Command 07: Integrate error-manage + coding-guidelines across BE/FE

Command (verbatim from user):
"integrate the coding guideline and error manage properly using the error model, and everything needs to be very accurate in back and front end."

Scope:
- Backend (Laravel, backend/) and Frontend (TanStack Start, src/).
- Sources of truth: spec/02-coding-guidelines/ (all subfolders) and spec/03-error-manage/ (all subfolders).
- Error model: LaraException on BE, LaraApiError + ApiErrorCodeType on FE, canonical envelope {Status, Attributes, Results}, RequestId/ErrorId correlation.

When it applies:
- Every code change on BE controllers, services, exceptions, middleware.
- Every FE mutation, loader, error boundary, toast surface.
- CI: parity checks (closed-set error codes BE<->FE), lint for magic literals, logging discipline.

Non-negotiables:
- No magic strings/numbers in domain code.
- PascalCase DB/JSON keys.
- 15-line function body cap.
- Never swallow errors; every throw carries an ErrorCode present in config('lara.error_codes').
- Server stack traces MUST be logged (gated by channel), never returned to callers.
- Every 5xx response carries ErrorId in Attributes for correlation.
