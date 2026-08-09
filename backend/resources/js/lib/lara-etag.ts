// Plan 06 step 75. Response-side ETag capture cache.
//
// Why this exists: the Inertia route in `routes/web.php` (admin.licenses.show)
// calls `Admin\LicenseController::show()` as a plain PHP method, so the
// response never traverses `EtagMiddleware::attachEtag()` (that middleware only
// runs on the `api` stack). `$showResponse->headers->get('ETag')` is therefore
// always null, the `etag` page prop is null, and `LicenseDetailActions` disables
// Save/Revoke forever. Capturing the header from the real
// `GET /Api/Admin/Licenses/{Key}` response on the client is the only source of a
// truthful strong validator, and it keeps the second consecutive edit off the
// PreconditionFailed path (spec 11-api-contracts/09-concurrency-control.md).
//
// The cache is intentionally dumb: exact resource key -> last seen strong ETag.
// It never invents a validator; an absent entry stays absent so the caller
// surfaces "reload to fetch the current concurrency token" instead of guessing.

const cache = new Map<string, string>();

/**
 * Resource identity for the cache: pathname only, lowercased, query string and
 * trailing slash removed. `?ResellerSlug=` selects a shard but does not change
 * which row the validator belongs to, and PATCH/DELETE hit the same pathname as
 * the GET that produced the ETag.
 */
export function etagKey(path: string): string {
  const withoutQuery = path.split("?")[0]!.split("#")[0]!;
  const trimmed = withoutQuery.replace(/\/+$/, "");
  return (trimmed === "" ? "/" : trimmed).toLowerCase();
}

/** RFC 9110 strong validator only: `*`, `W/"..."` and empty values are dropped. */
function isStrongEtag(value: string): boolean {
  if (value === "" || value === "*") return false;
  return !value.startsWith("W/");
}

/**
 * Store the `ETag` of a response. `headers` accepts either a fetch `Headers`
 * instance or the plain object axios exposes on `response.headers`.
 */
export function captureEtag(
  path: string,
  headers: Headers | Record<string, unknown> | null | undefined,
): string | null {
  if (!headers) return null;
  const raw =
    typeof (headers as Headers).get === "function"
      ? (headers as Headers).get("ETag")
      : ((headers as Record<string, unknown>)["etag"] ??
        (headers as Record<string, unknown>)["ETag"]);
  if (typeof raw !== "string") return null;
  const value = raw.trim();
  if (!isStrongEtag(value)) return null;
  cache.set(etagKey(path), value);
  return value;
}

/** Freshest strong ETag seen for this resource, or null when never captured. */
export function readEtag(path: string): string | null {
  return cache.get(etagKey(path)) ?? null;
}

/** Test seam and logout hook; never called on the happy path. */
export function clearEtags(): void {
  cache.clear();
}
