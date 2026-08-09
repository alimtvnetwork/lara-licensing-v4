/**
 * Plan 16 Step 37 + Plan 17 Step 25: `empty` seed writes auth records
 * AND config-tier data (feature catalog + tier-features) so admin
 * lookup screens render, but every transactional domain stays empty
 * so empty-state UIs render authentically.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, read, list } from "../src/lib/preview-store";
import { loadEmptySeed } from "../src/lib/preview-seeds/empty";
import {
  CONFIG_FEATURE_CODES,
  CONFIG_TIER_FEATURE_COUNT,
} from "../src/lib/preview-seeds/config";

describe("preview-seed:empty", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("seeds credentials and me pointer only", async () => {
    const result = await loadEmptySeed();
    expect(result.Hydrated).toBe(true);

    const creds = await read<Record<string, string>>("auth", "credentials");
    expect(creds).toMatchObject({ "admin@lara.local": "preview-admin" });

    const me = await read("me", "current");
    expect(me).toBeDefined();

    const users = await list("admin-users");
    expect(users.length).toBe(2);
  });

  it("hydrates the config-tier surface (features + tier-features)", async () => {
    await loadEmptySeed();
    const features = await list("features");
    expect(features.length).toBe(CONFIG_FEATURE_CODES.length);
    const tierFeatures = await list("tier-features");
    expect(tierFeatures.length).toBe(CONFIG_TIER_FEATURE_COUNT);
  });

  it("leaves every transactional domain empty", async () => {
    await loadEmptySeed();
    for (const domain of [
      "licenses",
      "updates",
      "serials",
      "quotas",
      "audit",
      "metrics",
      "impersonation",
      "password-reset",
      "license-features",
    ] as const) {
      const rows = await list(domain);
      expect(rows, `domain ${domain} must start empty`).toEqual([]);
    }
  });

  it("is idempotent across reloads via hydrateOnce", async () => {
    const first = await loadEmptySeed();
    const second = await loadEmptySeed();
    expect(first.Hydrated).toBe(true);
    expect(second.Hydrated).toBe(false);
  });
});
