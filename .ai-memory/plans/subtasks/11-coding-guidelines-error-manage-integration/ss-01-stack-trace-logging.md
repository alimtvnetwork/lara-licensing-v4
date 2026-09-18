# SS-01: Add gated stack-trace logging on LaraException + Throwable

Parent: 11-coding-guidelines-error-manage-integration
Slug: stack-trace-logging
Status: pending
Created: 2026-07-19

## Goal
BE currently logs LaraException metadata but not $e->getTraceAsString(). Unhandled Throwable also omits the trace. Add trace logging to a dedicated `lara-diag` Monolog channel so operators can correlate ErrorId -> full stack without leaking traces to callers.

## Changes
- `backend/config/logging.php`: add `lara-diag` channel (daily, 14-day retention, level=debug).
- `backend/bootstrap/app.php`:
  - In LaraException renderer: `Log::channel('lara-diag')->debug('lara.exception.trace', ['ErrorId'=>$e->errorId, 'Trace'=>$e->getTraceAsString()]);`
  - In Throwable renderer: `Log::channel('lara-diag')->error('lara.unhandled.trace', ['ErrorId'=>$errorId, 'Exception'=>$e::class, 'Trace'=>$e->getTraceAsString(), 'Previous'=>optional($e->getPrevious())?->getMessage()]);`
- Never include Trace in the response envelope.

## Verification
- Trigger a 500 in a feature test; assert log file `storage/logs/lara-diag-*.log` contains ErrorId + stack frames.
- Assert response body has NO `Trace` key.
