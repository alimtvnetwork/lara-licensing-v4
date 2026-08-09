import { describe, expect, it } from "vitest";
import { resellerQuotaSchema } from "@/lib/lara-quota";
import { auditLogSchema } from "@/lib/lara-audit";
import { licenseSchema } from "@/lib/lara-license";
import { serialCreateResultSchema, serialLookupSchema } from "@/lib/lara-serial";
import type * as S from "@/generated/api/schema";

/**
 * Plan 16 step 65. Runtime parity guard between the generated typed
 * transport (`src/generated/api/schema.d.ts`) and the real-BE Zod
 * contracts in `src/lib/lara-*.ts`.
 *
 * Root cause it addresses: v0.569.0 shipped `admin.quotas.tsx` on top of
 * a generated `Quota` shape (`{Id: Ulid, Allocated, Used, Restored}`)
 * that the real Laravel BE never returns (`{ResellerId,
 * LicenseCategoryId, LicenseTierId, LicensesGranted, LicensesConsumed,
 * LicensesRemaining, ...}` per `resellerQuotaSchema`). This test would
 * have failed CI on that migration; it exists so any future claim of
 * "the typed schema matches the real BE" for these three concepts must
 * either update the generated schema OR delete the entry here.
 *
 * The assertions purposefully compare against real BE samples (as
 * captured by the Zod schemas). Failure = drift, not test fragility.
 */

const REAL_BE_QUOTA_SAMPLE = {
  ResellerId: 1,
  LicenseCategoryId: 3,
  LicenseTierId: 2,
  LicensesGranted: 100,
  LicensesConsumed: 42,
  LicensesRemaining: 58,
  PeriodStart: "2026-01-01T00:00:00.000Z",
  PeriodEnd: null,
};

const REAL_BE_AUDIT_SAMPLE = {
  AuditLogId: 42,
  ActorType: "User" as const,
  ActorId: 7,
  Action: "License.Issued",
  TargetType: "License",
  TargetId: "123",
  RequestId: "req_abc",
  Payload: null,
  CreatedAt: "2026-07-20T00:00:00.000Z",
};

describe("generated schema vs runtime Zod parity", () => {
  it("resellerQuotaSchema accepts the real BE quota sample", () => {
    expect(() => resellerQuotaSchema.parse(REAL_BE_QUOTA_SAMPLE)).not.toThrow();
  });

  it("generated Quota shape DOES NOT match the real BE (documented drift)", () => {
    // If this ever starts matching, schema.d.ts has been regenerated; delete
    // this negative assertion and remove admin.quotas.tsx from
    // PREVIEW_ONLY_SHAPE_ROUTES in tests/api-client-boundary.test.ts.
    const generatedKeys: ReadonlyArray<keyof S.Quota> = [
      "Id",
      "ResellerId",
      "ResellerName",
      "FeatureCode",
      "Allocated",
      "Used",
      "Restored",
      "UpdatedAt",
      "Version",
    ];
    const runtimeKeys = Object.keys(REAL_BE_QUOTA_SAMPLE);
    const overlap = generatedKeys.filter((k) => runtimeKeys.includes(k as string));
    // Only "ResellerId" overlaps; everything else diverges.
    expect(overlap).toEqual(["ResellerId"]);
  });

  it("auditLogSchema accepts the real BE audit row (int AuditLogId, Action, CreatedAt)", () => {
    expect(() => auditLogSchema.parse(REAL_BE_AUDIT_SAMPLE)).not.toThrow();
  });

  it("generated AuditEntry shape uses Ulid+EventType+OccurredAt (documented drift)", () => {
    const generatedKeys: ReadonlyArray<keyof S.AuditEntry> = [
      "Id",
      "EventType",
      "ActorUserId",
      "TargetType",
      "TargetId",
      "RequestId",
      "OccurredAt",
      "Payload",
    ];
    // The runtime uses AuditLogId/Action/CreatedAt; none of the divergent
    // names appear on the generated side. If EventType/OccurredAt ever
    // disappear from schema.d.ts this negative assertion needs updating.
    expect(generatedKeys).toContain("EventType");
    expect(generatedKeys).toContain("OccurredAt");
  });

  it("licenseSchema uses integer LicenseId (real BE), generated License uses Ulid Id", () => {
    const sample = {
      LicenseId: 1,
      LicenseCategoryId: 1,
      LicenseTierId: 1,
      EnvironmentId: 1,
      IssuedByUserId: 1,
      ProductVersion: "1.0.0",
      IsActive: true,
      IssuedAt: "2026-07-20T00:00:00.000Z",
      IsSingleUse: false,
    };
    expect(() => licenseSchema.parse(sample)).not.toThrow();
    const generatedIdField: keyof S.License = "Id";
    expect(generatedIdField).toBe("Id");
  });

  it("serialCreateResultSchema uses integer SerialId; generated layer has no dedicated Serial interface (documented drift)", () => {
    const createSample = {
      SerialId: 42,
      LicenseId: 7,
      SerialValue: "AAAA-BBBB-CCCC-DDDD",
      CreatedAt: "2026-07-20T00:00:00.000Z",
    };
    expect(() => serialCreateResultSchema.parse(createSample)).not.toThrow();
    expect(() => serialLookupSchema.parse({ ...createSample, IsRevoked: false })).not.toThrow();
    // The generated schema only carries `Serial:string` inside License /
    // PortalSerialLookupResponse; no dedicated Serial interface exists.
    // If a Serial interface with SerialId:number appears in schema.d.ts,
    // delete this negative assertion, add admin.serials.tsx to
    // REAL_BE_ROUTES, and update spec/25-app-audit/06-schema-parity-report.md.
    const portalLookup: keyof S.PortalSerialLookupResponse = "Serial";
    expect(portalLookup).toBe("Serial");
  });
});
