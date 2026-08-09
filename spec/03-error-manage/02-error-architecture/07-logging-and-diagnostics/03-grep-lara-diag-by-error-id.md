# Runbook: Grep `lara-diag-*.log` by ErrorId

**Audience**: on-call operators, support engineers, backend developers debugging a caller-reported failure.
**Prereq**: SSH or filesystem access to `backend/storage/logs/` on the target environment.

## 1. Why this runbook exists

Plan 11 step 6 (`config/logging.php`) created the dedicated `lara-diag` daily channel and step 7 (`bootstrap/app.php`) wired every `LaraException` and unhandled `\Throwable` through it. The response envelope for a 5xx failure carries `Attributes.Error.ErrorId` (see `bootstrap/app.php` line 97 and line 189) but **never** carries the stack trace itself: `TraceRedactor::redactString` output is written only to `lara-diag`. Support tickets from the field will always include the `ErrorId` (the Global Error Modal exposes it verbatim via the Copy button, verified by `tests/e2e/specs/error-modal.spec.ts`), and this runbook is the documented path from that `ErrorId` back to the redacted stack trace.

## 2. Log location

- Path: `backend/storage/logs/lara-diag-YYYY-MM-DD.log` (Laravel daily driver).
- Retention: `LARA_DIAG_DAYS` environment variable, default 14 days (`config/logging.php` line 67).
- Minimum level: `LARA_DIAG_LEVEL`, default `debug` (`config/logging.php` line 66). Stack traces are written at `debug` for handled `LaraException` and at `error` for unhandled `\Throwable`.
- Response bodies **never** contain a `Trace` key. If you see one in a response, that is a bug: open an issue against spec/03-error-manage §4.3.

## 3. Canonical fields per entry

Every entry is a single JSON object. Grep the fields directly:

| Field | Source | Notes |
| --- | --- | --- |
| `RequestId` | `X-Request-Id` header on the inbound request | Correlates FE + BE + gateway logs. |
| `ErrorId` | `LaraException::$errorId` (handled) or `bin2hex(random_bytes(8))` (unhandled) | Hex string. Present on every 5xx envelope. |
| `ErrorCode` | Closed-set from `spec/03-error-manage/03-error-code-registry/` | Only on handled `lara.exception.trace`. |
| `Exception` | Fully qualified PHP class | e.g. `App\Support\LaraException`, or the original throwable class for unhandled. |
| `Trace` | `TraceRedactor::redactString($e)` | Stack trace with sensitive frame args replaced by `***REDACTED***`. |
| `Previous` | `$e->getPrevious()?->getMessage()` | Optional; present when the exception chained. |
| `channel` | Laravel adds automatically | Always `lara-diag`. |
| `context.message` | Laravel adds automatically | `lara.exception.trace` or `lara.unhandled.trace`. |

The parallel `lara.exception` / `lara.unhandled` entries on the default `stack` channel carry `Route`, `Method`, `HttpStatus`, and redacted `Details`, but not the trace. Cross-reference by `ErrorId` when you need both.

## 4. The one command you almost always run

```bash
cd backend/storage/logs
grep -F "\"ErrorId\":\"<paste-error-id>\"" lara-diag-*.log
```

`-F` treats the pattern as a fixed string so hex characters and JSON escapes cannot re-enter regex mode. The double-quote escapes anchor to the JSON key so a partial hex collision inside a message body cannot match.

Pretty-print each match:

```bash
grep -F "\"ErrorId\":\"<paste-error-id>\"" lara-diag-*.log \
  | sed 's/^[^{]*//' \
  | jq -C '{RequestId, ErrorId, ErrorCode, Exception, Previous, Trace}'
```

The `sed` strips the Laravel line prefix (`[timestamp] env.LEVEL: message `) so `jq` sees a single JSON object.

## 5. Multi-day search

Daily rotation keeps each day in its own file. When you do not know the day:

```bash
grep -F -r "\"ErrorId\":\"<paste-error-id>\"" backend/storage/logs/
```

`-r` is safe because `lara-diag` is the only channel that logs the `ErrorId` field name; `laravel-*.log` uses different keys, and the recursive walk will still finish in milliseconds on a 14-day window.

## 6. Correlating with the operator log

Every `lara-diag` trace has a sibling `lara.exception` (or `lara.unhandled`) entry on the default `stack` channel. To fetch both:

```bash
# lara-diag: the trace
grep -F "\"ErrorId\":\"$EID\"" backend/storage/logs/lara-diag-*.log

# stack (laravel-*.log): route, method, redacted Details
grep -F "\"ErrorId\":\"$EID\"" backend/storage/logs/laravel-*.log
```

If the stack entry has `Details`, those fields have already passed through `DetailsRedactor::redact` (Plan 11 step 33), so sensitive values are masked in place. Do **not** re-enable raw logging to "see the real value": the redaction is contractual (spec/03-error-manage §4.2, AC-ERR-005).

## 7. Correlating with the caller

The Global Error Modal in the frontend exposes:

- `data-testid="global-error-error-id"` with the same hex `ErrorId` (5xx only).
- `data-testid="global-error-request-id"` with the same `RequestId`.
- A Copy button that writes `ErrorId` to the OS clipboard (Chromium verified in `tests/e2e/specs/error-modal.spec.ts`).

When a support ticket contains only the `RequestId` (4xx caller-error case where the envelope intentionally omits `ErrorId`, see `bootstrap/app.php` line 97), grep by `RequestId`:

```bash
grep -F "\"RequestId\":\"<paste-request-id>\"" backend/storage/logs/lara-diag-*.log
```

## 8. Nothing matches

| Symptom | Root cause | Remedy |
| --- | --- | --- |
| Zero hits across all daily files | `ErrorId` older than `LARA_DIAG_DAYS` and the log file was rotated out | Escalate; the trace is gone. Ask the caller to reproduce with fresh telemetry. |
| Hits only on `laravel-*.log`, not on `lara-diag-*.log` | The 4xx path intentionally does not log to `lara-diag` (client errors carry no trace) | Expected. The stack entry has everything you can have. |
| `Trace` value appears literal, not JSON | Log file was concatenated or truncated mid-line | Fetch the full daily file from backup; do not trust a partial line. |
| `Trace` contains `***REDACTED***` in a frame you need | Working as designed (`TraceRedactor`, Plan 11 step 8) | Reproduce locally with `LARA_TRACE_REDACT=off` in a sealed dev environment; never disable in production. |

## 9. Related specs and tests

- `spec/03-error-manage/02-error-architecture/07-logging-and-diagnostics/00-overview.md`: channel design.
- `spec/03-error-manage/02-error-architecture/05-response-envelope/`: shape guarantees for `ErrorId` and absence of `Trace`.
- `backend/tests/Feature/Errors/ApiEnvelopeDetailsRedactionTest.php`: proves the response body never leaks a credential.
- `backend/tests/Feature/Errors/DetailsRedactorTest.php`: proves the `Details` masking used on the operator log side.
- `tests/e2e/specs/error-modal.spec.ts`: proves the FE surfaces the `ErrorId` operators grep for.
