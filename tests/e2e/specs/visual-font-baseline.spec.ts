import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 38. Font baseline visual diff.
 *
 * Root cause locked: Ubuntu (headings) + Poppins (body) is core brand identity
 * enforced via `--font-display` and `--font-sans` in `src/styles.css` (lines
 * 73-76) and the `<link>` preconnect + Google Fonts stylesheet wired in
 * `src/routes/__root.tsx`. A silent regression, e.g. a `<link>` removed from
 * the root head, a `font-family` override in a component, or the token
 * renamed, flips `getComputedStyle` back to system-ui with zero test signal.
 * `scripts/capture-font-baseline.py` only probes landing; login and admin
 * dashboard are uncovered.
 *
 * This spec probes computed `fontFamily` for `h1` (Ubuntu) and `body`
 * (Poppins) on the three brand-critical surfaces:
 *   1. landing `/` (public marketing hero)
 *   2. login `/admin/login` (unauthenticated entry point)
 *   3. admin dashboard `/admin` (authenticated shell, requires fixture)
 *
 * A failure here means fonts silently fell back and every downstream visual
 * regression check is invalid. Assertions run after `document.fonts.ready`
 * so we test the applied stack, not the pre-load flash.
 */

type FontProbe = { h1: string | null; body: string | null };

async function probeFonts(page: import("@playwright/test").Page): Promise<FontProbe> {
  await page.evaluate(() => document.fonts.ready);
  return page.evaluate<FontProbe>(() => {
    const h1 = document.querySelector("h1");
    const body = document.body;
    return {
      h1: h1 ? getComputedStyle(h1).fontFamily : null,
      body: body ? getComputedStyle(body).fontFamily : null,
    };
  });
}

function expectUbuntuHeading(probe: FontProbe, label: string) {
  expect(probe.h1, `${label}: <h1> must resolve to Ubuntu`).not.toBeNull();
  expect(probe.h1 ?? "", `${label}: <h1> fontFamily "${probe.h1}" missing Ubuntu`).toMatch(
    /Ubuntu/i,
  );
}

function expectPoppinsBody(probe: FontProbe, label: string) {
  expect(probe.body, `${label}: <body> must resolve to Poppins`).not.toBeNull();
  expect(
    probe.body ?? "",
    `${label}: <body> fontFamily "${probe.body}" missing Poppins`,
  ).toMatch(/Poppins/i);
}

test.describe("Font baseline (Ubuntu headings + Poppins body)", () => {
  test("landing `/` heading is Ubuntu and body is Poppins", async ({ page }) => {
    await page.goto("/", { waitUntil: "networkidle" });
    const probe = await probeFonts(page);
    expectUbuntuHeading(probe, "landing");
    expectPoppinsBody(probe, "landing");
  });

  test("admin login `/admin/login` heading is Ubuntu and body is Poppins", async ({
    page,
  }) => {
    await page.goto("/admin/login", { waitUntil: "networkidle" });
    const probe = await probeFonts(page);
    expectUbuntuHeading(probe, "admin-login");
    expectPoppinsBody(probe, "admin-login");
  });

  test("authenticated admin `/admin` heading is Ubuntu and body is Poppins", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();
    await expect(page).toHaveURL(/\/admin(\/|$)/);
    // Wait for the PageHeader h1 ("Overview") to mount before probing.
    await expect(page.getByRole("heading", { name: "Overview", level: 1 })).toBeVisible();
    const probe = await probeFonts(page);
    expectUbuntuHeading(probe, "admin-dashboard");
    expectPoppinsBody(probe, "admin-dashboard");
  });
});
