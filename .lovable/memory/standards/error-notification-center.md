# Error Notification Center

**Rule:** The global error notification bell must surface both client-side API failures (`LaraApiError`) and server-side log entries (`lara-audit-errors`) for administrators.

1. **Client-side Store:** Errors are normalized via `error-store.ts` into a bounded ring buffer (50 entries) per spec/03-error-manage. This buffer persists across page reloads via `sessionStorage`.
2. **Server-side Fetch:** When the current user has the `Admin` capability, the drawer must fetch the unified `/Api/Admin/Errors` backend endpoint to merge server-side `lara-audit-errors` telemetry (NDJSON log tails) with the client store.
3. **Deduplication:** Merging server + client entries must happen deterministically by `ErrorId`.
4. **Copy Action:** All entries must surface a "Copy Error ID" button via `navigator.clipboard`.
5. **No 5xx Duplication:** Toasts for 5xx failures are permitted (if no persistent banner owns the code), but must rely on the shared `error-store.ts` subscription to prevent state skew.
