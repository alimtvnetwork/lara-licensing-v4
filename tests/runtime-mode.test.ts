/**
 * Runtime-mode resolver + loader + hook unit tests (Plan 16 Step 18).
 *
 * Covers spec/28-runtime-modes/02-mode-selection-precedence.md:
 *   compile-time default < /version.json (Version match) < localStorage override (Version match)
 * plus SSR safety, invalid-payload fallbacks, override writer semantics, and
 * F-01 single-resolve freeze from version-json-loader.
 */
import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";

import {
  PACKAGE_VERSION,
  RUNTIME_OVERRIDE_KEY,
  getCompileTimeDefault,
  getRuntimeMode,
  isRuntimeModeFrozen,
  loadVersionJson,
  resetRuntimeMode,
  resolveRuntimeConfig,
  type VersionJson,
} from "@/lib/runtime-mode";
import {
  bootRuntimeConfig,
  clearRuntimeOverride,
  readRawOverride,
  resetBootForTests,
  writeRuntimeOverride,
} from "@/lib/version-json-loader";
import { useRuntimeMode } from "@/hooks/use-runtime-mode";

function mockFetchJson(body: unknown, ok = true, status = 200): typeof fetch {
  return vi.fn(async () => ({
    ok,
    status,
    json: async () => body,
  })) as unknown as typeof fetch;
}

function mockFetchThrows(): typeof fetch {
  return vi.fn(async () => {
    throw new Error("network-down");
  }) as unknown as typeof fetch;
}

function validVersionJson(overrides: Partial<VersionJson> = {}): VersionJson {
  return {
    Mode: "production",
    ApiBaseUrl: "https://api.example.com",
    PreviewSeed: "default",
    Version: PACKAGE_VERSION,
    UpdatedAt: "2026-07-20T00:00:00Z",
    AllowRuntimeToggle: true,
    ...overrides,
  };
}

beforeEach(() => {
  localStorage.clear();
  resetRuntimeMode();
  resetBootForTests();
  vi.spyOn(console, "error").mockImplementation(() => {});
});

describe("resolveRuntimeConfig precedence", () => {
  it("falls back to compile-time default when /version.json 404s", async () => {
    const cfg = await resolveRuntimeConfig(mockFetchJson({}, false, 404));
    expect(cfg).toEqual(getCompileTimeDefault());
    expect(console.error).toHaveBeenCalled();
  });

  it("falls back to compile-time default when fetch throws (offline)", async () => {
    const cfg = await resolveRuntimeConfig(mockFetchThrows());
    expect(cfg.Mode).toBe("preview");
    expect(cfg.ApiBaseUrl).toBeNull();
  });

  it("uses /version.json when Version matches PACKAGE_VERSION", async () => {
    const cfg = await resolveRuntimeConfig(mockFetchJson(validVersionJson()));
    expect(cfg).toEqual({
      Mode: "production",
      ApiBaseUrl: "https://api.example.com",
      PreviewSeed: "default",
    });
  });

  it("ignores /version.json when Version mismatches (INV-RM-09)", async () => {
    const cfg = await resolveRuntimeConfig(
      mockFetchJson(validVersionJson({ Version: "0.0.0-stale" })),
    );
    expect(cfg).toEqual(getCompileTimeDefault());
  });

  it("rejects invalid Mode value", async () => {
    const cfg = await resolveRuntimeConfig(
      mockFetchJson({ ...validVersionJson(), Mode: "staging" }),
    );
    expect(cfg).toEqual(getCompileTimeDefault());
  });

  it("rejects non-null ApiBaseUrl in preview mode (INV-RM-02)", async () => {
    const cfg = await resolveRuntimeConfig(
      mockFetchJson({
        ...validVersionJson(),
        Mode: "preview",
        ApiBaseUrl: "https://oops.example.com",
      }),
    );
    expect(cfg).toEqual(getCompileTimeDefault());
  });

  it("rejects missing http(s):// prefix in production", async () => {
    const cfg = await resolveRuntimeConfig(
      mockFetchJson({ ...validVersionJson(), ApiBaseUrl: "ftp://bad" }),
    );
    expect(cfg).toEqual(getCompileTimeDefault());
  });

  it("localStorage override with matching Version wins over /version.json", async () => {
    localStorage.setItem(
      RUNTIME_OVERRIDE_KEY,
      JSON.stringify({
        Mode: "dev",
        ApiBaseUrl: "http://localhost:8000",
        PreviewSeed: "empty",
        Version: PACKAGE_VERSION,
        WrittenAt: "2026-07-20T00:00:00Z",
      }),
    );
    const cfg = await resolveRuntimeConfig(mockFetchJson(validVersionJson()));
    expect(cfg).toEqual({
      Mode: "dev",
      ApiBaseUrl: "http://localhost:8000",
      PreviewSeed: "empty",
    });
  });

  it("localStorage override with mismatched Version is discarded (P-02)", async () => {
    localStorage.setItem(
      RUNTIME_OVERRIDE_KEY,
      JSON.stringify({
        Mode: "dev",
        ApiBaseUrl: "http://localhost:8000",
        PreviewSeed: "default",
        Version: "0.0.0-old",
        WrittenAt: "2020-01-01T00:00:00Z",
      }),
    );
    const cfg = await resolveRuntimeConfig(mockFetchJson(validVersionJson()));
    expect(cfg.Mode).toBe("production");
  });

  it("malformed override JSON is logged and ignored", async () => {
    localStorage.setItem(RUNTIME_OVERRIDE_KEY, "{not json");
    const cfg = await resolveRuntimeConfig(mockFetchJson(validVersionJson()));
    expect(cfg.Mode).toBe("production");
    expect(console.error).toHaveBeenCalled();
  });
});

describe("loadVersionJson", () => {
  it("returns ok:false on HTTP error", async () => {
    const r = await loadVersionJson(mockFetchJson({}, false, 500));
    expect(r.ok).toBe(false);
  });

  it("returns ok:false on schema mismatch", async () => {
    const r = await loadVersionJson(mockFetchJson({ Mode: "preview" }));
    expect(r.ok).toBe(false);
  });

  it("returns ok:true on valid document", async () => {
    const r = await loadVersionJson(mockFetchJson(validVersionJson()));
    expect(r.ok).toBe(true);
  });
});

describe("writeRuntimeOverride / clearRuntimeOverride", () => {
  it("writes a schema-shaped override that resolver accepts", async () => {
    const wrote = writeRuntimeOverride({
      Mode: "dev",
      ApiBaseUrl: "http://localhost:8000",
      PreviewSeed: "default",
    });
    expect(wrote).toBe(true);
    const raw = readRawOverride();
    expect(raw).not.toBeNull();
    const parsed = JSON.parse(raw as string);
    expect(parsed.Version).toBe(PACKAGE_VERSION);
    expect(typeof parsed.WrittenAt).toBe("string");

    const cfg = await resolveRuntimeConfig(mockFetchJson(validVersionJson()));
    expect(cfg.Mode).toBe("dev");
  });

  it("clearRuntimeOverride removes the key", () => {
    writeRuntimeOverride({ Mode: "dev", ApiBaseUrl: "http://x", PreviewSeed: "default" });
    expect(readRawOverride()).not.toBeNull();
    clearRuntimeOverride();
    expect(readRawOverride()).toBeNull();
  });
});

describe("bootRuntimeConfig freezes exactly once (F-01/F-02)", () => {
  it("returns identical config across concurrent callers", async () => {
    const f = mockFetchJson(validVersionJson());
    const [a, b, c] = await Promise.all([bootRuntimeConfig(f), bootRuntimeConfig(f), bootRuntimeConfig(f)]);
    expect(a).toEqual(b);
    expect(b).toEqual(c);
    expect(isRuntimeModeFrozen()).toBe(true);
    // Only one HTTP fetch across three callers (single in-flight promise).
    expect((f as unknown as ReturnType<typeof vi.fn>).mock.calls.length).toBe(1);
  });

  it("post-freeze boot returns the frozen value without re-fetching", async () => {
    await bootRuntimeConfig(mockFetchJson(validVersionJson()));
    const second = mockFetchJson(validVersionJson({ Mode: "dev", ApiBaseUrl: "http://x" }));
    const cfg = await bootRuntimeConfig(second);
    expect(cfg).toEqual(getRuntimeMode());
    expect(cfg.Mode).toBe("production");
    expect((second as unknown as ReturnType<typeof vi.fn>).mock.calls.length).toBe(0);
  });
});

describe("useRuntimeMode hook", () => {
  it("renders compile-time default before hydration, then resolves", async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = mockFetchJson(validVersionJson());
    try {
      const { result } = renderHook(() => useRuntimeMode());
      // Initial synchronous render must equal the compile-time default (F-04).
      expect(result.current.Config).toEqual(getCompileTimeDefault());
      await waitFor(() => {
        expect(result.current.Config.Mode).toBe("production");
      });
      await act(async () => {
        await Promise.resolve();
      });
      expect(result.current.IsFrozen).toBe(true);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});
