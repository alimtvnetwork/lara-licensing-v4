import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { errorsByCode } from "@/lib/error-copy";
import {
  laraErrorCopy,
  laraCopyForErrorCode,
  LARA_UNKNOWN_ERROR_COPY,
} from "../backend/resources/js/lib/lara-error-copy";

/**
 * Plan 06 step 77. Guards (a) key parity with the SPA dictionary, (b) the
 * "unknown" fallbacks, and (c) the Inertia error renderer wiring in
 * backend/bootstrap/app.php.
 */
const appPhp = readFileSync("backend/bootstrap/app.php", "utf8");
const errorPage = readFileSync("backend/resources/js/Pages/Error.tsx", "utf8");

describe("laravel inertia error page + spec 12 copy", () => {
  it("has key parity with the SPA copy dictionary", () => {
    expect(Object.keys(laraErrorCopy).sort()).toEqual(Object.keys(errorsByCode).sort());
  });

  it("falls back to the unknown copy for missing or unmapped codes", () => {
    expect(laraCopyForErrorCode(null)).toBe(LARA_UNKNOWN_ERROR_COPY);
    expect(laraCopyForErrorCode(undefined)).toBe(LARA_UNKNOWN_ERROR_COPY);
    expect(laraCopyForErrorCode("")).toBe(LARA_UNKNOWN_ERROR_COPY);
    expect(laraCopyForErrorCode("NotARealCode")).toBe(LARA_UNKNOWN_ERROR_COPY);
  });

  it("interpolates RateLimited only when Retry-After is known", () => {
    expect(laraCopyForErrorCode("RateLimited", { retryAfterSeconds: 30 })).toContain("30");
    expect(laraCopyForErrorCode("RateLimited")).not.toContain("{RetryAfterSec}");
  });

  it("renders unknown placeholders for absent correlation ids", () => {
    expect(errorPage).toContain('{errorCode ?? "unknown"}');
    expect(errorPage).toContain('{requestId || "unknown"}');
    expect(errorPage).toContain('{errorId || "unknown"}');
  });

  it("routes web (Inertia) failures to Pages/Error instead of the JSON envelope", () => {
    const renders = appPhp.match(/Inertia::render\('Error'/g) ?? [];
    expect(renders.length).toBe(2);
    expect(appPhp).toContain("! $request->expectsJson() && ! $request->is('Api/*', 'App/*')");
    expect(appPhp).toContain("'errorId' => $e->errorId");
  });
});
