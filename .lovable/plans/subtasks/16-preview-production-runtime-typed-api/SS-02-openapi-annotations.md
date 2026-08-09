---
Slug: openapi-annotations
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-02: Backend OpenAPI annotations

## Approach

Adopt `dedoc/scramble` (auto-inferring OpenAPI 3.1 for Laravel from FormRequest, Resource, and typed controller signatures). If the project already ships another generator, keep it; do not stack two.

## Steps

1. `composer require --dev dedoc/scramble` in `backend/`.
2. Publish config: `php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider"`.
3. Configure `config/scramble.php` to scan `app/Http/Controllers/Api` and emit stable operationIds (`{controller}.{method}` -> PascalCase).
4. For every controller under `backend/app/Http/Controllers/Api/**`:
   - Ensure request validation is a dedicated `FormRequest` subclass (Scramble reads rules to infer request schema).
   - Ensure responses go through a dedicated `JsonResource` or `Data` object (never a raw array).
   - Add PHPDoc `@response` / `@throws` annotations for any envelope shape Scramble cannot infer, especially `LaraException` error responses.
5. Emit a deterministic dump: `php artisan lara:openapi:export --out=build/openapi.json` (custom Artisan command wrapping Scramble's generator with sorted keys + stable ordering).
6. Commit `backend/build/openapi.json` and add it to the CI diff gate.

## Rules

- Every endpoint must document at minimum: 200/201, 400 validation error, 401 unauthorized, 403 forbidden, 404 not found (where applicable), 409 conflict (where If-Match applies), 412 precondition failed (where If-Match applies), 422 unprocessable, 429 rate-limited (with `Retry-After` header schema), 5xx canonical envelope.
- Error responses use the `LaraException` schema (`Attributes.ErrorCode` closed-set, `Attributes.RequestId`, `Attributes.ErrorId`, optional `Attributes.RetryAfter`).
- No endpoint may omit `X-Request-Id` request/response header from the spec.
