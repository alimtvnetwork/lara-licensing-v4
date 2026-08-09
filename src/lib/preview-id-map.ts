/**
 * Preview id-map (Plan 17 Step 4 foundation).
 *
 * Bidirectional, deterministic mapping between preview ULIDs (modern
 * schema) and positive integer ids (legacy `lara-*.ts` resource libs).
 * Backed by its own `idb-keyval` store so it survives reloads and is
 * decoupled from the domain stores in `preview-store.ts`.
 *
 * Root cause this exists to solve: many legacy resource query fns
 * (`licenseQueryOptions`, `resellerQuotasQueryOptions`, quota-request
 * listings, etc.) target endpoints keyed by positive-int ids, while
 * every preview handler emits ULID-shaped ids. Bridging them without
 * a stable id-map either (a) fabricates ids per render and breaks
 * navigation, or (b) loses reversibility. This module gives the next
 * bridge steps a single seam to translate in both directions.
 *
 * Invariants:
 * - Only used when runtime mode === "preview" (callers gate).
 * - `assignNumeric` is idempotent per (domain, ulid). Concurrent calls
 *   for the same key resolve to the same integer because the counter
 *   advance is sequenced through a per-domain promise chain.
 * - Ids are positive integers starting at 1 per domain.
 * - Every error is logged with context and re-thrown; nothing is
 *   swallowed (matches error-contract axis).
 */

import {
  createStore,
  get as idbGet,
  set as idbSet,
  clear as idbClear,
  type UseStore,
} from "idb-keyval";

const DB_PREFIX = "lara-preview-id-map::v1";
const COUNTER_KEY = "__counter__";
const UL_PREFIX = "u:"; // ulid -> numeric
const NU_PREFIX = "n:"; // numeric -> ulid

const stores = new Map<string, UseStore>();

function storeFor(domain: string): UseStore {
  const key = `${DB_PREFIX}::${domain}`;
  let s = stores.get(key);
  if (!s) {
    s = createStore(key, "kv");
    stores.set(key, s);
  }

  return s;
}

function fail(op: string, ctx: Record<string, unknown>, err: unknown): never {
  console.error("preview-id-map:error", { op, ...ctx, error: err });
  throw err;
}

const chains = new Map<string, Promise<unknown>>();

function sequence<T>(domain: string, fn: () => Promise<T>): Promise<T> {
  const prev = chains.get(domain) ?? Promise.resolve();
  const next = prev.then(fn, fn);
  chains.set(
    domain,
    next.catch(() => undefined),
  );

  return next;
}

async function readNumericForUlid(domain: string, ulid: string): Promise<number | undefined> {
  const raw = await idbGet(`${UL_PREFIX}${ulid}`, storeFor(domain));

  return typeof raw === "number" && raw > 0 ? raw : undefined;
}

async function readUlidForNumeric(domain: string, num: number): Promise<string | undefined> {
  const raw = await idbGet(`${NU_PREFIX}${num}`, storeFor(domain));

  return typeof raw === "string" && raw.length > 0 ? raw : undefined;
}

async function nextCounter(domain: string): Promise<number> {
  const s = storeFor(domain);
  const current = ((await idbGet(COUNTER_KEY, s)) as number | undefined) ?? 0;
  const next = current + 1;
  await idbSet(COUNTER_KEY, next, s);

  return next;
}

async function assignInner(domain: string, ulid: string): Promise<number> {
  const existing = await readNumericForUlid(domain, ulid);
  if (existing !== undefined) return existing;
  const s = storeFor(domain);
  const assigned = await nextCounter(domain);
  await idbSet(`${UL_PREFIX}${ulid}`, assigned, s);
  await idbSet(`${NU_PREFIX}${assigned}`, ulid, s);
  console.info("preview-id-map:assign", { Domain: domain, Ulid: ulid, Numeric: assigned });

  return assigned;
}

/** Idempotent per (domain, ulid). Concurrent calls resolve to the same id. */
export function assignNumeric(domain: string, ulid: string): Promise<number> {
  if (!ulid) return Promise.reject(new Error("preview-id-map: empty ulid"));

  return sequence(domain, () => assignInner(domain, ulid)).catch((err) =>
    fail("assignNumeric", { domain, ulid }, err),
  );
}

/** Read-only lookup. Returns undefined when not yet assigned. */
export async function numericFor(domain: string, ulid: string): Promise<number | undefined> {
  try {
    return await readNumericForUlid(domain, ulid);
  } catch (err) {
    return fail("numericFor", { domain, ulid }, err);
  }
}

/** Reverse lookup: numeric id -> ulid, or undefined. */
export async function ulidFor(domain: string, num: number): Promise<string | undefined> {
  if (!Number.isInteger(num) || num <= 0) return undefined;
  try {
    return await readUlidForNumeric(domain, num);
  } catch (err) {
    return fail("ulidFor", { domain, num }, err);
  }
}

/** Bulk-prime the map preserving input order. Used by seeders. */
export async function primeIdMap(
  domain: string,
  ulids: readonly string[],
): Promise<Array<{ Ulid: string; Numeric: number }>> {
  const out: Array<{ Ulid: string; Numeric: number }> = [];
  for (const u of ulids) {
    const n = await assignNumeric(domain, u);
    out.push({ Ulid: u, Numeric: n });
  }

  return out;
}

/** Drop the map for a single domain. Test + reset-hook use only. */
export async function resetIdMap(domain: string): Promise<void> {
  try {
    await idbClear(storeFor(domain));
  } catch (err) {
    fail("resetIdMap", { domain }, err);
  }
}

export const __PREVIEW_ID_MAP_INTERNAL__ = {
  DB_PREFIX,
  COUNTER_KEY,
  UL_PREFIX,
  NU_PREFIX,
  stores,
} as const;
