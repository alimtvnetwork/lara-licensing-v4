<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\ResellerStoreRequest;
use App\Http\Requests\Admin\ResellerUpdateRequest;
use App\Models\Reseller;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use App\Support\EntityHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 06 step 29. Admin surface for `Resellers` on the Root DB.
 *
 * Enforces the canonical envelope, `Idempotency-Key` (via global
 * middleware for POST), and `If-Match` (via global middleware for
 * PATCH plus server-side hash compare here). Uniqueness violations on
 * ResellerName / ResellerSlug raise `ResellerConflict`.
 *
 * Endpoint set (spec 21 §Admin Resellers):
 *   GET    /Api/Admin/Resellers            list  (200)
 *   POST   /Api/Admin/Resellers            create (201)
 *   GET    /Api/Admin/Resellers/{Slug}     show  (200)
 *   PATCH  /Api/Admin/Resellers/{Slug}     update (200)
 */
final class ResellerController
{
    private const SLUG_REGEX = '/^[a-z][a-z0-9-]{2,63}$/';
    private const UNIQUE_VIOLATION_SQLSTATE = '23505';
    private const UNIQUE_VIOLATION_SQLSTATE_GENERIC = '23000';


        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="ResellerController index",
     *     tags={"ResellerController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $rows = Reseller::query()->orderBy('ResellerId')->get();
        $projections = $rows->map(fn (Reseller $r): array => $this->project($r))->all();

        return ApiEnvelope::success($projections, $this->requestId($request));
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="ResellerController show",
     *     tags={"ResellerController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function show(Request $request, string $ResellerSlug): JsonResponse
    {
        $row = $this->findBySlugOrFail($ResellerSlug);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request));
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="ResellerController store",
     *     tags={"ResellerController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function store(ResellerStoreRequest $request): JsonResponse
    {
        $data = $request->payload();
        try {
            $row = new Reseller();
            $row->ResellerName = $data['ResellerName'];
            $row->ResellerSlug = $data['ResellerSlug'];
            $row->ContactEmail = $data['ContactEmail'];
            $row->IsActive = $data['IsActive'] ?? true;
            $row->save();
        } catch (Throwable $e) {
            $this->rethrowUniqueAsConflict($e);
            throw $e;
        }
        AuditWriter::write($request, 'ResellerCreated', 'Resellers', (int) $row->ResellerId, [
            'ResellerSlug' => (string) $row->ResellerSlug,
            'ResellerName' => (string) $row->ResellerName,
        ]);

        return ApiEnvelope::success(
            results: [$this->project($row)],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }

        /**
     * @OA\Patch(
     *     path="/api/placeholder",
     *     summary="ResellerController update",
     *     tags={"ResellerController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function update(ResellerUpdateRequest $request, string $ResellerSlug): JsonResponse
    {
        $row = $this->findBySlugOrFail($ResellerSlug);
        $this->assertIfMatch($request, $row);
        $data = $request->payload();
        try {
            $row->fill($data);
            $row->UpdatedAt = now();
            $row->save();
        } catch (Throwable $e) {
            $this->rethrowUniqueAsConflict($e);
            throw $e;
        }
        AuditWriter::write($request, 'ResellerUpdated', 'Resellers', (int) $row->ResellerId, [
            'ResellerSlug' => (string) $row->ResellerSlug,
            'Fields' => array_keys($data),
        ]);

        return ApiEnvelope::success([$this->project($row->refresh())], $this->requestId($request));
    }

    private function findBySlugOrFail(string $slug): Reseller
    {
        if (preg_match(self::SLUG_REGEX, $slug) !== 1) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller slug is malformed.',
                [['Field' => 'ResellerSlug', 'Rule' => 'FormatInvalid']],
            );
        }
        $row = Reseller::query()->where('ResellerSlug', $slug)->first();
        if ($row === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller not found for the requested slug.',
                [['Field' => 'ResellerSlug', 'Rule' => 'NotFound']],
            );
        }

        return $row;
    }

    // Validation for store/update is now performed by
    // App\Http\Requests\Admin\ResellerStoreRequest and
    // App\Http\Requests\Admin\ResellerUpdateRequest (Plan 10 step 2).



    private function assertIfMatch(Request $request, Reseller $row): void
    {
        $header = (string) $request->attributes->get('lara.if_match', '');
        // EtagMiddleware guarantees presence for PATCH admin/resellers/*.
        // Defensive: recompute if the middleware scope changes.
        if ($header === '') {
            throw ValidationException::custom('PreconditionRequired',
                'If-Match header is required on this endpoint.',
                [['Field' => 'If-Match', 'Rule' => 'Missing']],
            )
        }
        $currentHex = EntityHasher::hashSingleResource($this->project($row), $this->requestId($request));
        if (EntityHasher::ifMatchMatches($header, $currentHex) === false) {
            throw ValidationException::custom('PreconditionFailed',
                'Reseller state has changed since the client-cached version.',
                [['Field' => 'If-Match', 'Rule' => 'Stale']],
            )
        }
    }

    /**
     * @return array{ResellerId:int, ResellerName:string, ResellerSlug:string, ContactEmail:string, IsActive:bool, CreatedAt:string, UpdatedAt:string}
     */
    private function project(Reseller $row): array
    {
        // Plan 10 step 4 (wave 2): delegate wire shape to ResellerResource
        // so PascalCase envelope is enforced at the Resource layer.
        return (new \App\Http\Resources\ResellerResource($row))->resolve();
    }

    private function isoOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $value;
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }

    private function rethrowUniqueAsConflict(Throwable $e): void
    {
        $sqlState = null;
        if ($e instanceof \PDOException) {
            $sqlState = $e->getCode();
        }
        $previous = $e->getPrevious();
        if ($sqlState === null && $previous instanceof \PDOException) {
            $sqlState = $previous->getCode();
        }
        $isUnique = (string) $sqlState === self::UNIQUE_VIOLATION_SQLSTATE
            || ((string) $sqlState === self::UNIQUE_VIOLATION_SQLSTATE_GENERIC
                && str_contains((string) $e->getMessage(), 'UNIQUE constraint failed'));
        if ($isUnique) {
            Log::info('reseller.conflict', ['SqlState' => $sqlState]);
            throw DomainConflictException::custom('ResellerConflict',
                'Reseller name or slug is already in use.',
                [['Field' => 'ResellerSlug', 'Rule' => 'UniqueViolation']],
                $e,
            )
        }
    }
}

