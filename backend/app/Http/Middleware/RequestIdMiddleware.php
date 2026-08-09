<?php

namespace App\Http\Middleware;

use App\Exceptions\ValidationException;


use App\Exceptions\LaraException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Request-Id ingress + echo per spec/21-app/20-observability.md v1.0.0.
 *
 * Behavior:
 *  - Accepts inbound header, validates against ^[A-Za-z0-9-]{16,64}$.
 *  - Missing header on strict-list endpoints (/api/admin/*, /api/verify/*,
 *    /api/app/updateasset/*) -> throws LaraException('RequestIdMissing').
 *  - Missing header elsewhere -> server mints a UUIDv4 fallback.
 *  - Binds the id into the Log context so every log line for the request
 *    carries `RequestId` automatically (AC-ERR-004).
 *  - Echoes the id on the response header AND stashes into the request
 *    attribute bag so ApiEnvelope readers can pull the same value.
 *
 * ACs locked: AC-OBS-* RequestId presence, AC-ERR-004 log correlation.
 */
final class RequestIdMiddleware
{
    private const REQUEST_ID_REGEX = '/^[A-Za-z0-9-]{16,64}$/';
    private const HEADER = 'X-Request-Id';
    private const ATTR = 'lara.request_id';

    /** Strict-list route prefixes that REQUIRE a client-minted header. */
    private const STRICT_PREFIXES = [
        'api/admin/',
        'api/verify/',
        'api/app/updateasset/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);
        $request->attributes->set(self::ATTR, $requestId);
        Log::withContext(['RequestId' => $requestId]);
        Log::info('http.request', [
            'Method' => $request->method(),
            'Path' => '/' . ltrim($request->path(), '/'),
        ]);
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $raw = (string) $request->headers->get(self::HEADER, '');
        if ($raw !== '' && preg_match(self::REQUEST_ID_REGEX, $raw) === 1) {
            return $raw;
        }
        if ($this->isStrictPath($request)) {
            // Mint a fallback and bind it to attribute + Log context BEFORE
            // throwing so the failure envelope, response header, and
            // lara-diag file all correlate on the same RequestId.
            // AC-ERR-004, spec/03-error-manage/02-error-architecture.
            $fallback = $this->mintUuidV4();
            $request->attributes->set(self::ATTR, $fallback);
            Log::withContext(['RequestId' => $fallback]);
            throw ValidationException::custom('RequestIdMissing',
                'X-Request-Id header is required on this endpoint.',
                [['Field' => 'X-Request-Id', 'Rule' => 'Required']],
            )
        }

        return $this->mintUuidV4();
    }

    private function isStrictPath(Request $request): bool
    {
        $path = strtolower(ltrim($request->path(), '/'));
        foreach (self::STRICT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function mintUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
