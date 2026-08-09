/**
 * Plan 06 step 74. Single source of truth for the client-minted correlation id.
 *
 * `app/Http/Middleware/RequestIdMiddleware::REQUEST_ID_REGEX` accepts only
 * `^[A-Za-z0-9-]{16,64}$` and throws `RequestIdMissing` for anything under the
 * strict prefixes `api/admin/`, `api/verify/`, `api/app/updateasset/` when the
 * header is absent or malformed, so the format is a contract, not a detail.
 * A uuid v4 (36 chars, hyphen-separated hex) satisfies the regex exactly.
 */
export function mintRequestId(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  // Deterministic-length fallback for environments without randomUUID: 32 hex
  // chars + 4 hyphens keeps the value inside the 16..64 window.
  const bytes = new Uint8Array(16);
  if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
    crypto.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
  }
  bytes[6] = (bytes[6]! & 0x0f) | 0x40;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Verbs that mutate state and therefore MUST carry a client correlation id. */
export const MUTATING_METHODS = ["POST", "PUT", "PATCH", "DELETE"] as const;

export function isMutatingMethod(method: string | undefined): boolean {
  if (!method) return false;
  return (MUTATING_METHODS as readonly string[]).includes(method.toUpperCase());
}
