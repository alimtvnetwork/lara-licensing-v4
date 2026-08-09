<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plan 06 step 29. Deterministic strong ETag calculator for domain
 * entities.
 *
 * The middleware `EtagMiddleware` computes response ETags by hashing
 * canonicalised JSON. Controllers that enforce `If-Match` on PATCH
 * need to recompute the SAME hash from the CURRENT server state,
 * against a canonical "GET-equivalent" projection, so a cached header
 * from a prior read can be compared to the row as it exists now.
 *
 * Contract: the caller MUST pass the projection that would appear in
 * the `Results[0]` slot for the GET endpoint of that resource, using
 * the same PascalCase keys and same field order. This guarantees the
 * hash matches what `EtagMiddleware::attachEtag` produced on that GET.
 *
 * The hash is lowercase SHA-256 hex of the canonicalised single-item
 * envelope, matching `spec/21-app/11-api-contracts/09-concurrency-control.md`
 * §Server algorithm step 3.
 */
final class EntityHasher
{
    /**
     * @param array<string, mixed> $projection Single-resource GET body
     *                                          (Results[0] shape).
     */
    public static function hashSingleResource(array $projection, string $requestId): string
    {
        // Rebuild the exact JSON shape a GET would return, then hash it
        // via the same canonicaliser the middleware uses. RequestId and
        // RequestedAt are stripped inside the canonicaliser (they are
        // volatile per-request fields) so any RequestId works here.
        $response = ApiEnvelope::success([$projection], $requestId);
        $canonical = IdempotencyCanonicalizer::canonicalize((string) $response->getContent());

        return hash('sha256', $canonical);
    }

    public static function ifMatchMatches(string $ifMatchHeader, string $currentHex): bool
    {
        $trimmed = trim($ifMatchHeader);
        // Strip surrounding double quotes per RFC 9110 strong ETag form.
        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && substr($trimmed, -1) === '"') {
            $trimmed = substr($trimmed, 1, -1);
        }

        return hash_equals($currentHex, strtolower($trimmed));
    }
}
