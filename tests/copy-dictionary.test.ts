import { describe, it, expect } from "vitest";
import { copy, pluralize, pluralCount, formatRateLimited } from "@/lib/copy";

// spec/24-app-ui-design-system/56-copy-dictionary.md §3, §5, §7, §8, §13.

const BANNED_TOKENS = [
  "Oops",
  "Uh oh",
  "Whoops",
  "Sorry",
  "We are sorry",
  "Click here",
  "Awesome",
  "Great job",
  "Success!",
];

function everyString(): string[] {
  const out: string[] = [];
  const walk = (v: unknown): void => {
    if (typeof v === "string") out.push(v);
    else if (v && typeof v === "object")
      for (const k of Object.keys(v)) walk((v as Record<string, unknown>)[k]);
  };
  walk(copy);
  return out;
}

describe("copy dictionary", () => {
  it("bans em dashes and reassurance tokens in every string", () => {
    const strings = everyString();
    for (const s of strings) {
      expect(s.includes("\u2014"), `em dash in ${s}`).toBe(false);
      for (const t of BANNED_TOKENS)
        expect(s.toLowerCase().includes(t.toLowerCase()), `${t} in ${s}`).toBe(false);
    }
  });

  it("keeps error messages within 120 chars", () => {
    for (const [code, msg] of Object.entries(copy.errors))
      expect(msg.length, `${code} exceeds 120 chars`).toBeLessThanOrEqual(120);
  });

  it("pins §3 canonical button verbs", () => {
    expect(copy.buttons.save).toBe("Save");
    expect(copy.buttons.retry).toBe("Retry");
    expect(copy.buttons.signIn).toBe("Sign in");
    expect(copy.buttons.revokeLicense).toBe("Revoke license");
  });

  it("pins §5 error message strings byte-for-byte", () => {
    expect(copy.errors.LicenseNotFound).toBe("License not found.");
    expect(copy.errors.PreconditionFailed).toContain("Refresh and try again");
    expect(copy.errors.Unauthorized).toContain("Sign in again");
  });

  it("pins §7 destructive phrase values", () => {
    expect(copy.phrases.revokeLicense).toBe("REVOKE");
    expect(copy.phrases.deleteUser).toBe("DELETE");
    expect(copy.phrases.denyQuota).toBe("DENY");
    expect(copy.phrases.signOutEverywhere).toBe("SIGN OUT");
  });

  it("pluralizes §8 nouns using zero=plural rule", () => {
    expect(pluralize(1, "license")).toBe("license");
    expect(pluralize(0, "license")).toBe("licenses");
    expect(pluralize(2, "license")).toBe("licenses");
    expect(pluralCount(0, "user")).toBe("0 users");
    expect(pluralCount(1, "user")).toBe("1 user");
  });

  it("formats RateLimited RetryAfterSec", () => {
    expect(formatRateLimited(42)).toContain("42");
    expect(formatRateLimited(42)).not.toContain("{RetryAfterSec}");
  });
});
