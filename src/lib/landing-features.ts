// Typed catalog for the landing feature grid.
// Plan 09 Step 13 dependency: HeroSection + FeatureGrid must consume a
// typed module, never inline strings. Keys stay PascalCase per the project
// naming rule for JSON/data models even in TS-only modules.

export type LandingFeatureId =
  | "IssueAndRevoke"
  | "VerifyOffline"
  | "QuotaAndFeatures"
  | "AuditAndImpersonation"
  | "SelfUpdate"
  | "MultiTenantShards";

export interface LandingFeature {
  Id: LandingFeatureId;
  Title: string;
  Summary: string;
}

export const LANDING_FEATURES: readonly LandingFeature[] = [
  {
    Id: "IssueAndRevoke",
    Title: "Issue and revoke",
    Summary:
      "Admins and resellers issue tiered licenses with idempotent, ETag-guarded mutations and one-shot quota restore on revoke.",
  },
  {
    Id: "VerifyOffline",
    Title: "Verify anywhere",
    Summary:
      "Serial, hash, and final verify endpoints back end-user handshakes with fingerprint binding and cooldown protection.",
  },
  {
    Id: "QuotaAndFeatures",
    Title: "Quota and features",
    Summary:
      "Feature catalog, per-license entitlements, and quota requests with reseller submission plus admin approval fanout.",
  },
  {
    Id: "AuditAndImpersonation",
    Title: "Audit and impersonation",
    Summary:
      "Every mutation lands in the audit ledger with a caller lineage; scoped impersonation is timeboxed and idempotent.",
  },
  {
    Id: "SelfUpdate",
    Title: "Signed self-update",
    Summary:
      "Publish app updates with SHA-256 plus Ed25519 signatures, atomic yank, and stable channel rollout for v1.0.",
  },
  {
    Id: "MultiTenantShards",
    Title: "Split-DB tenancy",
    Summary:
      "Root registry plus per-reseller shard databases isolate license data, keyed by prefix, with cross-tenant fanout for admins.",
  },
] as const;
