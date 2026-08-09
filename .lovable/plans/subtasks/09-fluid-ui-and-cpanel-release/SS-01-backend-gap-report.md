# SS-01: Backend endpoint gap report

Parent: 09-fluid-ui-and-cpanel-release
Slug: backend-gap-report
Status: complete
Delivered: 2026-07-19 (v0.274.0)
Output: `docs/backend/endpoint-gap-report.md`
Created: 2026-07-19

## Goal

Produce a single markdown table at `docs/backend/endpoint-gap-report.md` enumerating every route in `backend/routes/api.php`, with columns:

| Method | Path | Controller@action | FormRequest | Policy | Resource | PHPUnit | Swagger | Frontend client (`src/lib/lara-*.ts`) | Status |

## Procedure

1. Parse `backend/routes/api.php` to enumerate all routes (Admin, Reseller, Portal, Auth groups).
2. For each route, `grep` the controller action for:
   - The FormRequest type-hint (or note "inline validate()" / "none").
   - `authorize()` / `Gate::` / policy usage.
   - Return type: `Resource` subclass vs raw array.
3. Search `backend/tests/` for a feature test hitting that route.
4. Search `src/lib/lara-*.ts` for a typed client function calling that path.
5. Search controller docblocks for `@OA\` (OpenAPI) annotations.
6. Mark Status as `complete`, `partial`, or `missing`.

## Output

- `docs/backend/endpoint-gap-report.md` (created by this subtask).
- Feed the `missing` and `partial` rows into steps 59-63 of the parent plan.

## Verification

- Row count matches `php artisan route:list --json | jq length` for the `api` middleware group.
- Every route referenced by an `src/lib/lara-*.ts` client appears with a `complete` frontend column.
