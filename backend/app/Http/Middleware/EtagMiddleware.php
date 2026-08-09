<?php

namespace App\Http\Middleware;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Support\IdempotencyCanonicalizer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optimistic concurrency (ETag + If-Match) per
 * spec/21-app/11-api-contracts/09-concurrency-control.md v1.0.0.
 *
 * This middleware does two jobs:
 *  1. On GET JSON responses, computes a strong ETag as lowercase SHA-256 hex
 *     over the canonical response body (PascalCase keys sorted, no whitespace)
 *     and writes it into the `ETag` response header, quoted per RFC 9110.
 *  2. On in-scope mutation routes (License PATCH/DELETE, License feature
 *     PUT/DELETE), enforces `If-Match`:
 *       - missing  -> LaraException('PreconditionRequired', 428)
 *       - wildcard -> LaraException('ValidationFailed', 400) with WildcardForbidden
 *       - weak `W/"..."` -> LaraException('ValidationFailed', 400) with WeakForbidden
 *     The actual "did the row change?" check runs inside the domain
 *     transaction per spec §Server algorithm step 3 (not middleware).
 *     Middleware stores the parsed values in `lara.if_match` for the handler.
 *
 * ACs locked: AC-CONCUR-001 (strong ETag on GET), AC-CONCUR-002 (428 on missing),
 * AC-CONCUR-003 (wildcard rejected), AC-CONCUR-004 (weak rejected).
 */
final class EtagMiddleware
{
    private const IF_MATCH_SCOPES = [
        'PATCH:api/licenses/',
        'DELETE:api/licenses/',
        'PUT:api/licenses/',
        'PATCH:api/admin/licenses/',
        'DELETE:api/admin/licenses/',
        'PUT:api/admin/licenses/',
        'PATCH:api/admin/resellers/',
        'PATCH:api/admin/users/',
        'PATCH:api/reseller/licenses/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $this->enforceIfMatchOnMutation($request);
        /** @var Response $response */
        $response = $next($request);
        if ($request->isMethod('GET') && $response instanceof JsonResponse) {
            $this->attachEtag($response);
        }

        return $response;
    }

    private function enforceIfMatchOnMutation(Request $request): void
    {
        if (!$this->isInScope($request)) {
            return;
        }
        $header = trim((string) $request->headers->get('If-Match', ''));
        if ($header === '') {
            throw ValidationException::custom('PreconditionRequired',
                'If-Match header is required on this endpoint.',
                [['Field' => 'If-Match', 'Rule' => 'Missing']],
            )
        }
        $this->rejectWildcardOrWeak($header);
        $request->attributes->set('lara.if_match', $header);
    }

    private function rejectWildcardOrWeak(string $header): void
    {
        if ($header === '*') {
            throw ValidationException::validationFailed(
                'If-Match wildcard is forbidden on License routes.',
                [['Field' => 'If-Match', 'Rule' => 'WildcardForbidden']],
            );
        }
        if (str_starts_with($header, 'W/')) {
            throw ValidationException::validationFailed(
                'Weak validators are not accepted on License routes.',
                [['Field' => 'If-Match', 'Rule' => 'WeakForbidden']],
            );
        }
    }

    private function isInScope(Request $request): bool
    {
        $prefix = strtoupper($request->method()) . ':' . strtolower(ltrim($request->path(), '/'));
        foreach (self::IF_MATCH_SCOPES as $scope) {
            if (str_starts_with($prefix, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function attachEtag(JsonResponse $response): void
    {
        $canonical = IdempotencyCanonicalizer::canonicalize((string) $response->getContent());
        $hex = hash('sha256', $canonical);
        $response->headers->set('ETag', '"' . $hex . '"');
    }
}
