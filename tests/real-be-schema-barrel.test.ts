import { describe, expect, expectTypeOf, it } from "vitest";
import type {
  License,
  ResellerQuota,
  SerialCreateResult,
  SerialLookup,
  AuditLog,
} from "@/generated/api/real-be-schema";
import type * as S from "@/generated/api/schema";

/**
 * Plan 16 step 67. Locks the real-BE type barrel
 * `src/generated/api/real-be-schema.ts` against the Zod-inferred shapes.
 *
 * These are compile-time assertions that fail the typecheck (and the
 * vitest run in the same process) the moment `real-be-schema.ts` starts
 * re-exporting anything that no longer matches the Zod contract, or the
 * moment `schema.d.ts` accidentally matches the real BE (which would mean
 * the divergence documented in spec/25-app-audit/06-schema-parity-report.md
 * is resolved and the negative parity tests in step 66 should be flipped).
 */

describe("real-be-schema barrel", () => {
  it("License carries integer LicenseId and does NOT structurally match generated License", () => {
    const sample: License = {
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
    expect(sample.LicenseId).toBe(1);
    expectTypeOf<License>().not.toEqualTypeOf<S.License>();
  });

  it("ResellerQuota diverges from generated Quota", () => {
    expectTypeOf<ResellerQuota>().not.toEqualTypeOf<S.Quota>();
  });

  it("SerialCreateResult uses integer SerialId; generated Serial has no counterpart interface", () => {
    const sample: SerialCreateResult = {
      SerialId: 1,
      LicenseId: 2,
      SerialValue: "AAA-BBB",
      CreatedAt: "2026-07-20T00:00:00.000Z",
    };
    expect(sample.SerialId).toBeTypeOf("number");
    const lookup: SerialLookup = { ...sample, IsRevoked: false };
    expect(lookup.IsRevoked).toBe(false);
  });

  it("AuditLog uses integer AuditLogId and Action/CreatedAt (not EventType/OccurredAt)", () => {
    const sample: AuditLog = {
      AuditLogId: 1,
      ActorType: "User",
      ActorId: 1,
      Action: "License.Issued",
      TargetType: "License",
      TargetId: "1",
      RequestId: "req_1",
      Payload: null,
      CreatedAt: "2026-07-20T00:00:00.000Z",
    };
    expect(sample.AuditLogId).toBe(1);
    expectTypeOf<AuditLog>().not.toEqualTypeOf<S.AuditEntry>();
  });
});
