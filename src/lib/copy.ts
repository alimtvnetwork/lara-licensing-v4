// spec/24-app-ui-design-system/56-copy-dictionary.md
// Single normative source for user-visible strings. Consumers import
// `copy` from '@/lib/copy'. Hand-edited entries MUST match the spec §3..§8.
// Any change here requires the matching row in 56-copy-dictionary.md.
//
// v0.301.0: added `errorsByCode` map keyed by the exact
// `ApiErrorCodeType` string. `copy.errors` retains its spec-owned semantic
// aliases (AuthFailed / Unauthorized / Forbidden etc.); `errorsByCode` is
// the enforced source of truth for `formatLaraApiError` and the coverage
// test in `tests/error-copy-coverage.test.ts`. Do not delete a code entry
// without removing the enum member from `src/lib/lara-api-error.ts` in the
// same commit.
import { ApiErrorCodeType } from "./lara-api-error";

export const copy = {
  buttons: {
    save: "Save",
    discard: "Discard",
    close: "Close",
    cancel: "Cancel",
    retry: "Retry",
    refresh: "Refresh",
    copy: "Copy",
    copied: "Copied",
    reveal: "Reveal",
    hide: "Hide",
    upload: "Upload",
    filter: "Filter",
    resetFilters: "Reset filters",
    search: "Search",
    signIn: "Sign in",
    signOut: "Sign out",
    signOutEverywhere: "Sign out everywhere",
    assignRole: "Assign role",
    removeRole: "Remove role",
    approve: "Approve",
    adjust: "Adjust",
    deny: "Deny",
    rebind: "Rebind",
    requestRebind: "Request rebind",
    rotateSecret: "Rotate secret",
    publishUpdate: "Publish update",
    issueLicense: "Issue license",
    revokeLicense: "Revoke license",
    revokeSerial: "Revoke serial",
    issueSerial: "Issue serial",
    requestQuota: "Request quota",
    inviteUser: "Invite user",
    registerClient: "Register client",
  },
  labels: {
    email: "Email",
    password: "Password",
    oneTimeCode: "One-time code",
    serial: "Serial",
    reason: "Reason",
    justification: "Justification",
    delta: "Delta",
    tier: "Tier",
    environment: "Environment",
    featureKey: "Feature key",
    featureLabel: "Feature label",
    expiry: "Expiry",
    machineBindingCount: "Machine binding count",
    reseller: "Reseller",
    role: "Role",
    label: "Label",
    version: "Version",
    channel: "Channel",
    redirectUri: "Redirect URI",
    description: "Description",
    revealConfirmation: "I have copied the install command",
    phraseConfirmation: "Type the phrase to confirm",
  },
  errors: {
    AuthFailed: "Sign in failed. Check your email and password.",
    Unauthorized: "Your session expired. Sign in again to continue.",
    Forbidden: "You do not have access to this action.",
    LicenseNotFound: "License not found.",
    SerialNotFound: "Serial not found.",
    ClientNotFound: "Client not found.",
    DeviceNotFound: "Device not found.",
    QuotaExhausted: "Quota exhausted. Request more quota before issuing another license.",
    QuotaAlreadyDecided: "This request was already decided. Refresh to see the current decision.",
    EnvironmentMismatch: "This action is not allowed in the current environment.",
    PreconditionFailed: "Someone edited this record while you had it open. Refresh and try again.",
    RateLimited: "Too many requests. Wait {RetryAfterSec} seconds and try again.",
    ValidationFailed: "Some fields need attention.",
    InternalError: "Something failed on our side. Try again in a moment.",
    OAuthStateInvalid: "Sign in was interrupted. Start over from the sign-in page.",
    ClientSecretAlreadyRotated:
      "This secret was rotated by someone else. Refresh to see the current secret.",
  },
  toasts: {
    LicenseIssued: {
      title: "License issued",
      body: "Certificate is ready to download.",
    },
    LicenseRenewed: { title: "License renewed" },
    LicenseRevoked: { title: "License revoked" },
    SerialIssued: {
      title: "Serial issued",
      body: "Serial value shown once; copy it now.",
    },
    SerialVerified: { title: "Serial verified" },
    SerialRevoked: { title: "Serial revoked" },
    ClientRegistered: {
      title: "Client registered",
      body: "Client secret shown once; copy it now.",
    },
    ClientSecretRotated: { title: "Client secret rotated" },
    UpdatePublished: { title: "Update published" },
    UpdateRetracted: { title: "Update retracted" },
    QuotaRequestSubmitted: {
      title: "Quota request submitted",
      body: "An admin will review it shortly.",
    },
    QuotaRequestApproved: { title: "Request approved" },
    QuotaRequestAdjusted: { title: "Request adjusted" },
    QuotaRequestDenied: { title: "Request denied" },
    RoleAssigned: { title: "Role assigned" },
    RoleRemoved: { title: "Role removed" },
    FeatureAdded: { title: "Feature added" },
    FeatureDeprecated: {
      title: "Feature deprecated",
      body: "Existing licenses keep this feature.",
    },
    SignedOutEverywhere: {
      title: "Signed out of all sessions",
      body: "Redirecting...",
    },
    CopyFailed: {
      title: "Copy failed",
      body: "Select the text and copy it manually.",
    },
    NetworkOffline: {
      title: "You are offline",
      body: "Actions will retry when the connection returns.",
    },
    StillWorking: { title: "Still working..." },
  },
  phrases: {
    revokeLicense: "REVOKE",
    revokeSerial: "REVOKE",
    deleteUser: "DELETE",
    disableUser: "DISABLE",
    denyQuota: "DENY",
    signOutEverywhere: "SIGN OUT",
  },
  plurals: {
    license: { one: "license", other: "licenses" },
    serial: { one: "serial", other: "serials" },
    user: { one: "user", other: "users" },
    device: { one: "device", other: "devices" },
    session: { one: "session", other: "sessions" },
    feature: { one: "feature", other: "features" },
    environment: { one: "environment", other: "environments" },
    tier: { one: "tier", other: "tiers" },
    client: { one: "client", other: "clients" },
    update: { one: "update", other: "updates" },
    request: { one: "request", other: "requests" },
    role: { one: "role", other: "roles" },
    override: { one: "override", other: "overrides" },
    product: { one: "product", other: "products" },
    reseller: { one: "reseller", other: "resellers" },
  },
} as const;

export type CopyDictionary = typeof copy;
export type PluralNoun = keyof CopyDictionary["plurals"];

export function pluralize(n: number, noun: PluralNoun): string {
  const forms = copy.plurals[noun];

  return n === 1 ? forms.one : forms.other;
}

export function pluralCount(n: number, noun: PluralNoun): string {
  return `${n} ${pluralize(n, noun)}`;
}

// Substitutes numeric interpolation into a §5 error template. Only
// `RateLimited` uses this pathway today; extending the set requires a
// matching row in the copy dictionary.
export function formatRateLimited(retryAfterSec: number): string {
  return copy.errors.RateLimited.replace("{RetryAfterSec}", String(retryAfterSec));
}

/**
 * v0.302.0. `errorsByCode` and `copyForErrorCode` now live in
 * `src/lib/error-copy.ts` to break a runtime cycle with
 * `src/lib/lara-api-error.ts`. Re-exported here so existing importers
 * (`src/lib/copy` consumers, coverage test) keep working unchanged.
 */
export { errorsByCode, copyForErrorCode } from "./error-copy";
