# SS-05: X-Request-Id end-to-end propagation

Parent: 11-coding-guidelines-error-manage-integration
Slug: request-id-propagation
Status: pending
Created: 2026-07-19

## Goal
Every request has a stable X-Request-Id, generated on FE, echoed by BE, present in logs, envelope, and any downstream call.

## Changes
- FE: `src/lib/lara-fetch.ts` generates `crypto.randomUUID()` on each request if header not already set, attaches to Request, reads back on response.
- BE middleware `App\Http\Middleware\RequestIdMiddleware`: accept incoming header, else generate; bind to `Log::withContext(['RequestId'=>$id])`; attach to Response headers; write into ApiEnvelope Attributes.
- Register middleware globally in `bootstrap/app.php` `withMiddleware()`.
- Update `ApiEnvelope::success/failure` to always include `RequestId` from context if not passed.

## Verification
- Curl: `curl -H "X-Request-Id: test-123" .../Api/... | jq .Attributes.RequestId` returns `test-123`.
- Feature test asserts response header + body + log line share the id.
- FE Sonner toast `[lara-toast]` console line prints the same id observed in server logs.
