/**
 * Plan 17 Step 4 foundation: preview id-map (`src/lib/preview-id-map.ts`).
 *
 * Locks the invariants the subsequent legacy-bridge steps depend on:
 *  - assignNumeric is idempotent per (domain, ulid).
 *  - Ids are positive integers starting at 1, per-domain.
 *  - Reverse lookup ulidFor(numeric) round-trips.
 *  - Concurrent assignNumeric calls for the same ulid resolve to the
 *    same id (no counter races).
 *  - Distinct ulids in the same domain get distinct ids.
 *  - resetIdMap clears the domain and restarts the counter.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import {
  assignNumeric,
  numericFor,
  ulidFor,
  primeIdMap,
  resetIdMap,
} from "../src/lib/preview-id-map";

const DOMAIN = "licenses";
const U1 = "01H00000000000000LIC00001";
const U2 = "01H00000000000000LIC00002";
const U3 = "01H00000000000000LIC00003";

describe("preview-id-map", () => {
  beforeEach(async () => {
    await resetIdMap(DOMAIN);
    await resetIdMap("resellers");
  });

  it("assigns a positive integer starting at 1", async () => {
    const n = await assignNumeric(DOMAIN, U1);
    expect(n).toBe(1);
    expect(await numericFor(DOMAIN, U1)).toBe(1);
    expect(await ulidFor(DOMAIN, 1)).toBe(U1);
  });

  it("is idempotent per (domain, ulid)", async () => {
    const a = await assignNumeric(DOMAIN, U1);
    const b = await assignNumeric(DOMAIN, U1);
    expect(a).toBe(b);
  });

  it("assigns distinct ids to distinct ulids", async () => {
    const a = await assignNumeric(DOMAIN, U1);
    const b = await assignNumeric(DOMAIN, U2);
    expect(a).not.toBe(b);
    expect(new Set([a, b]).size).toBe(2);
  });

  it("keeps counters independent per domain", async () => {
    const a = await assignNumeric(DOMAIN, U1);
    const b = await assignNumeric("resellers", U1);
    expect(a).toBe(1);
    expect(b).toBe(1);
  });

  it("serializes concurrent assigns for the same ulid to one id", async () => {
    const results = await Promise.all([
      assignNumeric(DOMAIN, U3),
      assignNumeric(DOMAIN, U3),
      assignNumeric(DOMAIN, U3),
    ]);
    expect(new Set(results).size).toBe(1);
  });

  it("primeIdMap returns entries in input order", async () => {
    const rows = await primeIdMap(DOMAIN, [U1, U2, U3]);
    expect(rows.map((r) => r.Numeric)).toEqual([1, 2, 3]);
    expect(rows.map((r) => r.Ulid)).toEqual([U1, U2, U3]);
    expect(await ulidFor(DOMAIN, 2)).toBe(U2);
  });

  it("resetIdMap clears the domain and restarts the counter", async () => {
    await primeIdMap(DOMAIN, [U1, U2]);
    await resetIdMap(DOMAIN);
    expect(await numericFor(DOMAIN, U1)).toBeUndefined();
    const n = await assignNumeric(DOMAIN, U1);
    expect(n).toBe(1);
  });

  it("rejects empty ulids", async () => {
    await expect(assignNumeric(DOMAIN, "")).rejects.toThrow(/empty ulid/);
  });
});
