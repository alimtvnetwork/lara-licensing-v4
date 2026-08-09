/**
 * Preview fixtures barrel (Plan 16 Step 34).
 */

import type { PreviewFixtureModule } from "./_module";
import auth from "./auth";
import licenses from "./licenses";
import resellers from "./resellers";
import features from "./features";
import updates from "./updates";
import serials from "./serials";
import quotas from "./quotas";
import quotaRequests from "./quota-requests";
import impersonation from "./impersonation";
import audit from "./audit";
import metrics from "./metrics";
import abuse from "./abuse";
import me from "./me";
import passwordReset from "./password-reset";
import adminUsers from "./admin-users";
import runtimeConfig from "./runtime-config";
import tierFeatures from "./tier-features";
import licenseFeatures from "./license-features";
import * as stubs from "./stubs";

export const PREVIEW_FIXTURE_MODULES: readonly PreviewFixtureModule[] = [
  auth,
  resellers,
  licenses,
  features,
  updates,
  serials,
  quotas,
  quotaRequests,
  impersonation,
  audit,
  metrics,
  abuse,
  me,
  passwordReset,
  adminUsers,
  runtimeConfig,
  tierFeatures,
  licenseFeatures,
  { name: "stubs", register: stubs.registerStubHandlers },
] as const;

export const PREVIEW_FIXTURE_MODULE_NAMES = [
  "auth",
  "resellers",
  "licenses",
  "features",
  "updates",
  "serials",
  "quotas",
  "quota-requests",
  "impersonation",
  "audit",
  "metrics",
  "abuse",
  "me",
  "password-reset",
  "admin-users",
  "runtime-config",
  "tier-features",
  "license-features",
  "stubs",
] as const;

export function registerAllPreviewHandlers(): void {
  for (const mod of PREVIEW_FIXTURE_MODULES) {
    mod.register();
  }
}

export type { PreviewFixtureModule } from "./_module";
