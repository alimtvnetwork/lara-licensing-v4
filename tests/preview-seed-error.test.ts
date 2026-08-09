/**
 * Plan 16 Step 38 test: `error` seed writes only auth records, keeps
 * content domains empty, and exports a complete per-domain error-code map
 * covering every `PreviewDomain`.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, read, list } from "../src/lib/preview-store";
import { PREVIEW_FIXTURE_MODULE_NAMES } from "../src/lib/preview-fixtures";
import { ApiErrorCodeType } from "../src/lib/lara-api-error";
import {
  ERROR_SEED_DOMAIN_CODE,
  loadErrorSeed,
} from "../src/lib/preview-seeds/error";

describe("preview-seed:error", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("seeds only auth records", async () => {
    const result = await loadErrorSeed();
    expect(result.Hydrated).toBe(true);
    const creds = await read<Record<string, string>>("auth", "credentials");
    expect(creds).toMatchObject({ "admin@lara.local": "preview-admin" });
    const users = await list("admin-users");
    expect(users.length).toBe(2);
  });

  it("leaves domains without negative-path rows empty", async () => {
    // Plan 17 Step 24 seeds negative-path rows into `audit`, `quota-requests`,
    // and `features` (see `tests/preview-error-seed-negative-path.test.ts`).
    // All other transactional domains stay empty so their error branches
    // fire against an empty store, exactly like a fresh install.
    await loadErrorSeed();
    for (const domain of ["licenses", "updates", "serials", "quotas", "metrics", "impersonation", "password-reset"] as const) {
      expect(await list(domain)).toEqual([]);
    }
  });

  it("exports a canonical ApiErrorCodeType for every PreviewDomain", () => {
    for (const domain of PREVIEW_FIXTURE_MODULE_NAMES) {
      const code = ERROR_SEED_DOMAIN_CODE[domain];
      expect(code, `domain ${domain} missing from ERROR_SEED_DOMAIN_CODE`).toBeDefined();
      expect(Object.values(ApiErrorCodeType)).toContain(code);
    }
  });

  it("is idempotent across reloads via hydrateOnce", async () => {
    expect((await loadErrorSeed()).Hydrated).toBe(true);
    expect((await loadErrorSeed()).Hydrated).toBe(false);
  });
});
