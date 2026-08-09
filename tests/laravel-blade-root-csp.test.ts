import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";

/**
 * Plan 06 step 78. Guards the Blade root document (csrf-token meta + CSP nonce),
 * the middleware policy per spec/19-main-worker-service/12-jwt-delivery-contract.md
 * lines 83-98, and the axios X-CSRF-TOKEN wiring in bootstrap.ts.
 */
const blade = readFileSync("backend/resources/views/app.blade.php", "utf8");
const csp = readFileSync(
  "backend/app/Http/Middleware/ContentSecurityPolicyMiddleware.php",
  "utf8",
);
const appPhp = readFileSync("backend/bootstrap/app.php", "utf8");
const bootstrap = readFileSync("backend/resources/js/bootstrap.ts", "utf8");

describe("laravel blade root: csrf meta + CSP nonce", () => {
  it("renders both meta tags in the document head", () => {
    expect(blade).toContain('<meta name="csrf-token" content="{{ csrf_token() }}">');
    expect(blade).toContain('<meta name="csp-nonce" content="{{ $laraCspNonce }}">');
  });

  it("feeds the middleware nonce into the Vite directives", () => {
    expect(blade).toContain("ContentSecurityPolicyMiddleware::ATTR");
    expect(blade).toContain("Vite::useCspNonce($laraCspNonce)");
    expect(blade).toContain("@viteReactRefresh");
    expect(blade).toContain("@inertiaHead");
  });

  it("emits the spec 19 directives with a nonce and no unsafe script sources", () => {
    for (const directive of [
      "default-src 'self'",
      "frame-ancestors 'none'",
      "base-uri 'none'",
      "object-src 'none'",
      "form-action 'self'",
    ]) {
      expect(csp).toContain(directive);
    }
    expect(csp).toContain("'nonce-{$nonce}'");
    // 'unsafe-eval' is dev-only (Vite HMR); it must sit behind the env branch.
    const unsafeEvalLine = csp
      .split("\n")
      .find((line) => line.includes("unsafe-eval"));
    expect(unsafeEvalLine).toBeDefined();
    expect(csp).toContain("$isDev = app()->environment(['local', 'testing'])");
    expect(csp).not.toContain("'unsafe-inline'\",\n            \"script-src");
  });

  it("mints the nonce before Inertia renders and skips JSON API responses", () => {
    expect(csp).toContain("$request->attributes->set(self::ATTR, $nonce)");
    expect(csp).toContain("$request->is('Api/*', 'App/*')");
    const webBlock = appPhp.slice(appPhp.indexOf("$middleware->web(append: ["));
    const cspAt = webBlock.indexOf("ContentSecurityPolicyMiddleware::class");
    const inertiaAt = webBlock.indexOf("HandleInertiaRequests::class");
    expect(cspAt).toBeGreaterThan(-1);
    expect(cspAt).toBeLessThan(inertiaAt);

  });

  it("sends X-CSRF-TOKEN from the meta tag on axios requests", () => {
    expect(bootstrap).toContain('meta[name="csrf-token"]');
    expect(bootstrap).toContain("['X-CSRF-TOKEN']");
  });
});
