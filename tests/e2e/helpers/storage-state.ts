import { mkdirSync } from "node:fs";
import { dirname, resolve } from "node:path";
import type { BrowserContext } from "@playwright/test";

const AUTH_DIR = resolve(process.cwd(), "tests/e2e/.auth");

/**
 * Persist a Playwright BrowserContext's storage (cookies +
 * localStorage) to disk so subsequent specs can attach it via
 * `use: { storageState: adminStorageStatePath() }` without re-logging
 * in. Empty/undefined role slugs fail loudly, no silent fallback.
 */
export function adminStorageStatePath(role: string): string {
  if (!role) {
    throw new Error("adminStorageStatePath requires a non-empty role slug");
  }
  return resolve(AUTH_DIR, `${role}.json`);
}

export async function saveStorageState(
  context: BrowserContext,
  role: string,
): Promise<string> {
  const path = adminStorageStatePath(role);
  mkdirSync(dirname(path), { recursive: true });
  await context.storageState({ path });
  return path;
}
