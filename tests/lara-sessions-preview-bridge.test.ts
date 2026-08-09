/**
 * Plan 17 Step 10: `userSessionsQueryOptions` + `revokeAuthSession`
 * preview bridge.
 *
 * Root cause locked here: both entry points in `src/lib/lara-sessions.ts`
 * unconditionally called `requestLaraApi(...)`, which
 * `assertRequestNotPreview` blocks in `Mode=preview`. There is no
 * `admin.sessions.*` operation in the modern OpenAPI, so the correct
 * minimum bridge is an explicit empty list + no-op revoke with a
 * structured log; the panel renders its empty-state.
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  revokeAuthSession,
  userSessionsQueryOptions,
} from "@/lib/lara-sessions";
import { freezeRuntimeMode } from "@/lib/runtime-mode";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

describe("lara-sessions preview bridge", () => {
  beforeEach(async () => {
    await resetPreviewStore();
    registerAllPreviewHandlers();
    freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
  });

  it("returns [] without calling requestLaraApi", async () => {
    const info = vi.spyOn(console, "info").mockImplementation(() => {});
    const rows = await userSessionsQueryOptions(1, false).queryFn!({} as never);
    expect(rows).toEqual([]);
    expect(info).toHaveBeenCalledWith(
      "lara-sessions:preview-bridge:list",
      expect.objectContaining({ UserId: 1, IncludeEnded: false, Count: 0 }),
    );
    info.mockRestore();
  });

  it("revoke is a logged no-op in preview", async () => {
    const info = vi.spyOn(console, "info").mockImplementation(() => {});
    await expect(
      revokeAuthSession("11111111-1111-1111-1111-111111111111"),
    ).resolves.toBeUndefined();
    expect(info).toHaveBeenCalledWith(
      "lara-sessions:preview-bridge:revoke:noop",
      expect.objectContaining({ SessionId: "11111111-1111-1111-1111-111111111111" }),
    );
    info.mockRestore();
  });
});
