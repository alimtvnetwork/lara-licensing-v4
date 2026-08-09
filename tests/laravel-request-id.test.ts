import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import {
  isMutatingMethod,
  mintRequestId,
  MUTATING_METHODS,
} from "../backend/resources/js/lib/lara-request-id";

/**
 * Plan 06 step 74. `RequestIdMiddleware::REQUEST_ID_REGEX` is
 * `^[A-Za-z0-9-]{16,64}$` and the strict prefixes (`api/admin/`, `api/verify/`,
 * `api/app/updateasset/`) throw `RequestIdMissing` on a malformed header, so the
 * minted shape is a contract.
 */
const MIDDLEWARE_REGEX = /^[A-Za-z0-9-]{16,64}$/;

describe("lara request id minter", () => {
  it("mints ids accepted by RequestIdMiddleware::REQUEST_ID_REGEX", () => {
    for (let i = 0; i < 200; i += 1) {
      expect(mintRequestId()).toMatch(MIDDLEWARE_REGEX);
    }
  });

  it("mints unique ids per call", () => {
    const seen = new Set(Array.from({ length: 200 }, () => mintRequestId()));
    expect(seen.size).toBe(200);
  });

  it("treats exactly the mutating verbs as requiring a correlation id", () => {
    expect([...MUTATING_METHODS]).toEqual(["POST", "PUT", "PATCH", "DELETE"]);
    for (const method of ["post", "PUT", "patch", "DELETE"]) {
      expect(isMutatingMethod(method)).toBe(true);
    }
    for (const method of ["GET", "get", "head", "OPTIONS", undefined, ""]) {
      expect(isMutatingMethod(method)).toBe(false);
    }
  });
});

describe("axios X-Request-Id interceptor", () => {
  const bootstrap = readFileSync("backend/resources/js/bootstrap.ts", "utf8");
  const api = readFileSync("backend/resources/js/lib/lara-api.ts", "utf8");

  it("registers a request interceptor on the shared axios instance", () => {
    expect(bootstrap).toContain("window.axios.interceptors.request.use");
    expect(bootstrap).toContain("mintRequestId");
    expect(bootstrap).toContain("isMutatingMethod(config.method)");
  });

  it("does not overwrite an id a caller already set", () => {
    expect(bootstrap).toContain("config.headers?.['X-Request-Id']");
  });

  it("keeps the fetch client on the same minter", () => {
    expect(api).toContain('from "./lara-request-id"');
    expect(api).not.toMatch(/Date\.now\(\)\.toString\(16\)/);
  });
});
