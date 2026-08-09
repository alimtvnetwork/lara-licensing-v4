/**
 * Error preview seed (Plan 16 Step 38).
 */

import type {
  AdminUser,
  AuditEntry,
  FeatureDefinition,
  AdminReseller,
  MeUser,
} from "@/generated/api/schema";
import type { AdminQuotaRequestRow } from "../lara-quota";
import { ApiErrorCodeType } from "../lara-api-error";
import type { PreviewDomain } from "../preview-store";
import { write } from "../preview-store";
import { runSeed, type PreviewSeedFn } from "./_contract";

const T0 = "2026-01-01T00:00:00Z";

const ADMIN_USER: MeUser = {
  Id: "01H0000000000000000ADMIN1",
  Email: "admin@lara.local",
  DisplayName: "Admin Preview (Error)",
  Roles: ["admin"],
  ResellerId: null,
  CreatedAt: T0,
  UpdatedAt: T0,
};

const RESELLER_USER: MeUser = {
  Id: "01H0000000000000000RSLL01",
  Email: "reseller@lara.local",
  DisplayName: "Reseller Preview (Error)",
  Roles: ["reseller"],
  ResellerId: "01H000000000000000RSLLR1",
  CreatedAt: T0,
  UpdatedAt: T0,
};

function asAdmin(u: MeUser): AdminUser {
  return {
    ...u,
    IsActive: true,
    LastLoginAt: T0,
    Version: 1,
  };
}

async function seedAuth(): Promise<void> {
  await write<AdminUser>("admin-users", ADMIN_USER.Id, asAdmin(ADMIN_USER));
  await write<AdminUser>("admin-users", RESELLER_USER.Id, asAdmin(RESELLER_USER));
  await write<MeUser>("me", "current", ADMIN_USER);
  await write<Record<string, string>>("auth", "credentials", {
    "admin@lara.local": "preview-admin",
    "reseller@lara.local": "preview-reseller",
    "user@licensingportal.local": "preview-portal",
  });
}

/**
 * Per-domain canonical `ApiErrorCodeType` that handlers MUST emit when the
 * active seed is `"error"`. Codes are chosen to exercise the most common
 * failure surface for each domain against `spec/03-error-manage/`.
 */
export const ERROR_SEED_DOMAIN_CODE: Readonly<Record<PreviewDomain, ApiErrorCodeType>> = {
  auth: ApiErrorCodeType.AuthUnauthorized,
  resellers: ApiErrorCodeType.AuthForbidden,
  "admin-users": ApiErrorCodeType.AuthForbidden,
  me: ApiErrorCodeType.AuthUnauthorized,
  licenses: ApiErrorCodeType.ValidationFailed,
  features: ApiErrorCodeType.ValidationFailed,
  updates: ApiErrorCodeType.ValidationFailed,
  serials: ApiErrorCodeType.ValidationFailed,
  quotas: ApiErrorCodeType.ValidationFailed,
  "quota-requests": ApiErrorCodeType.ValidationFailed,
  impersonation: ApiErrorCodeType.AuthForbidden,
  audit: ApiErrorCodeType.AuthForbidden,
  metrics: ApiErrorCodeType.ValidationFailed,
  "password-reset": ApiErrorCodeType.ValidationFailed,
  abuse: ApiErrorCodeType.AuthForbidden,

  "runtime-config": ApiErrorCodeType.ValidationFailed,
  "tier-features": ApiErrorCodeType.ValidationFailed,
  "license-features": ApiErrorCodeType.ValidationFailed,
  stubs: ApiErrorCodeType.BrOpsNotYetImplemented,
};

async function seedNegativePathRows(): Promise<void> {
  // Plan 17 Step 24: seed a minimal set of rows even in error mode so the
  // contract tests can prove that rejection happens due to the Seed ID,
  // not because the store is empty (INV-RM-06).
  const audit: AuditEntry = {
    Id: "01H0000000000000000AUDERR1",
    EventType: "auth.login.failed",
    ActorUserId: ADMIN_USER.Id,
    TargetType: "user",
    TargetId: ADMIN_USER.Id,
    RequestId: "req-err-audit-1",
    OccurredAt: T0,
    Payload: { Reason: "seed_error" },
  };
  const audit2: AuditEntry = {
    ...audit,
    Id: "01H0000000000000000AUDERR2",
    RequestId: "req-err-audit-2",
  };
  await write<AuditEntry>("audit", audit.Id, audit);
  await write<AuditEntry>("audit", audit2.Id, audit2);

  const feature: FeatureDefinition = {
    Code: "err.test",
    DisplayName: "Error Test",
    Description: "Test feature",
    Category: "core",
    IsBillable: false,
    CreatedAt: T0,
    UpdatedAt: T0,
  };
  const feature2: FeatureDefinition = { ...feature, Code: "err.test2" };
  await write<FeatureDefinition>("features", feature.Code, feature);
  await write<FeatureDefinition>("features", feature2.Code, feature2);

  const qreq: AdminQuotaRequestRow = {
    QuotaRequestId: 9991,
    ResellerId: 1,
    ResellerSlug: "err-reseller",
    LicenseCategoryId: 1,
    LicenseTierId: 1,
    RequestedDelta: 1,
    ApprovedDelta: null,
    Status: "Pending",
    SubmittedByUserId: 1,
    SubmittedAt: T0,
    DecidedByUserId: null,
    DecidedAt: null,
    DenialReason: null,
    Justification: "Error seed test",
  };
  const qreq2: AdminQuotaRequestRow = { ...qreq, QuotaRequestId: 9992 };
  await write<AdminQuotaRequestRow>("quota-requests", String(qreq.QuotaRequestId), qreq);
  await write<AdminQuotaRequestRow>("quota-requests", String(qreq2.QuotaRequestId), qreq2);
}

const seed: PreviewSeedFn = async () => {
  await seedAuth();
  await seedNegativePathRows();
};

export const errorSeed = seed;

export async function loadErrorSeed(): Promise<{ Hydrated: boolean }> {
  return runSeed("error", seed);
}
