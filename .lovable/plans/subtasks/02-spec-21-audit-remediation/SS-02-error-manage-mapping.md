# SS-02: Map every spec/21-app endpoint to spec/03-error-manage rules

Slug: error-manage-mapping
Parent: 02-spec-21-audit-remediation
Status: pending
Created: 2026-07-16

## Goal

Produce `spec/21-app/18-error-management-binding.md` that binds every endpoint in `10-endpoints.md` to the concrete rules in `spec/03-error-manage/` (catch-log-rethrow, request-id propagation, no swallowed errors, retry semantics, `Retry-After` surfacing) and to the closed `ErrorCode` set in `12-error-taxonomy.md`.

## Structure

- Section per endpoint group (Auth, Licenses, Serials, Verify, Admin, SelfUpdate).
- Columns: endpoint, failure modes, `ErrorCode`, HTTP status, log level, retry policy, client surface.
- Explicit "MUST log at ERROR and rethrow" vs "MUST log at WARN and surface to user" per row.

## Done when

- File exists and is referenced from `spec/21-app/00-overview.md` and `spec/25-app-audit/06-error-management-surface.md`.
- Every `ErrorCode` in `12-error-taxonomy.md` appears in at least one row.
- Every endpoint in `10-endpoints.md` has at least one row.
