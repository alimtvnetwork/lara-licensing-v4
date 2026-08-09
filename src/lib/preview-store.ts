/**
 * Preview store (Plan 16 Step 35).
 *
 * Thin typed layer over `idb-keyval` providing domain-scoped
 * persistence for preview mode. Seeds (Steps 36-38) hydrate it once
 * per session; handlers (Steps 40-50) read/write through it so
 * mutations survive reload but never leak into live mode.
 *
 * Invariants:
 * - Only used when runtime mode === "preview". Live mode MUST NOT import
 *   this file from hot paths (enforced by a linter in Step 84).
 * - Domain names are the 12 fixture module names in
 *   `PREVIEW_FIXTURE_MODULE_NAMES` so store and handlers stay aligned.
 * - `hydrateOnce(seedId, fn)` is idempotent across reloads: the seed
 *   marker key is stored in the same IDB so a hard reload sees it.
 * - All errors are logged with context and re-thrown. No silent swallow.
 */

import {
  clear as idbClear,
  createStore,
  del as idbDel,
  entries as idbEntries,
  get as idbGet,
  keys as idbKeys,
  set as idbSet,
  type UseStore,
} from "idb-keyval";
import { PREVIEW_FIXTURE_MODULE_NAMES, type PreviewFixtureModule } from "./preview-fixtures";
import { recordPreviewStoreOp } from "./preview-store-metrics";

export type PreviewDomain = (typeof PREVIEW_FIXTURE_MODULE_NAMES)[number];

const DB_NAME = "lara-preview-store";
const DB_VERSION_TAG = "v1";
const SEED_MARKER_DOMAIN = "__seed_markers__";

type AnyStore = UseStore;

const stores = new Map<string, AnyStore>();

function storeFor(domain: string): AnyStore {
  const key = `${DB_NAME}::${DB_VERSION_TAG}::${domain}`;
  let s = stores.get(key);
  if (!s) {
    s = createStore(key, "kv");
    stores.set(key, s);
  }

  return s;
}

function assertKnownDomain(domain: PreviewDomain): void {
  if (!(PREVIEW_FIXTURE_MODULE_NAMES as readonly string[]).includes(domain)) {
    throw new Error(`preview-store: unknown domain "${domain}"`);
  }
}

function logAndRethrow(op: string, ctx: Record<string, unknown>, err: unknown): never {
  console.error("preview-store:error", { op, ...ctx, error: err });
  throw err;
}

function now(): number {
  return typeof performance !== "undefined" ? performance.now() : Date.now();
}

export async function read<T>(domain: PreviewDomain, key: string): Promise<T | undefined> {
  assertKnownDomain(domain);
  const t0 = now();
  try {
    const v = (await idbGet(key, storeFor(domain))) as T | undefined;
    recordPreviewStoreOp(domain, "read", now() - t0, v === undefined ? 0 : 1);

    return v;
  } catch (err) {
    return logAndRethrow("read", { domain, key }, err);
  }
}

export async function write<T>(domain: PreviewDomain, key: string, value: T): Promise<void> {
  assertKnownDomain(domain);
  const t0 = now();
  try {
    await idbSet(key, value, storeFor(domain));
    recordPreviewStoreOp(domain, "write", now() - t0, 1);
  } catch (err) {
    logAndRethrow("write", { domain, key }, err);
  }
}

export async function list<T>(domain: PreviewDomain): Promise<Array<[string, T]>> {
  assertKnownDomain(domain);
  const t0 = now();
  try {
    const rows = (await idbEntries(storeFor(domain))) as Array<[string, T]>;
    recordPreviewStoreOp(domain, "list", now() - t0, rows.length);

    return rows;
  } catch (err) {
    return logAndRethrow("list", { domain }, err);
  }
}

export async function listKeys(domain: PreviewDomain): Promise<string[]> {
  assertKnownDomain(domain);
  const t0 = now();
  try {
    const keys = (await idbKeys(storeFor(domain))) as string[];
    recordPreviewStoreOp(domain, "listKeys", now() - t0, keys.length);

    return keys;
  } catch (err) {
    return logAndRethrow("listKeys", { domain }, err);
  }
}

export async function remove(domain: PreviewDomain, key: string): Promise<void> {
  assertKnownDomain(domain);
  const t0 = now();
  try {
    await idbDel(key, storeFor(domain));
    recordPreviewStoreOp(domain, "remove", now() - t0, 0);
  } catch (err) {
    logAndRethrow("remove", { domain, key }, err);
  }
}

export async function resetDomain(domain: PreviewDomain): Promise<void> {
  assertKnownDomain(domain);
  try {
    await idbClear(storeFor(domain));
  } catch (err) {
    logAndRethrow("resetDomain", { domain }, err);
  }
}

export async function resetAll(): Promise<void> {
  try {
    for (const domain of PREVIEW_FIXTURE_MODULE_NAMES) {
      await idbClear(storeFor(domain));
    }
    await idbClear(storeFor(SEED_MARKER_DOMAIN));
  } catch (err) {
    logAndRethrow("resetAll", {}, err);
  }
}

/**
 * Run `hydrator` exactly once per `seedId` per browser origin. The
 * marker is persisted in IDB so a page reload does not re-seed and
 * clobber user mutations. Callers wanting a fresh seed must call
 * `resetAll()` first (wired to a Runtime settings button in Step 55+).
 */
export async function hydrateOnce(
  seedId: string,
  hydrator: () => Promise<void>,
): Promise<{ Hydrated: boolean }> {
  const markers = storeFor(SEED_MARKER_DOMAIN);
  try {
    const existing = await idbGet(seedId, markers);
    if (existing) {
      return { Hydrated: false };
    }
    await hydrator();
    await idbSet(seedId, { At: new Date().toISOString() }, markers);

    return { Hydrated: true };
  } catch (err) {
    return logAndRethrow("hydrateOnce", { seedId }, err);
  }
}

export const __PREVIEW_STORE_INTERNAL__ = {
  DB_NAME,
  DB_VERSION_TAG,
  SEED_MARKER_DOMAIN,
  stores,
} as const;

// Referenced solely to keep the type export live for downstream steps.
export type { PreviewFixtureModule };
