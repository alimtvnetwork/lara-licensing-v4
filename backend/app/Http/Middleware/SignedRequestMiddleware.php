<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan 06 step 28. Portal request-signature gate.
 *
 * Portal endpoints (`/Api/Portal/*`) are unauthenticated at the session
 * level (no bearer token, no cookie) but MUST be signed by a shared
 * HMAC-SHA256 secret provisioned per KeyId. This middleware verifies
 * the four canonical signature headers before the handler runs.
 *
 * Canonical string to sign (LF separated, no trailing newline):
 *
 *   v1\n{METHOD}\n{PATH}\n{Timestamp}\n{Nonce}\n{BodySha256Hex}
 *
 * Where:
 *  - METHOD          = uppercase HTTP verb.
 *  - PATH            = request path exactly as received, lowercase, no
 *                      query string, no leading slash.
 *  - Timestamp       = decimal unix seconds (10 digits, integer).
 *  - Nonce           = 16..64 hex chars, unique per KeyId within replay
 *                      window (spec 21 §Portal signing).
 *  - BodySha256Hex   = lowercase hex sha256 of raw request body bytes
 *                      (empty body hashes as `e3b0...` per RFC 6234).
 *
 * Failure mapping (closed set, tied to spec/21-app/12-error-taxonomy.md):
 *   - Missing signature header set     -> AuthUnauthorized (401)
 *   - Header format invalid            -> ValidationFailed (400)
 *   - Timestamp skew > 300s past or 60s future -> AbuseBlocked (403)
 *   - Unknown KeyId                    -> AuthInvalidCredentials (401)
 *   - HMAC mismatch                    -> AuthInvalidCredentials (401)
 *   - Nonce replay in window           -> AbuseBlocked (403)
 *
 * Signing keys live in `config('lara.portal_signing_keys')` as a
 * `KeyId => Base64Secret` map. Production wiring reads
 * `PORTAL_SIGNING_KEYS_JSON` from the environment (Plan 06 step 45+).
 */
final class SignedRequestMiddleware
{
    private const HEADER_KEY_ID = 'X-Lara-KeyId';
    private const HEADER_TIMESTAMP = 'X-Lara-Timestamp';
    private const HEADER_NONCE = 'X-Lara-Nonce';
    private const HEADER_SIGNATURE = 'X-Lara-Signature';
    private const SIGNATURE_PREFIX = 'v1=';
    private const CANONICAL_VERSION = 'v1';
    private const KEY_ID_REGEX = '/^[A-Za-z0-9._-]{3,64}$/';
    private const NONCE_REGEX = '/^[a-f0-9]{16,64}$/';
    private const TIMESTAMP_REGEX = '/^\d{10}$/';
    private const SIGNATURE_HEX_REGEX = '/^[a-f0-9]{64}$/';
    private const MAX_PAST_SKEW_SECONDS = 300;
    private const MAX_FUTURE_SKEW_SECONDS = 60;
    private const NONCE_REPLAY_TTL_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        [$keyId, $timestamp, $nonce, $signature] = $this->readHeaders($request);
        $this->assertTimestampFresh((int) $timestamp);
        $secret = $this->resolveSecret($keyId);
        $this->assertSignatureMatches($request, $keyId, $timestamp, $nonce, $signature, $secret);
        $this->assertNonceFresh($keyId, $nonce);
        $request->attributes->set('lara.signature.key_id', $keyId);

        return $next($request);
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function readHeaders(Request $request): array
    {
        $keyId = trim((string) $request->headers->get(self::HEADER_KEY_ID, ''));
        $timestamp = trim((string) $request->headers->get(self::HEADER_TIMESTAMP, ''));
        $nonce = strtolower(trim((string) $request->headers->get(self::HEADER_NONCE, '')));
        $signature = trim((string) $request->headers->get(self::HEADER_SIGNATURE, ''));
        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw AuthException::unauthorized(
                'Portal request missing required signature headers.',
                [['Field' => 'X-Lara-Signature', 'Rule' => 'Missing']],
            );
        }
        $this->assertHeaderFormats($keyId, $timestamp, $nonce, $signature);

        return [$keyId, $timestamp, $nonce, substr($signature, strlen(self::SIGNATURE_PREFIX))];
    }

    private function assertHeaderFormats(string $keyId, string $timestamp, string $nonce, string $signature): void
    {
        if (preg_match(self::KEY_ID_REGEX, $keyId) !== 1) {
            $this->reject('X-Lara-KeyId', 'FormatInvalid');
        }
        if (preg_match(self::TIMESTAMP_REGEX, $timestamp) !== 1) {
            $this->reject('X-Lara-Timestamp', 'FormatInvalid');
        }
        if (preg_match(self::NONCE_REGEX, $nonce) !== 1) {
            $this->reject('X-Lara-Nonce', 'FormatInvalid');
        }
        if (str_starts_with($signature, self::SIGNATURE_PREFIX) === false) {
            $this->reject('X-Lara-Signature', 'FormatInvalid');
        }
        $hex = substr($signature, strlen(self::SIGNATURE_PREFIX));
        if (preg_match(self::SIGNATURE_HEX_REGEX, $hex) !== 1) {
            $this->reject('X-Lara-Signature', 'FormatInvalid');
        }
    }

    private function reject(string $field, string $rule): never
    {
        throw ValidationException::validationFailed(
            'Portal signature header is malformed.',
            [['Field' => $field, 'Rule' => $rule]],
        );
    }

    private function assertTimestampFresh(int $timestamp): void
    {
        $now = time();
        $delta = $now - $timestamp;
        if ($delta > self::MAX_PAST_SKEW_SECONDS || $delta < -self::MAX_FUTURE_SKEW_SECONDS) {
            throw AuthException::custom('AbuseBlocked',
                'Portal signature timestamp is outside the accepted window.',
                [['Field' => 'X-Lara-Timestamp', 'Rule' => 'Skew']],
            )
        }
    }

    private function resolveSecret(string $keyId): string
    {
        $keys = (array) config('lara.portal_signing_keys', []);
        $secret = $keys[$keyId] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw AuthException::invalidCredentials(
                'Portal signing key is not recognised.',
                [['Field' => 'X-Lara-KeyId', 'Rule' => 'UnknownKeyId']],
            );
        }
        $decoded = base64_decode($secret, true);
        if ($decoded === false || $decoded === '') {
            Log::error('portal.signing_key_invalid', ['KeyId' => $keyId]);
            throw AuthException::invalidCredentials(
                'Portal signing key is not usable.',
                [['Field' => 'X-Lara-KeyId', 'Rule' => 'UnknownKeyId']],
            );
        }

        return $decoded;
    }

    private function assertSignatureMatches(
        Request $request,
        string $keyId,
        string $timestamp,
        string $nonce,
        string $providedHex,
        string $secret,
    ): void {
        $canonical = $this->canonicalString($request, $timestamp, $nonce);
        $expectedHex = hash_hmac('sha256', $canonical, $secret);
        if (hash_equals($expectedHex, $providedHex) === false) {
            Log::warning('portal.signature_mismatch', [
                'KeyId' => $keyId,
                'Method' => $request->method(),
                'Path' => strtolower(ltrim($request->path(), '/')),
            ]);
            throw AuthException::invalidCredentials(
                'Portal request signature is invalid.',
                [['Field' => 'X-Lara-Signature', 'Rule' => 'BadSignature']],
            );
        }
    }

    private function canonicalString(Request $request, string $timestamp, string $nonce): string
    {
        $method = strtoupper($request->method());
        $path = strtolower(ltrim($request->path(), '/'));
        $bodyHash = hash('sha256', (string) $request->getContent());

        return implode("\n", [self::CANONICAL_VERSION, $method, $path, $timestamp, $nonce, $bodyHash]);
    }

    private function assertNonceFresh(string $keyId, string $nonce): void
    {
        $cacheKey = 'portal.nonce.' . $keyId . '.' . $nonce;
        if (Cache::add($cacheKey, 1, self::NONCE_REPLAY_TTL_SECONDS) === false) {
            throw AuthException::custom('AbuseBlocked',
                'Portal signature nonce has already been used.',
                [['Field' => 'X-Lara-Nonce', 'Rule' => 'NonceReplay']],
            )
        }
    }
}
