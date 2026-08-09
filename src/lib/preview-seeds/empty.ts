/**
 * Empty preview seed (Plan 16 Step 37).
 *
 * Populates ONLY the minimum needed to sign in (auth credentials + `me`
 * pointer + the two seed users under `admin-users`) so preview login
 * still succeeds. Every other domain (licenses, features, updates,
 * serials, quotas, audit, metrics, impersonation, password-reset) is
 * intentionally left empty so empty-state UIs render authentically.
 */

import type { AdminUser, MeUser } from "@/generated/api/schema";
import { write } from "../preview-store";
import { primeIdMap, resetIdMap } from "../preview-id-map";
import { runSeed, type PreviewSeedFn } from "./_contract";
import { hydrateConfig } from "./config";

const T0 = "2026-01-01T00:00:00Z";
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

function asAdmin(u: MeUser): AdminUser {
  return { ...u, IsActive: true, LastLoginAt: T2, Version: 1 };
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

async function primeLegacyIdMap(): Promise<void> {
  // Empty seed still needs admin-users 1..2 so `/Users/Me` numeric id is stable.
  await resetIdMap("admin-users");
  await primeIdMap("admin-users", [ADMIN_USER.Id, RESELLER_USER.Id]);
  await resetIdMap("licenses");
  await resetIdMap("resellers");
}

const seed: PreviewSeedFn = async () => {
  await seedAuth();
  await primeLegacyIdMap();
  // Plan 17 Step 25: hydrate config-tier surface (feature catalog +
  // tier-features) in `empty` too. Transactional domains (licenses,
  // updates, quotas, audit, license-features, metrics, impersonation,
  // password-reset, serials) stay empty so empty-state UIs render
  // authentically. Handlers MUST tolerate absent keys and return
  // canonical empty envelopes (Data: [] or null), never throw.
  await hydrateConfig();
};

export const emptySeed = seed;

export async function loadEmptySeed(): Promise<{ Hydrated: boolean }> {
  return runSeed("empty", seed);
}
