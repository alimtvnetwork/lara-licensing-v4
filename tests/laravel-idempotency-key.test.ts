import { beforeEach, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import {
  attemptFingerprint,
  clearAttempts,
  idempotencyKeyFor,
  mintIdempotencyKey,
  releaseAttempt,
} from "../backend/resources/js/lib/lara-idempotency";

/**
 * Plan 06 step 76. Per-attempt Idempotency-Key with retry reuse.
 */
describe("lara-idempotency attempt keys", () => {
  beforeEach(() => clearAttempts());

  it("mints exactly 32 printable hex chars (KEY_REGEX 16..128, reseller requires 32)", () => {
    for (let i = 0; i < 25; i += 1) {
      const key = mintIdempotencyKey();
      expect(key).toHaveLength(32);
      expect(key).toMatch(/^[0-9a-f]{32}$/);
    }
  });

  it("reuses the key while the identical attempt is retried", () => {
    const body = { Reason: "fraud" };
    const first = idempotencyKeyFor("DELETE", "/Api/Admin/Licenses/K1", body);
    const retry = idempotencyKeyFor("delete", "/Api/Admin/Licenses/K1", { Reason: "fraud" });
    expect(retry).toBe(first);
  });

  it("mints a fresh key once the attempt is confirmed", () => {
    const body = { Seats: 5 };
    const first = idempotencyKeyFor("POST", "/Api/Reseller/QuotaRequests", body);
    releaseAttempt("POST", "/Api/Reseller/QuotaRequests", body);
    expect(idempotencyKeyFor("POST", "/Api/Reseller/QuotaRequests", body)).not.toBe(first);
  });

  it("treats an edited body, another path, or another verb as a new attempt", () => {
    const base = idempotencyKeyFor("POST", "/Api/Reseller/QuotaRequests", { Seats: 5 });
    expect(idempotencyKeyFor("POST", "/Api/Reseller/QuotaRequests", { Seats: 6 })).not.toBe(base);
    expect(idempotencyKeyFor("POST", "/Api/Admin/Serials", { Seats: 5 })).not.toBe(base);
    expect(idempotencyKeyFor("PATCH", "/Api/Reseller/QuotaRequests", { Seats: 5 })).not.toBe(base);
  });

  it("canonicalizes key order so property order cannot fork an attempt", () => {
    expect(attemptFingerprint("POST", "/x", { a: 1, b: 2 })).toBe(
      attemptFingerprint("POST", "/x", { b: 2, a: 1 }),
    );
    expect(attemptFingerprint("POST", "/x?ResellerSlug=a", {})).not.toBe(
      attemptFingerprint("POST", "/x?ResellerSlug=b", {}),
    );
  });

  it("wires the attempt key into the fetch client and the axios request interceptor", () => {
    const api = readFileSync(
      resolve(__dirname, "../backend/resources/js/lib/lara-api.ts"),
      "utf8",
    );
    expect(api).toContain("idempotencyKeyFor(method, path, options.body)");
    expect(api).toContain("releaseAttempt(method, path, options.body)");
    expect(api).not.toContain("idempotencyKey32");

    const bootstrap = readFileSync(
      resolve(__dirname, "../backend/resources/js/bootstrap.ts"),
      "utf8",
    );
    expect(bootstrap).toContain("'Idempotency-Key'");
    expect(bootstrap).toContain("idempotencyKeyFor(");
    expect(bootstrap).toContain("releaseAttempt(");
  });
});
