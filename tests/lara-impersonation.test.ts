import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * Locks in the Admin impersonation client contract per
 * spec/21-app/46-impersonation.md §4. Guards:
 *  - AC-IMP scaffold: startImpersonation posts to /Users/{id}/Impersonate
 *    with Idempotency-Key and a valid Reason.
 *  - endImpersonation posts to /Impersonation/End with EndReason and key.
 *  - Active record is persisted on start and cleared on end (drives the
 *    banner required by AC-IMP-008).
 */
vi.mock("@/lib/lara-api-client", async () => {
  const actual = await vi.importActual<typeof import("@/lib/lara-api-client")>(
    "@/lib/lara-api-client",
  );
  return { ...actual, requestLaraApi: vi.fn() };
});

import { requestLaraApi } from "@/lib/lara-api-client";
import {
  clearActiveImpersonation,
  endImpersonation,
  readActiveImpersonation,
  startImpersonation,
} from "@/lib/lara-impersonation";

const mocked = vi.mocked(requestLaraApi);

const envelope = {
  SessionId: "11111111-1111-4111-8111-111111111111",
  ImpersonatorUserId: 1,
  TargetUserId: 42,
  Kind: "Impersonation" as const,
  ExpiresAt: "2030-01-01T00:30:00.000Z",
};

beforeEach(() => {
  mocked.mockReset();
  clearActiveImpersonation();
});

describe("startImpersonation", () => {
  it("posts to /Users/{id}/Impersonate with Idempotency-Key and persists the envelope", async () => {
    mocked.mockResolvedValueOnce([envelope]);
    const result = await startImpersonation(42, { Reason: "Debug ticket 1234" }, "key-1");
    expect(result).toEqual(envelope);
    const [path, , options] = mocked.mock.calls[0];
    expect(path).toBe("/Users/42/Impersonate");
    expect(options?.method).toBe("POST");
    expect(options?.headers?.["Idempotency-Key"]).toBe("key-1");
    expect(options?.body).toEqual({ Reason: "Debug ticket 1234" });
    expect(readActiveImpersonation()).toEqual(envelope);
  });

  it("rejects reasons shorter than 8 characters before any wire call", async () => {
    await expect(
      startImpersonation(42, { Reason: "short" }, "key-2"),
    ).rejects.toThrow();
    expect(mocked).not.toHaveBeenCalled();
  });
});

describe("endImpersonation", () => {
  it("posts EndReason and clears the persisted envelope", async () => {
    mocked.mockResolvedValueOnce([envelope]);
    await startImpersonation(42, { Reason: "Debug ticket 1234" }, "key-1");
    expect(readActiveImpersonation()).toBeDefined();

    mocked.mockResolvedValueOnce([
      { SessionId: envelope.SessionId, EndedAt: "2030-01-01T00:10:00.000Z", EndReason: "OperatorEnded" },
    ]);
    await endImpersonation("OperatorEnded", "key-end");
    const [path, , options] = mocked.mock.calls[1];
    expect(path).toBe("/Impersonation/End");
    expect(options?.body).toEqual({ EndReason: "OperatorEnded" });
    expect(options?.headers?.["Idempotency-Key"]).toBe("key-end");
    expect(readActiveImpersonation()).toBeUndefined();
  });
});
