# SS-04: User Management

Parent: 01-blind-ai-audit-folder-21
Slug: user-management
Status: pending
Created: 2026-07-16

## Enum

```sql
create type public.app_role as enum ('Admin', 'Reseller', 'AppBuilder', 'EndUser');
```

## Table

```sql
create table public.UserRoles (
  Id uuid primary key default gen_random_uuid(),
  UserId uuid not null references auth.users(Id) on delete cascade,
  Role app_role not null,
  GrantedAt timestamptz not null default now(),
  GrantedBy uuid references auth.users(Id),
  unique (UserId, Role)
);
```

## `has_role`

Security-definer SQL function returning boolean. Never checked client-side.

## Capability matrix

| Capability | Admin | Reseller | AppBuilder | EndUser |
|------------|-------|----------|------------|---------|
| Issue license | ✅ | ✅ (scoped) | ❌ | ❌ |
| Renew license | ✅ | ✅ (own) | ❌ | ❌ |
| Revoke license | ✅ | ✅ (own) | ❌ | ❌ |
| Lookup serial | ✅ | ✅ (own) | ✅ (own app) | ❌ |
| Verify final | ✅ | ✅ | ✅ | ✅ |
| Publish update | ✅ | ❌ | ❌ | ❌ |
| Manage roles | ✅ | ❌ | ❌ | ❌ |

## Debugging

- Every request tagged with `X-Request-Id` (uuid v7).
- Every 4xx/5xx returns `{ Code, Message, RequestId, Details? }`.
- Never swallow errors (see `spec/03-error-manage/`).
- Role-check failures logged to `AuditLog` with `RoleCheckDenied`.
