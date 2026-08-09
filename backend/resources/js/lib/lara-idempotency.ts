/**
 * Plan 06 step 76. Per-attempt `Idempotency-Key` minting with retry reuse.
 *
 * Two concrete defects this closes:
 *
 *  1. `resources/js/bootstrap.ts` injected only `X-Request-Id`, so any mutating
 *     Inertia visit (`router.post/patch/delete`, which shares the same axios
 *     singleton) reached a path under
 *     `IdempotencyKeyMiddleware::REQUIRED_PREFIXES` with no key and was rejected
 *     with `IdempotencyKeyRequired` (400) before the controller ever ran.
 *  2. `lib/lara-api.ts` minted a brand-new key inside every `laraRequest()`
 *     call, so an operator retry after a timeout (request committed, response
 *     lost) presented an unseen key and the middleware EXECUTED the mutation a
 *     second time instead of replaying the stored snapshot - double-charging
 *     reseller quota on `api/reseller/quotarequests` and `api/admin/licenses`.
 *
 * Contract: one logical attempt = one key. The attempt is identified by
 * method + resource path + request body, so a retry of the identical mutation
 * reuses the key (middleware replays byte-for-byte per AC-IDL-004), while an
 * edited body is a NEW attempt and gets a NEW key. The key is released once the
 * server confirms success, so a deliberate repeat of the same mutation later
 * (issue two identical serials) is not silently swallowed as a replay.
 */

import { mintRequestId } from "./lara-request-id";

/**
 * `Reseller\QuotaRequestController::requireIdempotencyKey()` rejects any key
 * whose length is not exactly 32, and
 * `IdempotencyKeyMiddleware::KEY_REGEX` accepts 16..128 printable ASCII, so
 * 32 hex characters is the only shape that satisfies both.
 */
export function mintIdempotencyKey(): string {
  return mintRequestId().replace(/-/g, "").padEnd(32, "0").slice(0, 32);
}

/** Stable JSON with sorted object keys so key order cannot fork an attempt. */
function canonicalize(value: unknown): string {
  if (value === undefined) return "";
  if (value === null || typeof value !== "object") return JSON.stringify(value) ?? "";
  if (Array.isArray(value)) return `[${value.map(canonicalize).join(",")}]`;
  const entries = Object.entries(value as Record<string, unknown>)
    .filter(([, v]) => v !== undefined)
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
    .map(([k, v]) => `${JSON.stringify(k)}:${canonicalize(v)}`);
  return `{${entries.join(",")}}`;
}

/** Attempt identity. Query string is kept: `?ResellerSlug=` picks the shard. */
export function attemptFingerprint(method: string, path: string, body: unknown): string {
  const normalizedBody = typeof body === "string" ? body : canonicalize(body);
  return `${method.toUpperCase()} ${path}\n${normalizedBody}`;
}

const attempts = new Map<string, string>();

/** Key for this attempt: existing one on a retry, fresh one otherwise. */
export function idempotencyKeyFor(method: string, path: string, body: unknown): string {
  const fingerprint = attemptFingerprint(method, path, body);
  const existing = attempts.get(fingerprint);
  if (existing !== undefined) return existing;
  const minted = mintIdempotencyKey();
  attempts.set(fingerprint, minted);
  return minted;
}

/**
 * Drop the attempt after the server confirmed it. Called on success only:
 * releasing on failure would defeat the whole point, because the failure may be
 * a lost response for a mutation that actually committed.
 */
export function releaseAttempt(method: string, path: string, body: unknown): void {
  attempts.delete(attemptFingerprint(method, path, body));
}

/** Test seam. */
export function clearAttempts(): void {
  attempts.clear();
}
