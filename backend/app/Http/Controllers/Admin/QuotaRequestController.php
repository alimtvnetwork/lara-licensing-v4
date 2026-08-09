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
use App\Models\Quota;
use App\Models\QuotaRequest;
use App\Models\Reseller;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


/**
 * Plan 06 step 37. Admin-scoped Approve/Deny for reseller quota requests.
 *
 * Root cause this fixes: spec 42 v1.1.0 declares the Approve/Deny endpoints
 * as `Admin`-only (`Quotas.Approve` / `Quotas.Adjust` permissions) but the
 * only quota-request controller shipped so far is the Reseller half from
 * step 36. Without this controller, Pending rows in the shard `QuotaRequests`
 * table have no state machine to drive them; AC-QR-003 and AC-QR-004 are
 * impossible to satisfy.
 *
 * Normative sources:
 *  - spec/21-app/42-quota-requests.md v1.1.0 §State machine, §Endpoints, §Errors
 *  - spec/21-app/41-reseller-quotas.md v1.0.0 §3 (Quotas shape), §5 (Ledger)
 *  - spec/23-app-db/10-reseller-shard-split-db.md §App-tier tables
 *
 * Cross-shard: this controller does NOT use `ShardBindingMiddleware`; Admins
 * act across resellers by supplying `?ResellerSlug=...` on every mutation,
 * mirroring `Admin\LicenseController`. Fanout across all shards for a
 * "global inbox" view is deliberately deferred: a single-shard listing keeps
 * the surface honest today, and the fanout aggregator (`Admin\QuotaInbox`)
 * will land in Plan 06 step 42 backed by the same primitives.
 *
 * Approve is transactional on the shard: UPDATE `QuotaRequests` (Pending ->
 * Approved), UPSERT `Quotas.LicensesGranted += ApprovedDelta`, INSERT one
 * `LicenseLedger` row (`LedgerAction = QuotaAdjusted`, `Delta = +ApprovedDelta`,
 * `QuotaRequestId` set, `LicenseId = NULL`). The three writes commit together
 * or roll back together; a partial approve is structurally impossible.
 */
final class QuotaRequestController
{
    private const STATUS_PENDING = 1;
    private const STATUS_APPROVED = 2;
    private const STATUS_DENIED = 3;
    private const STATUS_CANCELLED = 4;
    private const LIST_LIMIT_DEFAULT = 50;
    private const LIST_LIMIT_MAX = 200;
    private const LEDGER_ACTION_ADJUSTED = 'QuotaAdjusted';

    public function __construct(private readonly ShardResolver $shardResolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $reseller = $this->requireReseller($this->requireResellerSlug($request));
        $this->shardResolver->bind($reseller->ResellerSlug);
        $limit = $this->parseLimit($request);
        $status = $this->parseStatusFilter($request);
        $q = QuotaRequest::query()->where('ResellerId', (int) $reseller->ResellerId);
        if ($status !== null) {
            $q->where('Status', $status);
        }
        $rows = $q->orderByDesc('SubmittedAt')->limit($limit)->get();
        $projected = $rows->map(fn (QuotaRequest $r): array => $this->project($r))->all();

        return ApiEnvelope::success($projected, $this->requestId($request));
    }

    /**
     * Plan 06 step 37: cross-tenant fanout inbox at `GET /Api/Admin/QuotaRequests/All`.
     * Root cause this method fixes (one sentence): spec 42 v1.1.0 §"Admin
     * inbox" requires a global list of Pending quota requests across every
     * reseller shard so operators can honor the SLA, but the per-shard
     * `index()` method above forces the admin to name each `ResellerSlug`
     * explicitly, which means a genuinely global operator view was
     * impossible without client-side fanout that would bypass the shard
     * abstraction. Fanout runs sequentially (no parallel connections; the
     * shard connection alias is a singleton per request per
     * `ShardResolver`), rebinds between resellers, and clamps the returned
     * total at `LIST_LIMIT_MAX` after sorting to keep worst-case memory
     * bounded when many shards are active. A single shard failure is
     * logged and skipped so one broken tenant does not blank the inbox
     * for every other tenant; the per-shard error is surfaced in the
     * response `Meta.Warnings` array so callers see the partial state
     * explicitly rather than silently.
     */
    public function indexAll(Request $request): JsonResponse
    {
        $limit = $this->parseLimit($request);
        $status = $this->parseStatusFilter($request);
        $resellers = Reseller::query()->where('IsActive', true)->orderBy('ResellerSlug')->get();
        [$collected, $warnings] = $this->fanoutQuotaRequests($resellers, $status, $request);
        usort($collected, static fn (array $a, array $b): int => strcmp((string) $b['SubmittedAt'], (string) $a['SubmittedAt']));
        $projected = array_slice($collected, 0, $limit);
        $meta = ['Total' => count($collected), 'Warnings' => $warnings, 'ShardCount' => $resellers->count()];
        Log::info('admin.quota_requests.index_all', [
            'requestId' => $this->requestId($request),
            'shardCount' => $resellers->count(),
            'warningCount' => count($warnings),
            'total' => count($collected),
        ]);

        return ApiEnvelope::success(results: $projected, requestId: $this->requestId($request), extraAttributes: ['Meta' => $meta]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Reseller>  $resellers
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,string>>}
     */
    private function fanoutQuotaRequests(\Illuminate\Support\Collection $resellers, ?int $status, Request $request): array
    {
        $collected = [];
        $warnings = [];
        foreach ($resellers as $reseller) {
            try {
                $rows = $this->readShardQuotaRequests($reseller, $status);
                foreach ($rows as $row) {
                    $projected = $this->project($row);
                    $projected['ResellerSlug'] = (string) $reseller->ResellerSlug;
                    $collected[] = $projected;
                }
            } catch (\Throwable $e) {
                Log::warning('admin.quota_requests.index_all.shard_error', [
                    'requestId' => $this->requestId($request),
                    'resellerSlug' => (string) $reseller->ResellerSlug,
                    'error' => $e->getMessage(),
                ]);
                $warnings[] = ['ResellerSlug' => (string) $reseller->ResellerSlug, 'Error' => 'ShardUnavailable'];
            }
        }

        return [$collected, $warnings];
    }

    /**
     * @return \Illuminate\Support\Collection<int, QuotaRequest>
     */
    private function readShardQuotaRequests(Reseller $reseller, ?int $status): \Illuminate\Support\Collection
    {
        $this->shardResolver->bind($reseller->ResellerSlug);
        $q = QuotaRequest::query()->where('ResellerId', (int) $reseller->ResellerId);
        if ($status !== null) {
            $q->where('Status', $status);
        }

        return $q->orderByDesc('SubmittedAt')->limit(self::LIST_LIMIT_MAX)->get();
    }


    public function approve(\App\Http\Requests\Admin\QuotaRequestApproveRequest $request, string $requestId): JsonResponse
    {
        $reseller = $this->requireReseller($this->requireResellerSlug($request));
        $this->shardResolver->bind($reseller->ResellerSlug);
        $payload = $this->normalizeApprovePayload($request->payload());
        $row = DB::connection('shard')->transaction(
            fn (): QuotaRequest => $this->applyApprove($request, (int) $reseller->ResellerId, $requestId, $payload),
        );
        AuditWriter::write($request, 'QuotaRequestApproved', 'QuotaRequests', (int) $row->RequestId, [
            'ResellerId' => (int) $reseller->ResellerId,
            'ResellerSlug' => (string) $reseller->ResellerSlug,
            'ApprovedDelta' => (int) $row->ApprovedDelta,
        ]);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request));
    }

    public function deny(\App\Http\Requests\Admin\QuotaRequestDenyRequest $request, string $requestId): JsonResponse
    {
        $reseller = $this->requireReseller($this->requireResellerSlug($request));
        $this->shardResolver->bind($reseller->ResellerSlug);
        $reason = $request->denialReason();
        $row = DB::connection('shard')->transaction(
            fn (): QuotaRequest => $this->applyDeny($request, (int) $reseller->ResellerId, $requestId, $reason),
        );
        AuditWriter::write($request, 'QuotaRequestDenied', 'QuotaRequests', (int) $row->RequestId, [
            'ResellerId' => (int) $reseller->ResellerId,
            'ResellerSlug' => (string) $reseller->ResellerSlug,
            'DenyReason' => (string) $reason,
        ]);

        return ApiEnvelope::success([$this->project($row)], $this->requestId($request));
    }

    private function requireResellerSlug(Request $request): string
    {
        $slug = trim((string) $request->query('ResellerSlug', ''));
        if ($slug === '') {
            throw ValidationException::validationFailed(
                'ResellerSlug query parameter is required for shard binding.',
                [['Field' => 'ResellerSlug', 'Rule' => 'Required']],
            );
        }

        return $slug;
    }

    private function requireReseller(string $slug): Reseller
    {
        $row = Reseller::query()->where('ResellerSlug', $slug)->first();
        if ($row === null) {
            throw NotFoundException::notFound('ResellerNotFound',
                'Reseller does not exist in the Root directory.',
                [['Field' => 'ResellerSlug', 'Rule' => 'NotFound', 'Value' => $slug]],
            );
        }

        return $row;
    }

    /**
     * Convert the validated FormRequest payload (strings from JSON) into
     * the Carbon-typed structure the transactional apply step expects.
     * PeriodStart defaults to now(); PeriodEnd stays optional. The
     * strict PeriodEnd > PeriodStart assertion still lives here to keep
     * the specific `Rule = GreaterThan` marker documented in spec 42.
     *
     * @param  array{ApprovedDelta:int, PeriodStart?:string, PeriodEnd?:string}  $validated
     * @return array{ApprovedDelta:int,PeriodStart:Carbon,PeriodEnd:?Carbon}
     */
    private function normalizeApprovePayload(array $validated): array
    {
        $start = isset($validated['PeriodStart']) ? Carbon::parse((string) $validated['PeriodStart']) : Carbon::now();
        $end = isset($validated['PeriodEnd']) ? Carbon::parse((string) $validated['PeriodEnd']) : null;
        $this->assertPeriodOrder($start, $end);

        return ['ApprovedDelta' => (int) $validated['ApprovedDelta'], 'PeriodStart' => $start, 'PeriodEnd' => $end];
    }

    private function assertPeriodOrder(Carbon $start, ?Carbon $end): void
    {
        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::validationFailed(
                'PeriodEnd must be strictly greater than PeriodStart.',
                [['Field' => 'PeriodEnd', 'Rule' => 'GreaterThan', 'Value' => $end->toIso8601String()]],
            );
        }
    }

    /**
     * @param  array{ApprovedDelta:int,PeriodStart:Carbon,PeriodEnd:?Carbon}  $p
     */
    private function applyApprove(Request $request, int $resellerId, string $requestId, array $p): QuotaRequest
    {
        $row = $this->requireOwnedPendingForUpdate($resellerId, $requestId);
        $actorId = $this->requireActorId($request);
        $this->upsertQuota($resellerId, (int) $row->LicenseCategoryId, (int) $row->LicenseTierId, $p['ApprovedDelta'], $p['PeriodStart'], $p['PeriodEnd']);
        $this->insertAdjustLedger($resellerId, (int) $row->LicenseTierId, (int) $row->QuotaRequestId, $p['ApprovedDelta'], $actorId, $this->requestId($request));
        $row->Status = self::STATUS_APPROVED;
        $row->ApprovedDelta = $p['ApprovedDelta'];
        $row->DecidedByUserId = $actorId;
        $row->DecidedAt = Carbon::now();
        $row->save();
        Log::info('quota_request.approved', [
            'quotaRequestId' => (int) $row->QuotaRequestId,
            'resellerId' => $resellerId,
            'approvedDelta' => $p['ApprovedDelta'],
            'decidedByUserId' => $actorId,
            'requestId' => $this->requestId($request),
        ]);

        return $row;
    }

    private function applyDeny(Request $request, int $resellerId, string $requestId, string $reason): QuotaRequest
    {
        $row = $this->requireOwnedPendingForUpdate($resellerId, $requestId);
        $actorId = $this->requireActorId($request);
        $row->Status = self::STATUS_DENIED;
        $row->DenialReason = $reason;
        $row->DecidedByUserId = $actorId;
        $row->DecidedAt = Carbon::now();
        $row->save();
        Log::info('quota_request.denied', [
            'quotaRequestId' => (int) $row->QuotaRequestId,
            'resellerId' => $resellerId,
            'decidedByUserId' => $actorId,
            'requestId' => $this->requestId($request),
        ]);

        return $row;
    }

    private function requireOwnedPendingForUpdate(int $resellerId, string $requestId): QuotaRequest
    {
        if (ctype_digit($requestId) === false) {
            throw ValidationException::validationFailed(
                'QuotaRequestId path segment must be a positive integer.',
                [['Field' => 'QuotaRequestId', 'Rule' => 'Integer', 'Value' => $requestId]],
            );
        }
        $row = QuotaRequest::query()->where('QuotaRequestId', (int) $requestId)->lockForUpdate()->first();
        if ($row === null || (int) $row->ResellerId !== $resellerId) {
            throw NotFoundException::custom('ResourceRoleNotAssigned',
                'Quota request not found on the selected reseller shard.',
                [['Field' => 'QuotaRequestId', 'Rule' => 'NotFound', 'Value' => $requestId]],
            )
        }
        if ((int) $row->Status !== self::STATUS_PENDING) {
            throw DomainConflictException::custom('IdempotencyConflict',
                'Quota request is not in Pending state; refusing to transition.',
                [['Field' => 'Status', 'Rule' => 'NotPending', 'Value' => (string) $row->Status]],
            )
        }

        return $row;
    }

    private function upsertQuota(int $resellerId, int $categoryId, int $tierId, int $delta, Carbon $periodStart, ?Carbon $periodEnd): void
    {
        $existing = Quota::query()
            ->where('ResellerId', $resellerId)
            ->where('LicenseCategoryId', $categoryId)
            ->where('LicenseTierId', $tierId)
            ->where('PeriodStart', $periodStart)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            $existing->LicensesGranted = ((int) $existing->LicensesGranted) + $delta;
            $existing->save();

            return;
        }
        $q = new Quota();
        $q->ResellerId = $resellerId;
        $q->LicenseCategoryId = $categoryId;
        $q->LicenseTierId = $tierId;
        $q->LicensesGranted = $delta;
        $q->LicensesConsumed = 0;
        $q->PeriodStart = $periodStart;
        $q->PeriodEnd = $periodEnd;
        $q->save();
    }

    private function insertAdjustLedger(int $resellerId, int $tierId, int $quotaRequestId, int $delta, int $actorId, string $requestId): void
    {
        DB::connection('shard')->table('LicenseLedger')->insert([
            'ResellerId' => $resellerId,
            'TierName' => $this->tierNameByOrdinal($tierId),
            'LedgerAction' => self::LEDGER_ACTION_ADJUSTED,
            'Delta' => $delta,
            'LicenseId' => null,
            'QuotaRequestId' => $quotaRequestId,
            'RequestId' => $requestId,
            'ActorUserId' => $actorId,
        ]);
    }

    private function tierNameByOrdinal(int $ordinal): string
    {
        /** @var array<string,int> $map */
        $map = (array) Config::get('lara.license_tiers', []);
        foreach ($map as $name => $ord) {
            if ((int) $ord === $ordinal) {
                return (string) $name;
            }
        }
        throw ValidationException::validationFailed(
            'LicenseTierId does not map to a configured tier name.',
            [['Field' => 'LicenseTierId', 'Rule' => 'ClosedSetMember', 'Value' => (string) $ordinal]],
        );
    }

    private function parseLimit(Request $request): int
    {
        $raw = (int) $request->query('Limit', (string) self::LIST_LIMIT_DEFAULT);

        return max(1, min(self::LIST_LIMIT_MAX, $raw));
    }

    private function parseStatusFilter(Request $request): ?int
    {
        $raw = $request->query('Status');
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;

        return in_array($n, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DENIED], true) ? $n : null;
    }

    private function requireActorId(Request $request): int
    {
        $id = $request->user()?->getAuthIdentifier();
        if (is_numeric($id) === false) {
            throw AuthException::unauthorized(
                'Authenticated admin identity is required to decide a quota request.',
                [['Field' => 'ActorUserId', 'Rule' => 'Missing']],
            );
        }

        return (int) $id;
    }


    /**
     * @return array<string,mixed>
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
