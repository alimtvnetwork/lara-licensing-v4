# 06 - Schema Parity Report (generated typed layer vs runtime Zod contracts)

Owner: Plan 16 step 66. Companion to `05-api-contract-duality.md`.

Root cause this report addresses (one sentence): route migrations to
`useApi` / `apiClient.call` keep tripping because `src/generated/api/schema.d.ts`
and the Zod schemas in `src/lib/lara-*.ts` describe two different backends,
and until every divergent concept is enumerated in one place we cannot
mechanically decide which routes are safe to migrate.

## Legend

- Real BE = shape validated by `src/lib/lara-*.ts::*Schema` against the live
  Laravel responses.
- Generated = shape declared in `src/generated/api/schema.d.ts` (aspirational
  Ulid + Version design used by the preview transport and by
  `admin.runtime.tsx` / `admin.quotas.tsx`).
- Overlap = keys that exist verbatim in both, with the same primitive type.

## Divergence matrix

| Concept | Real BE identifier | Generated identifier | Overlap keys | Verdict |
| --- | --- | --- | --- | --- |
| Quota | `ResellerId:int`, `LicenseCategoryId`, `LicenseTierId`, `LicensesGranted/Consumed/Remaining`, `PeriodStart/End` | `Id:Ulid`, `ResellerName`, `FeatureCode`, `Allocated/Used/Restored`, `Version` | `["ResellerId"]` | Preview-only shape. Route quarantined via `PREVIEW_ONLY_SHAPE_ROUTES` in `tests/api-client-boundary.test.ts`. |
| Audit entry | `AuditLogId:int`, `Action`, `CreatedAt` | `Id:Ulid`, `EventType`, `OccurredAt` | none semantically compatible | Preview-only shape. Blocks migration of `admin.audit.tsx`. |
| License | `LicenseId:int`, `IsActive:boolean`, `ProductVersion` | `Id:Ulid`, `Status:LicenseStatus`, `Serial`, `Features[]`, `Version` | none semantically compatible | Preview-only shape. Blocks migration of `admin.licenses.*`. |
| Serial | `SerialId:int`, `LicenseId:int`, `SerialValue`, `CreatedAt` (+ `IsRevoked` on lookup) | Only carried as `Serial:string` inside `License` and `PortalSerialLookupResponse` | no dedicated generated `Serial` interface exists | Blocks migration of `admin.serials.tsx`. Requires new generated `Serial` shape. |
| Runtime config | `Mode`, `ScenarioId`, `Version`, `UpdatedAt` | same keys | full | Real-BE aligned. `admin.runtime.tsx` is the only route on `apiClient.call` today. |

## Rules derived from this report

1. A route may live in `REAL_BE_ROUTES` (arch test) only if its operation
   appears in the "full" row above.
2. A route in `PREVIEW_ONLY_SHAPE_ROUTES` must carry the inline
   `preview-only-shape:` comment marker and cite this file.
3. Regenerating `src/generated/api/schema.d.ts` from the Laravel OpenAPI
   export must, at minimum, flip Quota / Audit / License / Serial to
   integer ids and to the real BE field names before those routes may be
   promoted. Until then, `requestLaraApi + lara-*Schema` remains the only
   correct path for those admin surfaces.
4. Any future addition to `PREVIEW_ONLY_SHAPE_ROUTES` must add a row to
   the divergence matrix above in the same commit.

## Verification

- `tests/schema-vs-runtime-parity.test.ts` pins Quota / Audit / License /
  Serial divergences. Failures indicate schema regeneration landed and
  this document must be updated.
- `tests/api-client-boundary.test.ts` enforces the allowlists.
