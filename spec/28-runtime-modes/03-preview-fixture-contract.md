# Preview Fixture Contract

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`preview` · `fixture` · `handler` · `operation-id` · `envelope` · `lara-exception` · `if-match` · `etag` · `latency` · `scenario` · `seed-store`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ (`spec/28-runtime-modes/00-overview.md`) |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the exact contract every preview handler under `src/lib/preview-fixtures/**` MUST honor so that (a) every `operationId` in `src/generated/api/operations.ts` has a matching handler (INV-RM-04), (b) every response, success or failure, matches the canonical `LaraException` envelope shape the FE consumes in `production` (INV-RM-05), and (c) latency, offline, rate-limit, and error scenarios are exercisable without code changes.

## Handler Signature

Every preview handler is registered by `operationId` and MUST match this TypeScript signature:

```ts
export type PreviewHandler<Op extends keyof Operations> = (ctx: {
  Params: Operations[Op]["Request"];
  Headers: Record<string, string>;
  Signal: AbortSignal;
  Store: PreviewStore;   // IndexedDB-backed; see 03-preview-fixture-contract §Seed-Store Side Effects
  Seed: PreviewSeed;     // "default" | "empty" | "error"
  Scenario: PreviewScenario | null; // "offline" | "slow" | "rate-limited" | null
  RequestId: string;     // uuidv7, per-call
}) => Promise<Operations[Op]["Response"]>;
```

Rules:

- **H-01.** Handlers are pure `async` functions. No module-level mutable state outside `Store`. Function bodies obey the 15-line cap (project memory Core); factor helpers freely.
- **H-02.** Handlers MUST NOT call `fetch` or touch `window.location`. Enforced by `check-untyped-fetch.py` (Plan 16 Step 73).
- **H-03.** Handlers MUST throw a `LaraException` (never return an ok envelope with error fields; never throw a bare `Error`). The transport wraps thrown exceptions into the canonical failure envelope.
- **H-04.** Handlers MUST honor `Signal.aborted` before any expensive step and throw `LaraException.aborted()` when set. This lets React Query cancellations propagate identically to production.

## Envelope Shape

Success and failure share the top-level shape; only `Data` vs `Errors` differs.

Success:

```json
{
  "Data": { "...operation-specific payload..." },
  "Attributes": {
    "RequestId": "01926f...",
    "Version": "0.519.0",
    "Mode": "preview",
    "Seed": "default",
    "IssuedAt": "2026-07-20T12:34:56Z",
    "ETag": "W/\"lic-7-v3\""
  }
}
```

Failure:

```json
{
  "Data": null,
  "Errors": [
    { "Code": "LICENSE_NOT_FOUND", "Field": null, "Message": "License 7 not found." }
  ],
  "Attributes": {
    "RequestId": "01926f...",
    "ErrorId": "01926f...",
    "Version": "0.519.0",
    "Mode": "preview",
    "Seed": "default",
    "IssuedAt": "2026-07-20T12:34:56Z",
    "RetryAfterSeconds": null
  }
}
```

Rules:

- **E-01.** Keys are PascalCase (project memory Core). Never `data`, `errors`, `request_id`.
- **E-02.** `Attributes.RequestId` and `Attributes.ErrorId` are uuidv7 strings. `ErrorId` is present iff `Errors` is non-empty.
- **E-03.** `Attributes.RetryAfterSeconds` is `number | null`. When set, the transport surfaces the header `Retry-After: <n>` to `useApiMutation` for banner display.
- **E-04.** `Attributes.ETag` is present only for reads that produce a versioned entity. Uses the weak form `W/"<resource>-<id>-v<version>"`. Handlers echo the client's `If-Match` header on writes and throw `LICENSE_CONFLICT` (or the domain equivalent) on mismatch. See §Concurrency below.

## Error Codes (Closed Set)

Preview handlers MUST emit only codes registered in `spec/03-error-manage/03-error-code-registry`. The following runtime-mode-specific codes are added by this spec:

| Code | HTTP-analog | When |
|------|-------------|------|
| `RUNTIME_PREVIEW_HANDLER_MISSING` | 501 | Transport received an `operationId` with no registered handler (INV-RM-04 breach; the test at Step 70 catches this at build time, this code covers hot-path defense). |
| `RUNTIME_PREVIEW_ABORTED` | 499 | `Signal.aborted` before completion. |
| `RUNTIME_PREVIEW_OFFLINE` | 0 (network) | `Scenario === "offline"`; transport throws before the handler runs. |
| `RUNTIME_PREVIEW_RATE_LIMITED` | 429 | `Scenario === "rate-limited"` on mutating operationIds. Sets `Attributes.RetryAfterSeconds` to `3`. |

Domain codes (`LICENSE_NOT_FOUND`, `LICENSE_CONFLICT`, `FEATURE_CATALOG_UNSEEDED`, `SERIAL_NOT_FOUND`, `QUOTA_EXHAUSTED`, `IMPERSONATION_FORBIDDEN`, ...) come from the existing registry unchanged.

## Scenario Hooks

Two mechanisms select scenarios:

- **URL:** `?preview=offline` / `?preview=slow` / `?preview=rate-limited` (Plan 16 Steps 81-82).
- **Header:** `x-preview-scenario: rate-limited` on a per-call basis (Plan 16 Step 80).

Rules:

- **SC-01.** Scenario resolution happens in `preview-transport.ts` before handler dispatch. Handlers only see the resolved `Scenario` value in `ctx`.
- **SC-02.** `offline`: transport throws `RUNTIME_PREVIEW_OFFLINE` immediately; handler is not invoked.
- **SC-03.** `slow`: transport awaits 2000ms then invokes the handler normally. Applies to reads and writes.
- **SC-04.** `rate-limited`: transport allows reads through; for mutating operationIds (POST/PATCH/DELETE analogs) it throws `RUNTIME_PREVIEW_RATE_LIMITED` with `RetryAfterSeconds: 3` before invoking the handler.

## Latency Model

- Default latency per call: `50ms` uniform baseline (so skeleton flicker is visible in preview without being disruptive).
- Under `slow` scenario: baseline replaced by `2000ms`.
- Handlers MUST NOT add their own latency; latency lives in the transport.

## Seed-Store Side Effects

`ctx.Store` is the IndexedDB-backed `PreviewStore` (Plan 16 Step 35). Handler discipline:

- **ST-01.** Reads pull from `ctx.Store.get(resource, id)` with a fallthrough to the seed loader (`ctx.Seed`) when the store is empty.
- **ST-02.** Writes mutate `ctx.Store` only. Never mutate the seed module in place (seed modules are frozen at import time via `Object.freeze`).
- **ST-03.** Mutations MUST bump the resource `Version` integer stored in `Store`; the new `ETag` derives from `W/"<resource>-<id>-v<version>"`. This makes `If-Match` conflict detection deterministic.
- **ST-04.** On process boot in preview, the store is lazily populated from `ctx.Seed`. `seed=empty` populates nothing. `seed=error` populates enough rows to render list screens but every mutation throws a domain code (registered per handler).

## Concurrency: `If-Match` / `ETag`

For every mutating operationId on a versioned entity (licenses, features, quotas, admin-users):

- **CC-01.** Client MUST send `If-Match: <etag>` from the last read. Transport forwards it in `ctx.Headers["if-match"]`.
- **CC-02.** Handler compares against the current stored `ETag`. On mismatch: throw `LICENSE_CONFLICT` (or domain equivalent) with `Attributes.Code`, log the mismatch context in the returned `Errors[0].Message`, and `Attributes.ETag` set to the current server value so the client can refetch.
- **CC-03.** Missing `If-Match` on a mutating op MUST throw `PRECONDITION_REQUIRED` (existing code); never silently allow the write.

## Registration and Discovery

- **R-01.** Every domain file exports a `handlers: Record<OperationId, PreviewHandler>` object. `preview-transport.ts` merges all domain maps into a single registry at boot.
- **R-02.** Duplicate `operationId` registrations MUST throw at boot with `RUNTIME_PREVIEW_DUPLICATE_HANDLER` (log-and-throw; do NOT last-write-wins). This surfaces contract drift immediately.
- **R-03.** The unit test at `src/lib/preview-transport.test.ts` (Step 70) MUST iterate every key of `Operations` and assert a handler exists. Failure means Step 4 contract broken.

## Contract Test (Step 77)

Every preview handler's response passes through a Zod schema derived from `src/generated/api/schema.d.ts` for that operationId. The test bench:

- Runs each handler under seed=default and seed=empty.
- Validates both success and failure envelopes.
- Fails the suite on any drift (missing key, wrong type, extra key).

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-04, INV-RM-05.
- `spec/28-runtime-modes/01-version-json-schema.md`: `PreviewSeed` closed set.
- `spec/28-runtime-modes/02-mode-selection-precedence.md`: how `Scenario` interacts with mode (only meaningful in `preview`).
- `spec/03-error-manage/03-error-code-registry`: canonical error codes.
- Plan 16 Steps 33 (transport), 34 (fixtures folder), 35 (`PreviewStore`), 36-38 (seeds), 40-50 (11 domain handlers), 51 (envelope parity), 70 (transport test), 77 (Zod contract test), 80-82 (scenarios).
