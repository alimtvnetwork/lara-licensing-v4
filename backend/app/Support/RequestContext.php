<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Plan 06 (spec 21 §Observability, spec 47 §5). Canonical extraction of
 * per-request context that every audit row and structured log line MUST
 * include: RequestId (X-Request-Id), Method, Path, IpAddress, UserAgent,
 * Reseller identifiers (set by ShardBindingMiddleware), and Portal
 * KeyId (set by SignedRequestMiddleware).
 *
 * Root cause this class exists:
 *   AuditEnrichment previously carried only impersonation lineage, so
 *   audit rows and app.log entries diverged on shard/reseller context.
 *   Centralising the extraction keeps both writers in lock-step.
 *
 * Pure function, never throws. Missing attributes are omitted (not null)
 * so downstream log aggregators can rely on presence-implies-truth.
 */
final class RequestContext
{
    public const ATTR_REQUEST_ID = 'lara.request_id';
    public const ATTR_RESELLER_ID = 'ResellerId';
    public const ATTR_RESELLER_SLUG = 'ResellerSlug';
    public const ATTR_PORTAL_KEY_ID = 'lara.signature.key_id';

    /**
     * @return array<string, mixed>
     */
    public static function extract(Request $request): array
    {
        $attrs = $request->attributes;
        $ctx = [
            'RequestId' => (string) ($attrs->get(self::ATTR_REQUEST_ID) ?? $request->headers->get('X-Request-Id', '')),
            'Method' => $request->method(),
            'Path' => $request->path(),
            'IpAddress' => (string) ($request->ip() ?? ''),
            'UserAgent' => (string) $request->userAgent(),
        ];

        return array_merge($ctx, self::optional($attrs->all()));
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private static function optional(array $attrs): array
    {
        $out = [];
        if (isset($attrs[self::ATTR_RESELLER_ID])) {
            $out['ResellerId'] = (int) $attrs[self::ATTR_RESELLER_ID];
        }
        if (isset($attrs[self::ATTR_RESELLER_SLUG])) {
            $out['ResellerSlug'] = (string) $attrs[self::ATTR_RESELLER_SLUG];
        }
        if (isset($attrs[self::ATTR_PORTAL_KEY_ID])) {
            $out['PortalKeyId'] = (string) $attrs[self::ATTR_PORTAL_KEY_ID];
        }

        return $out;
    }
}
