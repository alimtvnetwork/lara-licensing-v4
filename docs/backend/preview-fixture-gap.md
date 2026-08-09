# Preview Fixture Coverage Gap Report (Plan 18 Step 95)

This report lists every `OperationId` declared in `src/generated/api/operations.ts` that lacks a registered handler in `src/lib/preview-fixtures/`.

## Summary
- Total Operations: 31
- Registered Handlers: 24
- Missing Handlers: 7
- Coverage: 77.4%

## Missing Operations

### Auth / Password Reset
- `password-reset.request` (POST /api/password-reset/request) [DONE]
- `password-reset.confirm` (POST /api/password-reset/confirm) [DONE]

### Admin / Infrastructure
- `admin.impersonation.start` (POST /api/admin/impersonation/start) [DONE]
- `admin.impersonation.stop` (POST /api/admin/impersonation/stop) [DONE]
- `admin.runtime-config.show` (GET /api/admin/runtime-config) [DONE]
- `admin.runtime-config.update` (PUT /api/admin/runtime-config) [DONE]

### Admin / Licensing
- `admin.licenses.show` (GET /api/admin/licenses/:Id) [DONE]

## Next Steps
1. Implement `password-reset` handlers in `src/lib/preview-fixtures/auth.ts` or new module. (Step 96)
2. Implement `impersonation` handlers. (Step 97)
3. Implement `runtime-config` handlers. (Step 98)
4. Add `admin.licenses.show` handler to `src/lib/preview-fixtures/licenses.ts`. (Step 99)
