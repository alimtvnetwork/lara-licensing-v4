/**
 * Plan 16 Step 35: preview-store unit tests.
 * Uses `fake-indexeddb/auto` (loaded via setupFiles or here) to give
 * jsdom a real IndexedDB implementation.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import {
  hydrateOnce,
  list,
  listKeys,
  read,
  remove,
  resetAll,
  resetDomain,
  write,
} from "@/lib/preview-store";

interface User {
  Id: string;
  Email: string;
}

describe("preview-store (Plan 16 Step 35)", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("writes then reads a typed value in a domain", async () => {
    await write<User>("auth", "u1", { Id: "u1", Email: "a@b.co" });
    const got = await read<User>("auth", "u1");
    expect(got).toEqual({ Id: "u1", Email: "a@b.co" });
  });

  it("lists entries and keys scoped to the domain", async () => {
    await write<User>("auth", "u1", { Id: "u1", Email: "a@b.co" });
    await write<User>("auth", "u2", { Id: "u2", Email: "c@d.co" });
    await write("licenses", "l1", { Id: "l1" });
    expect((await listKeys("auth")).sort()).toEqual(["u1", "u2"]);
    expect(await listKeys("licenses")).toEqual(["l1"]);
    const entries = await list<User>("auth");
    expect(entries).toHaveLength(2);
  });

  it("remove() deletes a single key; resetDomain() clears one domain only", async () => {
    await write("auth", "u1", 1);
    await write("licenses", "l1", 1);
    await remove("auth", "u1");
    expect(await read("auth", "u1")).toBeUndefined();
    expect(await read("licenses", "l1")).toBe(1);
    await resetDomain("licenses");
    expect(await read("licenses", "l1")).toBeUndefined();
  });

  it("hydrateOnce runs the hydrator exactly once per seedId", async () => {
    let calls = 0;
    const hydrator = async () => {
      calls += 1;
      await write("auth", "seeded-user", { Id: "s", Email: "s@x.co" });
    };
    const first = await hydrateOnce("default", hydrator);
    const second = await hydrateOnce("default", hydrator);
    expect(first.Hydrated).toBe(true);
    expect(second.Hydrated).toBe(false);
    expect(calls).toBe(1);
    expect(await read("auth", "seeded-user")).toEqual({ Id: "s", Email: "s@x.co" });
  });

  it("resetAll clears seed markers so hydrateOnce runs again", async () => {
    let calls = 0;
    const h = async () => {
      calls += 1;
    };
    await hydrateOnce("default", h);
    await resetAll();
    await hydrateOnce("default", h);
    expect(calls).toBe(2);
  });

  it("rejects unknown domains loudly (no silent swallow)", async () => {
    // @ts-expect-error intentional bad domain
    await expect(read("nope", "x")).rejects.toThrow(/unknown domain/);
  });
});
