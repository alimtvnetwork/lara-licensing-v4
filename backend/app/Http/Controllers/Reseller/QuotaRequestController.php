<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reseller;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Models\QuotaRequest;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


/**
 * Plan 06 step 36. Reseller-scoped submit/list/cancel of quota requests.
 *
 * Normative sources:
 *  - spec/21-app/42-quota-requests.md v1.1.0 §State machine and §Errors
 *  - spec/23-app-db/10-reseller-shard-split-db.md §App-tier tables
 *    (QuotaRequests lives on the reseller's shard, never on Root)
 *
 * Shard binding is done by `ShardBindingMiddleware` before this controller
 * runs. The caller does NOT supply a `ResellerId`; it is derived from the
 * bound tenant and used verbatim on every write so cross-reseller writes
 * are structurally impossible per spec 10 §Routing Rules.
 *
 * Endpoints (registered in `routes/api.php`):
 *   GET   /Api/Reseller/QuotaRequests                  index  (200)
 *   POST  /Api/Reseller/QuotaRequests                  store  (201)
 *   POST  /Api/Reseller/QuotaRequests/{RequestId}/Cancel cancel (200)
 *
 * Approve/Deny/Adjust are Admin-only per spec 42 §Endpoints and land in
 * Plan 06 step 37 (`Admin\QuotaRequestController`). This controller
 * DELIBERATELY refuses those verbs so the RBAC surface stays honest.
 *
 * Not-yet-shipped dependency: shard `Quotas` table (aka `ResellerQuotas`)
 * has no migration yet, so this controller cannot verify that the
 * `(LicenseCategoryId, LicenseTierId)` tuple has an existing quota row.
 * That check will land with `QuotaService` in Plan 06 step 40. Today the
 * store path validates the tuple against the closed-set config catalogs
 * (`license_categories`, `license_tiers`) so an unknown category or tier
 * still returns 400 `ValidationFailed`, not a silent success.
 */
final class QuotaRequestController
{
    private const SHARD_CONNECTION = 'shard';
    private const STATUS_PENDING = 1;
    private const STATUS_APPROVED = 2;
    private const STATUS_DENIED = 3;
    private const STATUS_CANCELLED = 4;
    private const LIST_LIMIT_DEFAULT = 50;
    private const LIST_LIMIT_MAX = 200;
    private const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';
    private const IDEMPOTENCY_KEY_LEN = 32;

    public function index(Request $request): JsonResponse
    {
        $resellerId = $this->requireResellerId($request);
        $limit = $this->parseLimit($request);
        $rows = QuotaRequest::query()
            ->where('ResellerId', $resellerId)
            ->orderByDesc('SubmittedAt')
            ->limit($limit)
            ->get();
        $projected = $rows->map(fn (QuotaRequest $r): array => $this->project($r))->all();

        return ApiEnvelope::success($projected, $this->requestId($request));
    }

    public function store(\App\Http\Requests\Reseller\QuotaRequestStoreRequest $request): JsonResponse
    {
        $resellerId = $this->requireResellerId($request);
        $payload = $request->payload();
        $this->assertClosedSetOrThrow('LicenseCategoryId', $payload['LicenseCategoryId'], $this->categoryOrdinals());
        $this->assertClosedSetOrThrow('LicenseTierId', $payload['LicenseTierId'], $this->tierOrdinals());
        $idem = $this->requireIdempotencyKey($request);
        $existing = $this->findByIdempotency($resellerId, $this->actorId($request), $idem);
        if ($existing !== null) {
            return ApiEnvelope::success([$this->project($existing)], $this->requestId($request));
        }
        $row = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): QuotaRequest => $this->insertPending($request, $resellerId, $payload, $idem)
        );
        AuditWriter::write($request, 'QuotaRequestSubmitted', 'QuotaRequests', (int) $row->RequestId, [
            'ResellerId' => (int) $resellerId,
            'RequestedDelta' => (int) $row->RequestedDelta,
        ]);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request), 201);
    }

    public function cancel(Request $request, string $requestId): JsonResponse
    {
        $resellerId = $this->requireResellerId($request);
        $row = DB::connection(self::SHARD_CONNECTION)->transaction(
            fn (): QuotaRequest => $this->applyCancel($request, $resellerId, $requestId)
        );
        AuditWriter::write($request, 'QuotaRequestCanceled', 'QuotaRequests', (int) $row->RequestId, [
            'ResellerId' => (int) $resellerId,
        ]);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request));
    }

    private function requireResellerId(Request $request): int
    {
        $slug = (string) $request->attributes->get('ResellerSlug', '');
        if ($slug === '') {
            throw AuthException::forbidden(
                'Reseller shard is not bound; user has no tenant.',
                [['Field' => 'ResellerSlug', 'Rule' => 'Unbound']],
            );
        }
        $id = DB::connection('root')->table('Resellers')->where('ResellerSlug', $slug)->value('ResellerId');
        if ($id === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Bound reseller does not resolve to a Root row.',
                [['Field' => 'ResellerSlug', 'Rule' => 'NotFound', 'Value' => $slug]],
            );
        }

        return (int) $id;
    }


    /**
     * @param  array<int>  $allowed
     */
    private function assertClosedSetOrThrow(string $field, int $value, array $allowed): void
    {
        if (in_array($value, $allowed, true)) {
            return;
        }
        throw ValidationException::validationFailed(
            'Value is not in the closed set catalog for this field.',
            [['Field' => $field, 'Rule' => 'ClosedSetMember', 'Value' => (string) $value]],
        );
    }

    /** @return array<int> */
    private function categoryOrdinals(): array
    {
        /** @var array<string,int> $map */
        $map = (array) Config::get('lara.license_categories', []);

        return array_values(array_map('intval', $map));
    }

    /** @return array<int> */
    private function tierOrdinals(): array
    {
        /** @var array<string,int> $map */
        $map = (array) Config::get('lara.license_tiers', []);

        return array_values(array_map('intval', $map));
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $key = trim((string) $request->headers->get(self::IDEMPOTENCY_KEY_HEADER, ''));
        if ($key === '') {
            throw InternalException::custom('IdempotencyKeyRequired',
                'Idempotency-Key header is required on this endpoint.',
                [['Field' => self::IDEMPOTENCY_KEY_HEADER, 'Rule' => 'Missing']],
            )
        }
        if (strlen($key) !== self::IDEMPOTENCY_KEY_LEN) {
            throw ValidationException::validationFailed(
                'Idempotency-Key must be exactly ' . self::IDEMPOTENCY_KEY_LEN . ' characters.',
                [['Field' => self::IDEMPOTENCY_KEY_HEADER, 'Rule' => 'Length', 'Value' => (string) strlen($key)]],
            );
        }

        return $key;
    }

    private function findByIdempotency(int $resellerId, ?int $actorId, string $key): ?QuotaRequest
    {
        if ($actorId === null) {
            return null;
        }

        return QuotaRequest::query()
            ->where('ResellerId', $resellerId)
            ->where('SubmittedByUserId', $actorId)
            ->where('IdempotencyKey', $key)
            ->first();
    }

    /**
     * @param  array{LicenseCategoryId:int,LicenseTierId:int,RequestedDelta:int,Justification:string}  $p
     */
    private function insertPending(Request $request, int $resellerId, array $p, string $idem): QuotaRequest
    {
        $actorId = $this->actorId($request);
        if ($actorId === null) {
            throw AuthException::unauthorized(
                'Authenticated user identity is required to submit a quota request.',
                [['Field' => 'SubmittedByUserId', 'Rule' => 'Missing']],
            );
        }
        $row = new QuotaRequest();
        $row->ResellerId = $resellerId;
        $row->LicenseCategoryId = $p['LicenseCategoryId'];
        $row->LicenseTierId = $p['LicenseTierId'];
        $row->RequestedDelta = $p['RequestedDelta'];
        $row->ApprovedDelta = null;
        $row->Status = self::STATUS_PENDING;
        $row->Justification = $p['Justification'];
        $row->DenialReason = null;
        $row->SubmittedByUserId = $actorId;
        $row->DecidedByUserId = null;
        $row->SubmittedAt = Carbon::now();
        $row->DecidedAt = null;
        $row->RequestId = $this->requestId($request);
        $row->IdempotencyKey = $idem;
        $row->save();
        Log::info('quota_request.submitted', [
            'quotaRequestId' => (int) $row->QuotaRequestId,
            'resellerId' => $resellerId,
            'licenseCategoryId' => $p['LicenseCategoryId'],
            'licenseTierId' => $p['LicenseTierId'],
            'requestedDelta' => $p['RequestedDelta'],
            'submittedByUserId' => $actorId,
            'requestId' => $this->requestId($request),
        ]);

        return $row;
    }

    private function applyCancel(Request $request, int $resellerId, string $requestId): QuotaRequest
    {
        $row = $this->requireOwnedForUpdate($resellerId, $requestId);
        $actorId = $this->actorId($request);
        if ($row->SubmittedByUserId !== $actorId) {
            throw AuthException::forbidden(
                'Only the original submitter can cancel a pending quota request.',
                [['Field' => 'SubmittedByUserId', 'Rule' => 'NotOwner']],
            );
        }
        if ($row->Status !== self::STATUS_PENDING) {
            throw DomainConflictException::custom('IdempotencyConflict',
                'Quota request is not in Pending state and cannot be cancelled.',
                [['Field' => 'Status', 'Rule' => 'NotPending', 'Value' => (string) $row->Status]],
            )
        }
        $row->Status = self::STATUS_CANCELLED;
        $row->DecidedByUserId = $actorId;
        $row->DecidedAt = Carbon::now();
        $row->save();
        Log::info('quota_request.cancelled', [
            'quotaRequestId' => (int) $row->QuotaRequestId,
            'resellerId' => $resellerId,
            'cancelledByUserId' => $actorId,
            'requestId' => $this->requestId($request),
        ]);

        return $row;
    }

    private function requireOwnedForUpdate(int $resellerId, string $requestId): QuotaRequest
    {
        if (ctype_digit($requestId) === false) {
            throw ValidationException::validationFailed(
                'QuotaRequestId path segment must be a positive integer.',
                [['Field' => 'QuotaRequestId', 'Rule' => 'Integer', 'Value' => $requestId]],
            );
        }
        $row = QuotaRequest::query()
            ->where('QuotaRequestId', (int) $requestId)
            ->lockForUpdate()
            ->first();
        if ($row === null || (int) $row->ResellerId !== $resellerId) {
            throw NotFoundException::custom('ResourceRoleNotAssigned',
                'Quota request not found on this reseller shard.',
                [['Field' => 'QuotaRequestId', 'Rule' => 'NotFound', 'Value' => $requestId]],
            )
        }

        return $row;
    }

    private function parseLimit(Request $request): int
    {
        $raw = (int) $request->query('Limit', (string) self::LIST_LIMIT_DEFAULT);

        return max(1, min(self::LIST_LIMIT_MAX, $raw));
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }


    /**
     * @return array<string, mixed>
     */
    private function project(QuotaRequest $r): array
    {
        return (new \App\Http\Resources\QuotaRequestResource($r))->resolve();
    }

    private function statusName(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DENIED => 'Denied',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    private function isoOrEmpty(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $v;
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }
}
