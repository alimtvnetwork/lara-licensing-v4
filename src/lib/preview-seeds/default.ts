/**
 * Default preview seed (Plan 16 Step 36).
 *
 * Populates every domain with realistic, deterministic records typed
 * against `src/generated/api/schema.d.ts`. IDs are fixed ULID-shaped
 * strings and timestamps are pinned so screenshots are stable.
 * Steps 40..50 handlers read/write through the same domain keys.
 */

import type {
  AdminUser,
  AuditEntry,
  FeatureDefinition,
  KpiTile,
  License,
  MeUser,
  Quota,
  RuntimeConfigDoc,
  AdminReseller,
  UpdateManifestEntry,
  AbuseEvent,
} from "@/generated/api/schema";

import type { AdminQuotaRequestRow } from "../lara-quota";
import type { LicenseFeatureResource, TierFeatureResource } from "../lara-features";

import { write } from "../preview-store";
import { primeIdMap, resetIdMap } from "../preview-id-map";
import { runSeed, type PreviewSeedFn } from "./_contract";

const T0 = "2026-01-01T00:00:00Z";
const T1 = "2026-06-15T12:00:00Z";
const T2 = "2026-07-20T00:00:00Z";

const ADMIN_USER: MeUser = {
  Id: "01H0000000000000000ADMIN1",
  Email: "admin@lara.local",
  DisplayName: "Admin Preview",
  Roles: ["admin"],
  ResellerId: null,
  CreatedAt: T0,
  UpdatedAt: T2,
};

const RESELLER_USER: MeUser = {
  Id: "01H0000000000000000RSLL01",
  Email: "reseller@lara.local",
  DisplayName: "Reseller Preview",
  Roles: ["reseller"],
  ResellerId: "01H000000000000000RSLLR1",
  CreatedAt: T0,
  UpdatedAt: T2,
};

async function seedUsers(): Promise<void> {
  const asAdmin = (u: MeUser, active = true): AdminUser => ({
    ...u,
    IsActive: active,
    LastLoginAt: T2,
    Version: 1,
  });
  await write<AdminUser>("admin-users", ADMIN_USER.Id, asAdmin(ADMIN_USER));
  await write<AdminUser>("admin-users", RESELLER_USER.Id, asAdmin(RESELLER_USER));

  // Plan 18 Step 66: Add 6 more users to reach >= 8 users across roles.
  const extraUsers: MeUser[] = [
    {
      Id: "01H0000000000000000AUDTR1",
      Email: "auditor@lara.local",
      DisplayName: "Auditor Preview",
      Roles: ["auditor"],
      ResellerId: null,
      CreatedAt: T0,
      UpdatedAt: T2,
    },
    {
      Id: "01H0000000000000000SUPP01",
      Email: "support@lara.local",
      DisplayName: "Support Preview",
      Roles: ["support"],
      ResellerId: null,
      CreatedAt: T0,
      UpdatedAt: T2,
    },
    {
      Id: "01H0000000000000000RSLL02",
      Email: "reseller2@lara.local",
      DisplayName: "Reseller Two",
      Roles: ["reseller"],
      ResellerId: "01H000000000000000RSLLR1",
      CreatedAt: T0,
      UpdatedAt: T2,
    },
    {
      Id: "01H0000000000000000RSLL03",
      Email: "reseller3@lara.local",
      DisplayName: "Reseller Three",
      Roles: ["reseller"],
      ResellerId: "01H000000000000000RSLLR2",
      CreatedAt: T0,
      UpdatedAt: T2,
    },
    {
      Id: "01H0000000000000000PORT01",
      Email: "portal@licensingportal.local",
      DisplayName: "Portal User",
      Roles: ["portal"],
      ResellerId: null,
      CreatedAt: T0,
      UpdatedAt: T2,
    },
    {
      Id: "01H0000000000000000ADM002",
      Email: "admin2@lara.local",
      DisplayName: "Admin Two",
      Roles: ["admin"],
      ResellerId: null,
      CreatedAt: T0,
      UpdatedAt: T2,
    },
  ];
  for (const u of extraUsers) {
    await write<AdminUser>("admin-users", u.Id, asAdmin(u));
  }

  // "me" domain stores the currently signed-in user id + credentials map.
  await write<MeUser>("me", "current", ADMIN_USER);
  await write<Record<string, string>>("auth", "credentials", {
    "admin@lara.local": "preview-admin",
    "reseller@lara.local": "preview-reseller",
    "user@licensingportal.local": "preview-portal",
  });
}

async function seedFeatures(): Promise<void> {
  const features: FeatureDefinition[] = [
    {
      Code: "core.reports",
      DisplayName: "Reports",
      Description: "Standard reporting suite.",
      Category: "core",
      IsBillable: false,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Code: "core.dashboards",
      DisplayName: "Dashboards",
      Description: "KPI dashboards.",
      Category: "core",
      IsBillable: false,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Code: "addon.exports",
      DisplayName: "Data Exports",
      Description: "CSV/Parquet exports.",
      Category: "addon",
      IsBillable: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Code: "addon.sso",
      DisplayName: "SSO",
      Description: "SAML/OIDC SSO.",
      Category: "addon",
      IsBillable: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Code: "addon.api",
      DisplayName: "Advanced API",
      Description: "Programmatic access.",
      Category: "addon",
      IsBillable: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Code: "addon.branding",
      DisplayName: "Custom Branding",
      Description: "White-label support.",
      Category: "addon",
      IsBillable: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
  ];
  for (const f of features) {
    await write<FeatureDefinition>("features", f.Code, f);
  }
}

/**
 * Plan 17 Step 23: seed tier-features rows keyed by
 * `<LicenseTierId>::<FeatureKey>`, one per FeatureKey per tier, spanning
 * three deterministic tiers (1=Starter, 2=Growth, 3=Enterprise) so the
 * tier-features bridge exercises Boolean/Number/String ValueTypes and
 * demonstrates the Precedence contract (spec/21-app/45 §4).
 * Values obey `featureKeyValueTypeRegistry` and validate cleanly under
 * `tierFeatureResourceSchema` in `src/lib/lara-features.ts`.
 */
async function seedTierFeatures(): Promise<void> {
  const rows: TierFeatureResource[] = [
    { LicenseTierId: 1, FeatureKey: "Modules.Reports", Value: true },
    { LicenseTierId: 1, FeatureKey: "Modules.Api", Value: false },
    { LicenseTierId: 1, FeatureKey: "Limits.MaxUsers", Value: 5 },
    { LicenseTierId: 1, FeatureKey: "Limits.MaxProjects", Value: 3 },
    { LicenseTierId: 1, FeatureKey: "Branding.Watermark", Value: true },
    { LicenseTierId: 1, FeatureKey: "Support.Tier", Value: "Community" },
    { LicenseTierId: 2, FeatureKey: "Modules.Reports", Value: true },
    { LicenseTierId: 2, FeatureKey: "Modules.Api", Value: true },
    { LicenseTierId: 2, FeatureKey: "Limits.MaxUsers", Value: 25 },
    { LicenseTierId: 2, FeatureKey: "Limits.MaxProjects", Value: 10 },
    { LicenseTierId: 2, FeatureKey: "Branding.Watermark", Value: false },
    { LicenseTierId: 2, FeatureKey: "Support.Tier", Value: "Standard" },
    { LicenseTierId: 3, FeatureKey: "Modules.Reports", Value: true },
    { LicenseTierId: 3, FeatureKey: "Modules.Api", Value: true },
    { LicenseTierId: 3, FeatureKey: "Limits.MaxUsers", Value: 250 },
    { LicenseTierId: 3, FeatureKey: "Limits.MaxProjects", Value: 100 },
    { LicenseTierId: 3, FeatureKey: "Branding.Watermark", Value: false },
    { LicenseTierId: 3, FeatureKey: "Support.Tier", Value: "Priority" },
  ];
  for (const r of rows) {
    await write<TierFeatureResource>("tier-features", `${r.LicenseTierId}::${r.FeatureKey}`, r);
  }
}

/**
 * Plan 17 Step 23: seed license-features overrides for the three seeded
 * licenses (numeric ids assigned by `primeLegacyIdMap()`: LIC001=1,
 * LIC002=2, LIC003=3). Only a subset of keys is overridden per license
 * so the Precedence resolver (§4) has both "override" and "tier default"
 * branches to exercise in preview.
 */
async function seedLicenseFeatures(): Promise<void> {
  const rows: LicenseFeatureResource[] = [
    { LicenseId: 1, FeatureKey: "Limits.MaxUsers", Value: 50 },
    { LicenseId: 1, FeatureKey: "Modules.Api", Value: true },
    { LicenseId: 2, FeatureKey: "Branding.Watermark", Value: true },
    { LicenseId: 3, FeatureKey: "Support.Tier", Value: "Priority" },
    { LicenseId: 3, FeatureKey: "Limits.MaxProjects", Value: 200 },
  ];
  for (const r of rows) {
    await write<LicenseFeatureResource>("license-features", `${r.LicenseId}::${r.FeatureKey}`, r);
  }
}

async function seedLicenses(): Promise<void> {
  const licenses: License[] = [
    {
      Id: "01H00000000000000LIC00001",
      Serial: "LARA-AAAA-0001",
      Status: "active",
      CustomerName: "Acme Co.",
      CustomerEmail: "ops@acme.test",
      ResellerId: RESELLER_USER.ResellerId,
      IssuedAt: T0,
      ExpiresAt: "2027-01-01T00:00:00Z",
      Features: ["core.reports", "core.dashboards"],
      MaxActivations: 5,
      ActiveActivations: 2,
      Version: 3,
      CreatedAt: T0,
      UpdatedAt: T1,
    },
    {
      Id: "01H00000000000000LIC00002",
      Serial: "LARA-BBBB-0002",
      Status: "suspended",
      CustomerName: "Beta LLC",
      CustomerEmail: "it@beta.test",
      ResellerId: null,
      IssuedAt: T1,
      ExpiresAt: null,
      Features: ["core.reports"],
      MaxActivations: 1,
      ActiveActivations: 0,
      Version: 1,
      CreatedAt: T1,
      UpdatedAt: T1,
    },
    {
      Id: "01H00000000000000LIC00003",
      Serial: "LARA-CCCC-0003",
      Status: "expired",
      CustomerName: "Gamma Inc.",
      CustomerEmail: "admin@gamma.test",
      ResellerId: null,
      IssuedAt: T0,
      ExpiresAt: "2026-06-01T00:00:00Z",
      Features: ["core.reports", "addon.sso"],
      MaxActivations: 10,
      ActiveActivations: 10,
      Version: 5,
      CreatedAt: T0,
      UpdatedAt: T1,
    },
    {
      Id: "01H00000000000000LIC00004",
      Serial: "LARA-DDDD-0004",
      Status: "active",
      CustomerName: "Delta Soft",
      CustomerEmail: "billing@delta.test",
      ResellerId: "01H000000000000000RSLLR1",
      IssuedAt: T1,
      ExpiresAt: "2028-01-01T00:00:00Z",
      Features: ["core.reports"],
      MaxActivations: 50,
      ActiveActivations: 25,
      Version: 1,
      CreatedAt: T1,
      UpdatedAt: T1,
    },
    {
      Id: "01H00000000000000LIC00005",
      Serial: "LARA-EEEE-0005",
      Status: "active",
      CustomerName: "Epsilon Tech",
      CustomerEmail: "dev@epsilon.test",
      ResellerId: "01H000000000000000RSLLR2",
      IssuedAt: T2,
      ExpiresAt: null,
      Features: ["core.reports", "core.dashboards", "addon.exports"],
      MaxActivations: 100,
      ActiveActivations: 0,
      Version: 1,
      CreatedAt: T2,
      UpdatedAt: T2,
    },
    {
      Id: "01H00000000000000LIC00006",
      Serial: "LARA-FFFF-0006",
      Status: "active",
      CustomerName: "Zeta Labs",
      CustomerEmail: "support@zeta.test",
      ResellerId: null,
      IssuedAt: T2,
      ExpiresAt: "2027-12-31T23:59:59Z",
      Features: ["core.reports", "addon.sso"],
      MaxActivations: 25,
      ActiveActivations: 5,
      Version: 1,
      CreatedAt: T2,
      UpdatedAt: T2,
    },
  ];
  for (const l of licenses) {
    await write<License>("licenses", l.Id, l);
    await write<{ Serial: string; LicenseId: string }>("serials", l.Serial, {
      Serial: l.Serial,
      LicenseId: l.Id,
    });
  }
}

async function seedUpdates(): Promise<void> {
  // Plan 17 Step 22: backfill portal-updates catalog from 2 to 6 deterministic
  // entries so `/api/portal/updates` exercises: (a) the "no update" branch when
  // CurrentVersion=2.0.0, (b) mandatory-only branch (1.3.0, 1.5.0), (c) major
  // bump with MinPreviousVersion gate (2.0.0), and (d) newest-first descending
  // sort across mixed patch/minor/major segments. IsMandatory alternates so the
  // Portal UI can render both banner and dismissable states.
  const updates: UpdateManifestEntry[] = [
    {
      Version: "2.0.0",
      ReleasedAt: T2,
      DownloadUrl: "https://preview.local/2.0.0.zip",
      Sha256: "e".repeat(64),
      MinPreviousVersion: "1.5.0",
      IsMandatory: false,
      Notes: "Preview release notes 2.0.0 (major).",
    },
    {
      Version: "1.5.0",
      ReleasedAt: T2,
      DownloadUrl: "https://preview.local/1.5.0.zip",
      Sha256: "d".repeat(64),
      MinPreviousVersion: "1.4.0",
      IsMandatory: true,
      Notes: "Preview release notes 1.5.0 (security).",
    },
    {
      Version: "1.4.1",
      ReleasedAt: T2,
      DownloadUrl: "https://preview.local/1.4.1.zip",
      Sha256: "c".repeat(64),
      MinPreviousVersion: "1.4.0",
      IsMandatory: false,
      Notes: "Preview release notes 1.4.1 (patch).",
    },
    {
      Version: "1.4.0",
      ReleasedAt: T2,
      DownloadUrl: "https://preview.local/1.4.0.zip",
      Sha256: "a".repeat(64),
      MinPreviousVersion: "1.3.0",
      IsMandatory: false,
      Notes: "Preview release notes 1.4.0.",
    },
    {
      Version: "1.3.0",
      ReleasedAt: T1,
      DownloadUrl: "https://preview.local/1.3.0.zip",
      Sha256: "b".repeat(64),
      MinPreviousVersion: "1.2.0",
      IsMandatory: true,
      Notes: "Preview release notes 1.3.0.",
    },
    {
      Version: "1.2.0",
      ReleasedAt: T0,
      DownloadUrl: "https://preview.local/1.2.0.zip",
      Sha256: "f".repeat(64),
      MinPreviousVersion: "1.1.0",
      IsMandatory: false,
      Notes: "Preview release notes 1.2.0 (baseline).",
    },
  ];
  for (const u of updates) {
    await write<UpdateManifestEntry>("updates", u.Version, u);
  }
}

async function seedQuotas(): Promise<void> {
  const quotas: Quota[] = [
    {
      Id: "01H0000000000000000QUOTA1",
      ResellerId: RESELLER_USER.ResellerId!,
      ResellerName: RESELLER_USER.DisplayName,
      FeatureCode: "core.reports",
      Allocated: 100,
      Used: 42,
      Restored: 3,
      UpdatedAt: T2,
      Version: 2,
    },
    {
      Id: "01H0000000000000000QUOTA2",
      ResellerId: RESELLER_USER.ResellerId!,
      ResellerName: RESELLER_USER.DisplayName,
      FeatureCode: "addon.exports",
      Allocated: 20,
      Used: 20,
      Restored: 0,
      UpdatedAt: T2,
      Version: 4,
    },
    {
      Id: "01H0000000000000000QUOTA3",
      ResellerId: RESELLER_USER.ResellerId!,
      ResellerName: RESELLER_USER.DisplayName,
      FeatureCode: "addon.sso",
      Allocated: 5,
      Used: 1,
      Restored: 0,
      UpdatedAt: T2,
      Version: 1,
    },
  ];
  for (const q of quotas) {
    await write<Quota>("quotas", q.Id, q);
  }
}

async function seedAudit(): Promise<void> {
  // Plan 17 Step 20: 28 rows spanning realistic domain.action EventTypes so the
  // audit page exercises pagination, filtering, and empty-payload branches
  // deterministically. TargetType uses free-form strings (schema field is string).
  const EVENTS: ReadonlyArray<{
    Type: string;
    Target: string;
    TargetId: string;
    Payload: Record<string, unknown>;
  }> = [
    {
      Type: "license.created",
      Target: "license",
      TargetId: "01H00000000000000LIC00001",
      Payload: { Serial: "LARA-AAAA-0001" },
    },
    {
      Type: "license.updated",
      Target: "license",
      TargetId: "01H00000000000000LIC00001",
      Payload: { Field: "CustomerEmail" },
    },
    {
      Type: "license.suspended",
      Target: "license",
      TargetId: "01H00000000000000LIC00002",
      Payload: { Reason: "non-payment" },
    },
    {
      Type: "license.reactivated",
      Target: "license",
      TargetId: "01H00000000000000LIC00002",
      Payload: {},
    },
    {
      Type: "license.revoked",
      Target: "license",
      TargetId: "01H00000000000000LIC00003",
      Payload: { Reason: "expired" },
    },
    {
      Type: "license.renewed",
      Target: "license",
      TargetId: "01H00000000000000LIC00001",
      Payload: { ExpiresAt: "2027-01-01T00:00:00Z" },
    },
    {
      Type: "license.activation.added",
      Target: "license",
      TargetId: "01H00000000000000LIC00001",
      Payload: { DeviceId: "dev-01" },
    },
    {
      Type: "license.activation.removed",
      Target: "license",
      TargetId: "01H00000000000000LIC00001",
      Payload: { DeviceId: "dev-02" },
    },
    {
      Type: "quota.updated",
      Target: "quota",
      TargetId: "01H0000000000000000QUOTA1",
      Payload: { From: 80, To: 100 },
    },
    {
      Type: "quota.allocated",
      Target: "quota",
      TargetId: "01H0000000000000000QUOTA2",
      Payload: { Allocated: 20 },
    },
    {
      Type: "quota.restored",
      Target: "quota",
      TargetId: "01H0000000000000000QUOTA1",
      Payload: { Amount: 3 },
    },
    {
      Type: "quota.exhausted",
      Target: "quota",
      TargetId: "01H0000000000000000QUOTA2",
      Payload: {},
    },
    { Type: "feature.enabled", Target: "feature", TargetId: "core.reports", Payload: {} },
    { Type: "feature.disabled", Target: "feature", TargetId: "addon.sso", Payload: {} },
    {
      Type: "feature.updated",
      Target: "feature",
      TargetId: "addon.exports",
      Payload: { IsBillable: true },
    },
    {
      Type: "auth.login",
      Target: "user",
      TargetId: ADMIN_USER.Id,
      Payload: { Device: "preview-browser" },
    },
    { Type: "auth.logout", Target: "user", TargetId: ADMIN_USER.Id, Payload: {} },
    {
      Type: "auth.login.failed",
      Target: "user",
      TargetId: ADMIN_USER.Id,
      Payload: { Reason: "bad_password" },
    },
    { Type: "auth.password.reset", Target: "user", TargetId: RESELLER_USER.Id, Payload: {} },
    {
      Type: "user.created",
      Target: "user",
      TargetId: RESELLER_USER.Id,
      Payload: { Role: "reseller" },
    },
    {
      Type: "user.role.granted",
      Target: "user",
      TargetId: RESELLER_USER.Id,
      Payload: { Role: "reseller" },
    },
    {
      Type: "user.role.revoked",
      Target: "user",
      TargetId: ADMIN_USER.Id,
      Payload: { Role: "auditor" },
    },
    { Type: "user.deactivated", Target: "user", TargetId: RESELLER_USER.Id, Payload: {} },
    {
      Type: "impersonation.started",
      Target: "user",
      TargetId: RESELLER_USER.Id,
      Payload: { By: ADMIN_USER.Id },
    },
    {
      Type: "impersonation.ended",
      Target: "user",
      TargetId: RESELLER_USER.Id,
      Payload: { By: ADMIN_USER.Id },
    },
    {
      Type: "update.published",
      Target: "update",
      TargetId: "1.4.0",
      Payload: { IsMandatory: false },
    },
    {
      Type: "update.installed",
      Target: "update",
      TargetId: "1.3.0",
      Payload: { Instance: "preview-1" },
    },
    {
      Type: "runtime.config.updated",
      Target: "runtime-config",
      TargetId: "current",
      Payload: { PreviewSeed: "default" },
    },
  ];
  const times = [T0, T1, T2];
  const events: AuditEntry[] = EVENTS.map((e, i) => ({
    Id: `01H0000000000000000AUD${String(i + 1).padStart(3, "0")}`,
    EventType: e.Type,
    ActorUserId: i % 5 === 0 ? RESELLER_USER.Id : ADMIN_USER.Id,
    TargetType: e.Target,
    TargetId: e.TargetId,
    RequestId: `req_seed_${String(i + 1).padStart(3, "0")}`,
    OccurredAt: times[i % times.length],
    Payload: e.Payload,
  }));
  for (const e of events) {
    await write<AuditEntry>("audit", e.Id, e);
  }
}

async function seedQuotaRequests(): Promise<void> {
  // Plan 17 Step 21: 6 rows across every QuotaRequest.Status value so the
  // admin cross-shard inbox exercises pending, approved, denied, and
  // cancelled render branches deterministically. ResellerId/UserId use
  // the numeric ids assigned by primeLegacyIdMap (reseller=1, admin=1,
  // reseller-user=2). ResellerSlug is fixed for the preview shard.
  const RESELLER_ID = 1;
  const ADMIN_UID = 1;
  const RESELLER_UID = 2;
  const SLUG = "preview-reseller";
  const rows: AdminQuotaRequestRow[] = [
    {
      QuotaRequestId: 1001,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 1,
      LicenseTierId: 1,
      RequestedDelta: 25,
      ApprovedDelta: null,
      Status: "Pending",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T2,
      DecidedByUserId: null,
      DecidedAt: null,
      DenialReason: null,
      Justification: "Q3 expansion cohort.",
    },
    {
      QuotaRequestId: 1002,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 2,
      LicenseTierId: 2,
      RequestedDelta: 10,
      ApprovedDelta: null,
      Status: "Pending",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T2,
      DecidedByUserId: null,
      DecidedAt: null,
      DenialReason: null,
      Justification: "Trial spillover for pilot.",
    },
    {
      QuotaRequestId: 1003,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 1,
      LicenseTierId: 3,
      RequestedDelta: 50,
      ApprovedDelta: 40,
      Status: "Approved",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T1,
      DecidedByUserId: ADMIN_UID,
      DecidedAt: T2,
      DenialReason: null,
      Justification: "Approved with 40 of 50 requested.",
    },
    {
      QuotaRequestId: 1004,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 3,
      LicenseTierId: 1,
      RequestedDelta: 5,
      ApprovedDelta: 5,
      Status: "Approved",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T1,
      DecidedByUserId: ADMIN_UID,
      DecidedAt: T1,
      DenialReason: null,
      Justification: "SSO addon quota.",
    },
    {
      QuotaRequestId: 1005,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 2,
      LicenseTierId: 4,
      RequestedDelta: 100,
      ApprovedDelta: null,
      Status: "Denied",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T0,
      DecidedByUserId: ADMIN_UID,
      DecidedAt: T1,
      DenialReason: "Exceeds annual entitlement.",
      Justification: "Requested 100 units.",
    },
    {
      QuotaRequestId: 1006,
      ResellerId: RESELLER_ID,
      ResellerSlug: SLUG,
      LicenseCategoryId: 1,
      LicenseTierId: 2,
      RequestedDelta: 15,
      ApprovedDelta: null,
      Status: "Cancelled",
      SubmittedByUserId: RESELLER_UID,
      SubmittedAt: T0,
      DecidedByUserId: null,
      DecidedAt: null,
      DenialReason: null,
      Justification: "Cancelled by reseller.",
    },
  ];
  for (const r of rows) {
    await write<AdminQuotaRequestRow>("quota-requests", String(r.QuotaRequestId), r);
  }
}

async function seedMetrics(): Promise<void> {
  const tiles: KpiTile[] = [
    {
      Key: "licenses.active",
      Label: "Active Licenses",
      Value: 128,
      Unit: "count",
      Delta: 4,
      Trend: "up",
    },
    {
      Key: "licenses.expiring",
      Label: "Expiring 30d",
      Value: 6,
      Unit: "count",
      Delta: -1,
      Trend: "down",
    },
    {
      Key: "quota.utilization",
      Label: "Quota Utilization",
      Value: 62,
      Unit: "percent",
      Delta: 3,
      Trend: "up",
    },
    {
      Key: "updates.adoption",
      Label: "Update Adoption",
      Value: 78,
      Unit: "percent",
      Delta: 0,
      Trend: "flat",
    },
  ];
  await write<{ Tiles: KpiTile[]; GeneratedAt: string }>("metrics", "kpis", {
    Tiles: tiles,
    GeneratedAt: T2,
  });
}

async function seedRuntimeConfig(): Promise<void> {
  const doc: RuntimeConfigDoc = {
    Mode: "preview",
    ApiBaseUrl: null,
    PreviewSeed: "default",
    AllowRuntimeToggle: true,
    Version: "0.556.0",
    UpdatedAt: T2,
  };
  await write<RuntimeConfigDoc>("runtime-config", "current", doc);
}

async function primeLegacyIdMap(): Promise<void> {
  // Deterministic legacy positive-int ids for the bridge steps (Plan 17 Step 5).
  // Order MUST match domain read order: admin-users 1..2, licenses 1..3, resellers 1.
  // Reset each domain first so re-seeding never advances the counter past the fixed values.
  await resetIdMap("admin-users");
  await primeIdMap("admin-users", [ADMIN_USER.Id, RESELLER_USER.Id]);
  await resetIdMap("licenses");
  await primeIdMap("licenses", [
    "01H00000000000000LIC00001",
    "01H00000000000000LIC00002",
    "01H00000000000000LIC00003",
  ]);
  if (RESELLER_USER.ResellerId) {
    await resetIdMap("resellers");
    await primeIdMap("resellers", [RESELLER_USER.ResellerId]);
  }
}

async function seedResellers(): Promise<void> {
  const resellers: AdminReseller[] = [
    {
      Id: "01H000000000000000RSLLR1",
      Name: "Preview Reseller One",
      Slug: "preview-reseller",
      IsActive: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Id: "01H000000000000000RSLLR2",
      Name: "Beta Reseller LLC",
      Slug: "beta-reseller",
      IsActive: true,
      CreatedAt: T0,
      UpdatedAt: T0,
    },
    {
      Id: "01H000000000000000RSLLR3",
      Name: "Gamma Distribution",
      Slug: "gamma-distribution",
      IsActive: true,
      CreatedAt: T0,
      UpdatedAt: T1,
    },
    {
      Id: "01H000000000000000RSLLR4",
      Name: "Delta Partners",
      Slug: "delta-partners",
      IsActive: false,
      CreatedAt: T0,
      UpdatedAt: T1,
    },
    {
      Id: "01H000000000000000RSLLR5",
      Name: "Epsilon Retail",
      Slug: "epsilon-retail",
      IsActive: true,
      CreatedAt: T1,
      UpdatedAt: T2,
    },
    {
      Id: "01H000000000000000RSLLR6",
      Name: "Zeta Wholesale",
      Slug: "zeta-wholesale",
      IsActive: true,
      CreatedAt: T1,
      UpdatedAt: T2,
    },
  ];
  for (const r of resellers) {
    await write<AdminReseller>("resellers" as any, r.Id, r);
  }
}

async function seedAuditExpansion(): Promise<void> {
  const tMinus1 = "2026-07-19T12:00:00Z";
  const extraEvents: AuditEntry[] = [
    {
      Id: "01H0000000000000000AUD999",
      EventType: "abuse.event.blocked",
      ActorUserId: ADMIN_USER.Id,
      TargetType: "abuse",
      TargetId: "1.2.3.4",
      RequestId: "req_seed_999",
      OccurredAt: tMinus1,
      Payload: { Reason: "blacklist_match", Source: "AbuseEventsSeeder" },
    },
    {
      Id: "01H0000000000000000AUD998",
      EventType: "quota.request.denied",
      ActorUserId: ADMIN_USER.Id,
      TargetType: "quota-request",
      TargetId: "1005",
      RequestId: "req_seed_998",
      OccurredAt: tMinus1,
      Payload: { Reason: "Exceeds annual entitlement." },
    },
  ];
  for (const e of extraEvents) {
    await write<AuditEntry>("audit", e.Id, e);
  }
}

async function seedAbuse(): Promise<void> {
  const events: AbuseEvent[] = [];
  const eventTypes: Array<AbuseEvent["EventType"]> = ["AbuseBlocked", "RateLimited"];
  const slugs = ["reseller-a", "reseller-b", "reseller-c"];

  for (let i = 0; i < 12; i++) {
    events.push({
      Id: `01H0000000000000000ABU${String(i + 1).padStart(3, "0")}`,
      EventType: eventTypes[i % 2],
      Target:
        i % 3 === 0
          ? `IP: 192.168.1.${100 + i}`
          : i % 3 === 1
            ? `User: user${i}@example.local`
            : `License: LIC${i}`,
      IpAddress: `192.168.1.${100 + i}`,
      OccurredAt: `2026-07-${String(1 + i).padStart(2, "0")}T12:00:00Z`,
      Metadata: { ResellerSlug: slugs[i % slugs.length] },
    });
  }

  for (const e of events) {
    await write<AbuseEvent>("abuse", e.Id, e);
  }
}

const seed: PreviewSeedFn = async () => {
  await seedUsers();
  await seedFeatures();
  await seedLicenses();
  await seedUpdates();
  await seedQuotas();
  await seedAudit();
  await seedMetrics();
  await seedRuntimeConfig();
  await primeLegacyIdMap();
  await seedQuotaRequests();
  await seedTierFeatures();
  await seedLicenseFeatures();
  await seedResellers();
  await seedAbuse();
  await seedAuditExpansion();
  // impersonation + password-reset intentionally start empty: seeded on demand by their handlers.
};

export const defaultSeed = seed;

export async function loadDefaultSeed(): Promise<{ Hydrated: boolean }> {
  return runSeed("default", seed);
}
