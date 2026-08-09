/**
 * Plan 16 step 59a (v0.564.0). Vitest coverage for the runtime-config
 * preview handlers reached via `apiClient.call` in preview mode.
 *
 * Root cause guarded (one sentence): after v0.563.0 landed the backend
 * `PUT /Api/Admin/RuntimeConfig` with a closed-set of runtime-config
 * error codes (RuntimeConfigConflict / RuntimeConfigLocked /
 * RuntimeConfigModeMismatch), the FE preview handler at
 * `src/lib/preview-fixtures/runtime-config.ts` still emits generic
 * `PreconditionFailed`/`AuthForbidden`/`ValidationFailed` codes with no
 * test pinning either the codes or the HTTP status, so drift between
 * preview (INV-RM-06) and live could ship green without warning.
 *
 * Coverage:
 *   1. show(): happy path returns typed `RuntimeConfigDoc` and defaults
 *      when the IDB store is empty (documents the default doc contract).
 *   2. update(): happy path bumps `UpdatedAt` and persists (round-trip
 *      via a follow-up show()).
 *   3. update(): stale If-Match -> LaraApiError with the *current* FE
 *      code (PreconditionFailed) AND records the exact status/code so
 *      Plan 16 Step 59b can flip the assertion to RuntimeConfigConflict.
 *   4. update(): Mode="production" without ApiBaseUrl -> current
 *      ValidationFailed 422 (Step 59b: RuntimeConfigModeMismatch 422).
 *   5. update(): AllowRuntimeToggle=false persisted -> current
 *      AuthForbidden 423 (Step 59b: RuntimeConfigLocked 423).
 *   6. error seed: both ops reject with the domain error code.
 *
 * These assertions are intentionally tight against the *current* FE
 * behaviour so the alignment PR (Step 59b) will produce a diff-visible
 * failure that must be handled deliberately, not silently.
 */
import "fake-indexeddb/auto";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { apiClient } from "@/lib/api-client";
import {
  clearPreviewHandlersForTest,
} from "@/lib/preview-transport";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetAll, write } from "@/lib/preview-store";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { freezeRuntimeMode, resetRuntimeMode } from "@/lib/runtime-mode";
import type { RuntimeConfigDoc } from "@/generated/api/schema";

const SHOW = "admin.runtime-config.show" as const;
const UPDATE = "admin.runtime-config.update" as const;

async function primeDoc(overrides: Partial<RuntimeConfigDoc>): Promise<RuntimeConfigDoc> {
  const base: RuntimeConfigDoc = {
    Mode: "preview",
    ApiBaseUrl: null,
    PreviewSeed: "default",
    AllowRuntimeToggle: true,
    Version: "0.564.0",
    UpdatedAt: "2026-07-20T10:00:00Z",
  };
  const doc: RuntimeConfigDoc = { ...base, ...overrides };
  await write<RuntimeConfigDoc>("runtime-config", "current", doc);
  return doc;
}

async function callUpdate(ifMatch: string, patch: Partial<RuntimeConfigDoc>): Promise<RuntimeConfigDoc> {
  return apiClient.call(UPDATE, {
    IfMatch: ifMatch,
    Mode: patch.Mode ?? "preview",
    ApiBaseUrl: patch.ApiBaseUrl ?? null,
    PreviewSeed: patch.PreviewSeed ?? "default",
    AllowRuntimeToggle: patch.AllowRuntimeToggle ?? true,
  });
}

async function expectLaraError(fn: () => Promise<unknown>): Promise<LaraApiError> {
  try {
    await fn();
  } catch (err) {
    if (err instanceof LaraApiError) return err;
    throw err;
  }
  throw new Error("Expected LaraApiError; call resolved.");
}

function useSeed(seed: "default" | "empty" | "error"): void {
  resetRuntimeMode();
  freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: seed });
}

beforeEach(async () => {
  useSeed("default");
  await resetAll();
  clearPreviewHandlersForTest();
  registerAllPreviewHandlers();
});

afterEach(() => {
  resetRuntimeMode();
});

describe("preview-fixtures: admin.runtime-config", () => {
  it("show(): returns deterministic default when store is empty", async () => {
    const doc = await apiClient.call(SHOW, {});
    expect(doc.Mode).toBe("preview");
    expect(doc.ApiBaseUrl).toBeNull();
    expect(doc.AllowRuntimeToggle).toBe(true);
    expect(doc.PreviewSeed).toBe("default");
    expect(doc.UpdatedAt.length).toBeGreaterThan(0);
  });

  it("show(): returns persisted document verbatim", async () => {
    const primed = await primeDoc({ Mode: "dev", ApiBaseUrl: null, PreviewSeed: "" });
    const doc = await apiClient.call(SHOW, {});
    expect(doc.Mode).toBe(primed.Mode);
    expect(doc.UpdatedAt).toBe(primed.UpdatedAt);
  });

  it("update(): happy path bumps UpdatedAt and persists", async () => {
    const primed = await primeDoc({});
    const next = await callUpdate(primed.UpdatedAt, {
      Mode: "preview",
      ApiBaseUrl: null,
      PreviewSeed: "empty",
      AllowRuntimeToggle: true,
    });
    expect(next.PreviewSeed).toBe("empty");
    expect(next.UpdatedAt).not.toBe(primed.UpdatedAt);
    const reread = await apiClient.call(SHOW, {});
    expect(reread.UpdatedAt).toBe(next.UpdatedAt);
    expect(reread.PreviewSeed).toBe("empty");
  });

  it("update(): stale If-Match rejects with PreconditionFailed 412 (INV-RM-06 tie point)", async () => {
    await primeDoc({});
    const err = await expectLaraError(() =>
      callUpdate('"deadbeef"', { Mode: "preview", PreviewSeed: "default" }),
    );
    // Step 59b (v0.564.0): FE preview handler now emits the BE closed-set
    // runtime-config codes. Any drift here means preview and live disagree.
    expect(err.errorCode).toBe(ApiErrorCodeType.RuntimeConfigConflict);
    expect(err.httpStatus).toBe(412);
  });

  it("update(): Mode=production without ApiBaseUrl rejects with RuntimeConfigModeMismatch 422", async () => {
    const primed = await primeDoc({});
    const err = await expectLaraError(() =>
      callUpdate(primed.UpdatedAt, {
        Mode: "production",
        ApiBaseUrl: null,
        PreviewSeed: "",
        AllowRuntimeToggle: true,
      }),
    );
    expect(err.errorCode).toBe(ApiErrorCodeType.RuntimeConfigModeMismatch);
    expect(err.httpStatus).toBe(422);
  });

  it("update(): locked doc rejects with RuntimeConfigLocked 423", async () => {
    const primed = await primeDoc({ AllowRuntimeToggle: false });
    const err = await expectLaraError(() =>
      callUpdate(primed.UpdatedAt, {
        Mode: "preview",
        PreviewSeed: "default",
        AllowRuntimeToggle: false,
      }),
    );
    expect(err.errorCode).toBe(ApiErrorCodeType.RuntimeConfigLocked);
    expect(err.httpStatus).toBe(423);
  });

  it("error seed: show() rejects with the domain error code", async () => {
    useSeed("error");
    const err = await expectLaraError(() => apiClient.call(SHOW, {}));
    expect(err.httpStatus).toBe(422);
  });

  it("error seed: update() rejects with the domain error code", async () => {
    await primeDoc({});
    useSeed("error");
    const err = await expectLaraError(() =>
      callUpdate("2026-07-20T10:00:00Z", { Mode: "preview", PreviewSeed: "default" }),
    );
    expect(err.httpStatus).toBe(422);
  });
});
