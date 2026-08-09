<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\PrefixStoreRequest;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Throwable;

/**
 * Plan 06 step 30. Admin surface for the Root `Prefixes` registry.
 *
 * Endpoints (spec 21 §Admin Prefixes):
 *   GET    /Api/Admin/Prefixes                 list  (200)
 *   POST   /Api/Admin/Prefixes                 create (201)
 *   DELETE /Api/Admin/Prefixes/{PrefixValue}   delete (200)
 *
 * DELETE performs a cross-shard "in-use" check: bind the owning
 * reseller's shard and count `Licenses` rows with matching
 * `PrefixValue`. If any exist, reject with `PrefixInUse` (409). This
 * is the correct check under the split-DB architecture per
 * spec/23-app-db/10 §Routing Rules; the Root registry cannot answer
 * "is this prefix referenced by any license" by itself.
 */
final class PrefixController
{
    private const PREFIX_REGEX = '/^[A-Z0-9]{2,12}$/';
    private const UNIQUE_VIOLATION_SQLSTATE = '23505';
    private const UNIQUE_VIOLATION_SQLSTATE_GENERIC = '23000';
    private const SHARD_CONNECTION = 'shard';
    private const LICENSES_TABLE = 'Licenses';

    public function __construct(private readonly ShardResolver $shardResolver)
    {
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="PrefixController index",
     *     tags={"PrefixController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $rows = Prefix::query()->orderBy('PrefixId')->get();
        $projections = $rows->map(fn (Prefix $p): array => $this->project($p))->all();

        return ApiEnvelope::success($projections, $this->requestId($request));
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="PrefixController store",
     *     tags={"PrefixController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function store(PrefixStoreRequest $request): JsonResponse
    {
        $data = $request->payload();
        $reseller = $this->requireReseller((int) $data['ResellerId']);
        try {
            $row = new Prefix();
            $row->ResellerId = $reseller->ResellerId;
            $row->PrefixValue = $data['PrefixValue'];
            $row->IsActive = $data['IsActive'] ?? true;
            $row->save();
        } catch (Throwable $e) {
            $this->rethrowUniqueAsConflict($e);
            throw $e;
        }
        AuditWriter::write($request, 'PrefixCreated', 'Prefixes', (int) $row->PrefixId, [
            'PrefixValue' => (string) $row->PrefixValue,
            'ResellerId' => (int) $row->ResellerId,
        ]);

        return ApiEnvelope::success(
            results: [$this->project($row)],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }

        /**
     * @OA\Delete(
     *     path="/api/placeholder",
     *     summary="PrefixController destroy",
     *     tags={"PrefixController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function destroy(Request $request, string $PrefixValue): JsonResponse
    {
        $row = $this->findByValueOrFail($PrefixValue);
        $reseller = $this->requireReseller((int) $row->ResellerId);
        $this->assertNotInUseOnShard($reseller->ResellerSlug, $row->PrefixValue);
        $row->delete();
        AuditWriter::write($request, 'PrefixDeleted', 'Prefixes', (int) $row->PrefixId, [
            'PrefixValue' => (string) $row->PrefixValue,
            'ResellerId' => (int) $row->ResellerId,
        ]);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request));
    }

    private function findByValueOrFail(string $value): Prefix
    {
        $this->assertPrefixFormat($value);
        $row = Prefix::query()->where('PrefixValue', $value)->first();
        if ($row === null) {
            throw NotFoundException::notFound('PrefixNotFound',
                'Prefix not found for the requested value.',
                [['Field' => 'PrefixValue', 'Rule' => 'NotFound', 'Value' => $value]],
            );
        }

        return $row;
    }

    private function assertPrefixFormat(string $value): void
    {
        if (preg_match(self::PREFIX_REGEX, $value) !== 1) {
            throw NotFoundException::notFound('PrefixNotFound',
                'Prefix value is malformed.',
                [['Field' => 'PrefixValue', 'Rule' => 'FormatInvalid', 'Value' => $value]],
            );
        }
    }

    // Store validation now handled by
    // App\Http\Requests\Admin\PrefixStoreRequest (Plan 10 step 2).



    private function requireReseller(int $resellerId): Reseller
    {
        $row = Reseller::query()->where('ResellerId', $resellerId)->first();
        if ($row === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller not found for the requested id.',
                [['Field' => 'ResellerId', 'Rule' => 'NotFound', 'Value' => (string) $resellerId]],
            );
        }

        return $row;
    }

    private function assertNotInUseOnShard(string $resellerSlug, string $prefixValue): void
    {
        try {
            $this->shardResolver->bind($resellerSlug);
            $count = DB::connection(self::SHARD_CONNECTION)
                ->table(self::LICENSES_TABLE)
                ->where('PrefixValue', $prefixValue)
                ->count();
        } catch (LaraException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('prefix.destroy.shard_check_failed', [
                'ResellerSlug' => $resellerSlug,
                'PrefixValue' => $prefixValue,
                'Exception' => $e::class,
            ]);
            throw InternalException::custom('ServiceUnavailable',
                'Prefix in-use check against shard failed.',
                [['Field' => 'ShardBinding', 'Rule' => 'Unavailable']],
                $e,
            )
        }
        if ($count > 0) {
            throw DomainConflictException::conflict('PrefixInUse',
                'Prefix is referenced by existing licenses on the reseller shard.',
                [['Field' => 'PrefixValue', 'Rule' => 'LicensesExist', 'Value' => (string) $count]],
            );
        }
    }

    /**
     * @return array{PrefixId:int, PrefixValue:string, ResellerId:int, IsActive:bool, CreatedAt:string, UpdatedAt:string}
     */
    private function project(Prefix $row): array
    {
        /** @var array{PrefixId:int, PrefixValue:string, ResellerId:int, IsActive:bool, CreatedAt:string, UpdatedAt:string} $shape */
        $shape = (new \App\Http\Resources\PrefixResource($row))->resolve();

        return $shape;
    }


    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }

    private function rethrowUniqueAsConflict(Throwable $e): void
    {
        $sqlState = $e instanceof \PDOException ? $e->getCode() : null;
        $prev = $e->getPrevious();
        if ($sqlState === null && $prev instanceof \PDOException) {
            $sqlState = $prev->getCode();
        }
        $isUnique = (string) $sqlState === self::UNIQUE_VIOLATION_SQLSTATE
            || ((string) $sqlState === self::UNIQUE_VIOLATION_SQLSTATE_GENERIC
                && str_contains((string) $e->getMessage(), 'UNIQUE constraint failed'));
        if ($isUnique) {
            Log::info('prefix.conflict', ['SqlState' => $sqlState]);
            throw DomainConflictException::custom('PrefixConflict',
                'Prefix value is already registered.',
                [['Field' => 'PrefixValue', 'Rule' => 'UniqueViolation']],
                $e,
            )
        }
    }
}
