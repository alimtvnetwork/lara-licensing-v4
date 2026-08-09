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
use App\Support\IdempotencyCanonicalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency-Key middleware per spec/21-app/29-idempotency-lifecycle.md v1.0.0
 * and spec/21-app/11-api-contracts/03-idempotency.md.
 *
 * v2 (Plan 06 step 15): DB-backed `IdempotencyRecords` on the Root
 * connection with `SELECT ... FOR UPDATE` acting as the advisory lock
 * across every worker in the fleet. Replaces the v1 cache-only path,
 * which could not survive restarts nor serialise concurrent writers on
 * two nodes hitting the same key.
 *
 * Only mutating verbs (POST/PUT/PATCH/DELETE) are considered. Required-scope
 * routes without a valid key -> IdempotencyKeyRequired (400). Reused key
 * with a different body hash -> IdempotencyConflict (409). Match hit ->
 * replay stored snapshot byte-for-byte.
 *
 * ACs locked: AC-IDL-001 (decision tree), AC-IDL-004 (byte-identical replay),
 * AC-ERR-008 (retry class NoRetry mapping via LaraException).
 */
final class IdempotencyKeyMiddleware
{
    private const HEADER = 'Idempotency-Key';
    private const KEY_REGEX = '/^[\x20-\x7e]{16,128}$/';
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const TABLE = 'IdempotencyRecords';
    private const CONNECTION = 'root';
    private const DEFAULT_TTL_SECONDS = 86400;
    private const REQUIRED_PREFIXES = [
        'api/admin/appupdates',
        'api/admin/licenses',
        'api/admin/serials',
        'api/admin/resellers',
        'api/admin/users',
        'api/admin/impersonation',
        'api/admin/quotarequests',
        'api/reseller/licenses',
        'api/reseller/quotarequests',
        'api/portal/serials',
        'api/admin/backup/exports',
        'api/admin/backup/imports',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->method(), self::MUTATING, true)) {
            return $next($request);
        }
        $key = $this->readKey($request);
        if ($key === null) {
            return $next($request);
        }

        return $this->processWithKey($request, $next, $key);
    }

    private function readKey(Request $request): ?string
    {
        $raw = trim((string) $request->headers->get(self::HEADER, ''));
        if ($raw === '') {
            if ($this->isRequiredPath($request)) {
                throw InternalException::custom('IdempotencyKeyRequired',
                    'Idempotency-Key header is required on this endpoint.',
                    [['Field' => 'Idempotency-Key', 'Rule' => 'Required']],
                )
            }

            return null;
        }
        if (preg_match(self::KEY_REGEX, $raw) !== 1) {
            throw ValidationException::validationFailed(
                'Idempotency-Key format invalid (16..128 printable ASCII).',
                [['Field' => 'Idempotency-Key', 'Rule' => 'FormatInvalid']],
            );
        }

        return $raw;
    }

    private function isRequiredPath(Request $request): bool
    {
        $path = strtolower(ltrim($request->path(), '/'));
        foreach (self::REQUIRED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function processWithKey(Request $request, Closure $next, string $key): Response
    {
        $hash = IdempotencyCanonicalizer::hashBody($request->getContent());
        $scope = $this->scope($request, $key);

        return DB::connection(self::CONNECTION)->transaction(
            fn (): Response => $this->serialiseAndExecute($request, $next, $scope, $hash, $key)
        );
    }

    /**
     * @param array{Endpoint:string,ActorId:string,IdempotencyKey:string} $scope
     */
    private function serialiseAndExecute(Request $request, Closure $next, array $scope, string $hash, string $key): Response
    {
        $stored = $this->lockAndFetch($scope);
        if ($stored !== null && $this->isFresh($stored)) {
            return $this->handleStored($stored, $hash, $request, $key);
        }
        if ($stored !== null) {
            $this->deleteExpired($scope);
        }

        return $this->executeAndStore($request, $next, $scope, $hash, $key);
    }

    /**
     * @param array{Endpoint:string,ActorId:string,IdempotencyKey:string} $scope
     * @return array{BodyHash:string,ResponseStatus:int,ResponseHeadersJson:string,ResponseBody:string,ExpiresAt:string}|null
     */
    private function lockAndFetch(array $scope): ?array
    {
        $row = DB::connection(self::CONNECTION)
            ->table(self::TABLE)
            ->where('Endpoint', $scope['Endpoint'])
            ->where('ActorId', $scope['ActorId'])
            ->where('IdempotencyKey', $scope['IdempotencyKey'])
            ->lockForUpdate()
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param array{ExpiresAt:string} $stored
     */
    private function isFresh(array $stored): bool
    {
        return Carbon::parse($stored['ExpiresAt'])->isFuture();
    }

    /**
     * @param array{Endpoint:string,ActorId:string,IdempotencyKey:string} $scope
     */
    private function deleteExpired(array $scope): void
    {
        DB::connection(self::CONNECTION)
            ->table(self::TABLE)
            ->where('Endpoint', $scope['Endpoint'])
            ->where('ActorId', $scope['ActorId'])
            ->where('IdempotencyKey', $scope['IdempotencyKey'])
            ->delete();
    }

    /**
     * @param array{BodyHash:string,ResponseStatus:int,ResponseHeadersJson:string,ResponseBody:string} $stored
     */
    private function handleStored(array $stored, string $hash, Request $request, string $key): Response
    {
        if ((string) $stored['BodyHash'] !== $hash) {
            Log::warning('idempotency.conflict', [
                'Endpoint' => $request->path(),
                'Key' => $key,
                'Outcome' => 'Conflict',
            ]);
            throw DomainConflictException::custom('IdempotencyConflict',
                'Idempotency-Key reused with a different request body.',
                [['Field' => 'Idempotency-Key', 'Rule' => 'BodyMismatch']],
            )
        }
        Log::info('idempotency.replay', [
            'Endpoint' => $request->path(),
            'Key' => $key,
            'Outcome' => 'Replay',
        ]);
        $headers = $this->decodeHeaders((string) $stored['ResponseHeadersJson']);

        return response((string) $stored['ResponseBody'], (int) $stored['ResponseStatus'], $headers);
    }

    /**
     * @param array{Endpoint:string,ActorId:string,IdempotencyKey:string} $scope
     */
    private function executeAndStore(Request $request, Closure $next, array $scope, string $hash, string $key): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }
        $this->persistSnapshot($scope, $hash, $response);
        Log::info('idempotency.fresh', ['Endpoint' => $request->path(), 'Key' => $key, 'Outcome' => 'Fresh']);

        return $response;
    }

    /**
     * @param array{Endpoint:string,ActorId:string,IdempotencyKey:string} $scope
     */
    private function persistSnapshot(array $scope, string $hash, Response $response): void
    {
        $ttl = (int) config('lara.idempotency_ttl_seconds', self::DEFAULT_TTL_SECONDS);
        $now = Carbon::now();
        try {
            DB::connection(self::CONNECTION)->table(self::TABLE)->insert([
                'Endpoint' => $scope['Endpoint'],
                'ActorId' => $scope['ActorId'],
                'IdempotencyKey' => $scope['IdempotencyKey'],
                'BodyHash' => $hash,
                'ResponseStatus' => $response->getStatusCode(),
                'ResponseHeadersJson' => $this->encodeHeaders($response->headers->all()),
                'ResponseBody' => (string) $response->getContent(),
                'CreatedAt' => $now,
                'UpdatedAt' => $now,
                'ExpiresAt' => $now->copy()->addSeconds($ttl),
            ]);
        } catch (Throwable $e) {
            Log::warning('idempotency.persist_failed', [
                'Endpoint' => $scope['Endpoint'],
                'ActorId' => $scope['ActorId'],
                'Error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string,array<int,string|null>|string|null> $headers
     */
    private function encodeHeaders(array $headers): string
    {
        return (string) json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function decodeHeaders(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{Endpoint:string,ActorId:string,IdempotencyKey:string}
     */
    private function scope(Request $request, string $key): array
    {
        $actorId = (string) ($request->attributes->get('lara.actor_id') ?? 'anon');
        $endpoint = strtolower($request->method() . ' ' . $request->path());

        return [
            'Endpoint' => substr($endpoint, 0, 200),
            'ActorId' => substr($actorId, 0, 64),
            'IdempotencyKey' => $key,
        ];
    }
}
