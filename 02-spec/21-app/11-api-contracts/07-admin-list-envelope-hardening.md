# Admin List Envelope Hardening

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Bind every Admin list endpoint declared in [`04-admin-contracts.md`](./04-admin-contracts.md)
to the `Attributes.Pagination` block fixed in [`06-envelope-attributes.md`](./06-envelope-attributes.md).
Cursor-based pagination is out of scope for v1 (see `06-envelope-attributes.md` §Pagination).

## Scope

The following endpoints are list endpoints and MUST emit `Attributes.Pagination`:

| Endpoint | Query keys | Sort key | Default `PageSize` |
|----------|-----------|----------|--------------------|
| `GET /Resellers` | `Page`, `PageSize`, optional `IsActive`, optional `Search` | `ResellerId ASC` | 25 |
| `GET /Resellers/{ResellerId}/Prefixes` | `Page`, `PageSize`, optional `IsActive` | `PrefixId ASC` | 25 |
| `GET /Users` | `Page`, `PageSize`, optional `TenantId`, optional `IsActive`, optional `Search` | `UserId ASC` | 25 |
| `GET /Licenses` | `Page`, `PageSize`, optional `ResellerId`, optional `State` | `LicenseId DESC` | 25 |
| `GET /AuditEvents` | `Page`, `PageSize`, optional `ActorId`, optional `Action` | `AuditEventId DESC` | 50 |

`MaxPageSize` is `100` for all list endpoints. Values above are server-clamped;
the clamped value is echoed in `Attributes.Pagination.PageSize` and the request
is not rejected.

## Query parameter rules

- `Page` defaults to `1`. Values less than `1` return `400 ValidationFailed` with `Details[]` `{ Field: "Page", Rule: "MinValue", Actual: <value> }`.
- `PageSize` defaults per table above. Values less than `1` return `400 ValidationFailed`. Values above `100` are clamped, not rejected.
- Unknown query keys return `400 ValidationFailed` per [`00-overview.md`](./00-overview.md).
- Filter combinations that yield zero rows return `200` with `Results: []` and `Attributes.Pagination.TotalItems: 0`. Empty results are never `404`.

## Sort stability

Every list endpoint has a deterministic tiebreaker (the primary key column
in the sort direction shown). This guarantees repeatable pagination when
callers walk `Page` from `1` to `TotalPages`.

## Envelope shape

Success response for any list endpoint:

```json
{
  "Status": { "IsSuccess": true, "Code": 200, "Message": "OK" },
  "Attributes": {
    "RequestId": "01HXYZ...",
    "RequestedAt": "2026-07-16T00:00:00Z",
    "Pagination": {
      "Page": 1,
      "PageSize": 25,
      "TotalItems": 137,
      "TotalPages": 6,
      "HasNext": true,
      "HasPrevious": false
    }
  },
  "Results": [ /* ... */ ]
}
```

`Results` is always an array (per [`05-envelope-schema.md`](./05-envelope-schema.md));
`null` and empty `204` responses are forbidden.

## Acceptance

- AC-ALEH-001: Every list endpoint above emits `Attributes.Pagination` with all six fields on every 2xx response.
- AC-ALEH-002: `Cursor` and `NextCursor` are forbidden in v1 Admin list contracts; contracts that mention them must be corrected.
- AC-ALEH-003: `PageSize` above `100` is clamped to `100`, echoed in the response, and NOT rejected as `400`.
- AC-ALEH-004: Every list endpoint has a deterministic sort with a primary-key tiebreaker.
- AC-ALEH-005: Zero-row filter results return `200` with `Results: []` and `TotalItems: 0`, never `404`.
