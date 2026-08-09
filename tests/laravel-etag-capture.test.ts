import { beforeEach, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import {
  captureEtag,
  clearEtags,
  etagKey,
  readEtag,
} from "../backend/resources/js/lib/lara-etag";

/**
 * Plan 06 step 75. Guards the response-side ETag capture cache and its wiring.
 */
describe("lara-etag capture cache", () => {
  beforeEach(() => clearEtags());

  it("normalizes the resource key across query strings and trailing slashes", () => {
    const key = etagKey("/Api/Admin/Licenses/ABC");
    expect(etagKey("/Api/Admin/Licenses/ABC?ResellerSlug=acme")).toBe(key);
    expect(etagKey("/api/admin/licenses/ABC/")).toBe(key);
  });

  it("captures a strong ETag from fetch Headers and serves it back", () => {
    const headers = new Headers({ ETag: '"abc123"' });
    expect(captureEtag("/Api/Admin/Licenses/K1?ResellerSlug=acme", headers)).toBe('"abc123"');
    expect(readEtag("/Api/Admin/Licenses/K1")).toBe('"abc123"');
  });

  it("captures from a plain axios headers object", () => {
    captureEtag("/Api/Admin/Licenses/K2", { etag: '"deadbeef"' });
    expect(readEtag("/Api/Admin/Licenses/K2")).toBe('"deadbeef"');
  });

  it("rejects weak, wildcard, empty and missing validators", () => {
    expect(captureEtag("/x", new Headers({ ETag: 'W/"weak"' }))).toBeNull();
    expect(captureEtag("/x", new Headers({ ETag: "*" }))).toBeNull();
    expect(captureEtag("/x", new Headers({ ETag: "  " }))).toBeNull();
    expect(captureEtag("/x", new Headers())).toBeNull();
    expect(captureEtag("/x", null)).toBeNull();
    expect(readEtag("/x")).toBeNull();
  });

  it("keeps the freshest value per resource without cross-talk", () => {
    captureEtag("/Api/Admin/Licenses/K3", { etag: '"v1"' });
    captureEtag("/Api/Admin/Licenses/K3", { etag: '"v2"' });
    captureEtag("/Api/Admin/Licenses/K4", { etag: '"other"' });
    expect(readEtag("/Api/Admin/Licenses/K3")).toBe('"v2"');
    expect(readEtag("/Api/Admin/Licenses/K4")).toBe('"other"');
  });

  it("wires capture into the fetch client and the axios response interceptor", () => {
    const api = readFileSync(
      resolve(__dirname, "../backend/resources/js/lib/lara-api.ts"),
      "utf8",
    );
    expect(api).toContain("captureEtag(path, response.headers)");
    expect(api).toContain("readEtag(path) ?? options.ifMatch");

    const bootstrap = readFileSync(
      resolve(__dirname, "../backend/resources/js/bootstrap.ts"),
      "utf8",
    );
    expect(bootstrap).toContain("interceptors.response.use");
    expect(bootstrap).toContain("captureEtag(");
  });

  it("makes the license detail surface read its validator from the cache", () => {
    const component = readFileSync(
      resolve(__dirname, "../backend/resources/js/Components/admin/LicenseDetailActions.tsx"),
      "utf8",
    );
    expect(component).toContain("readEtag(resourcePath)");
    expect(component).toContain("const effectiveEtag = liveEtag ?? etag");
    expect(component).not.toContain("ifMatch: etag as string");
  });
});
