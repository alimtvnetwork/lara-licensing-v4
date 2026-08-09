<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 40. Shard-scoped quota accounting for reseller-issued
 * licenses.
 *
 * Preflight and decrement/restore MUST run under the caller's shard
 * connection ($shardConnection defaults to `shard`, which
 * `App\Db\ShardResolver::bind()` binds before invocation). Every
 * mutating operation MUST be called inside a shard transaction opened
 * by the controller so the `Quotas` update and the `LicenseLedger`
 * insert cannot drift apart (invariant per spec/21-app/48-quota-restore-on-revoke.md
 * §Ledger contract: `SUM(Delta) = LicensesGranted - LicensesConsumed`).
 *
 * Wire-in status: This service is authored but not yet wired into
 * `Admin\LicenseController::issue` / `::revoke` because the shard
 * `Licenses` table does not carry `LicenseCategoryId` yet (see spec 23
 * §Licenses pending column add). The controller will call this service
 * once that column lands; until then callers can invoke this service
 * directly from tests and from reseller-issued flows that already have
 * the tuple in hand. Contract is stable so the wire-in is a mechanical
 * follow-up, not a rewrite.
 */
final class QuotaService
{
    private const SHARD_CONNECTION = 'shard';
    private const QUOTAS_TABLE = 'Quotas';
    private const LEDGER_TABLE = 'LicenseLedger';
    private const LEDGER_ACTION_CONSUMED = 'QuotaConsumed';
    private const LEDGER_ACTION_RESTORED = 'QuotaRestored';
    private const LEDGER_DELTA_CONSUMED = -1;
    private const LEDGER_DELTA_RESTORED = 1;
    private const TIER_ORDINAL_MIN = 1;
    private const TIER_ORDINAL_MAX = 4;
    private const CATEGORY_ORDINAL_MIN = 1;
    private const CATEGORY_ORDINAL_MAX = 7;

    /**
     * Verify the reseller has remaining capacity for the (category, tier)
     * tuple covering `$now`. Throws `QuotaExhausted` (409) if none.
     *
     * @return array{ResellerId:int, LicenseCategoryId:int, LicenseTierId:int, PeriodStart:string, PeriodEnd:?string, LicensesGranted:int, LicensesConsumed:int, Available:int}

     */
    public function preflight(int $resellerId, int $categoryId, int $tierId, ?Carbon $now = null): array
    {
        $this->assertTuple($resellerId, $categoryId, $tierId);
        $row = $this->lookupActiveQuotaRow($resellerId, $categoryId, $tierId, $now ?? Carbon::now(), forUpdate: false);
        $available = $this->availableFromRow($row);
        if ($available <= 0) {
            throw DomainConflictException::conflict('QuotaExhausted',
                'Reseller quota exhausted for the requested tuple.',
                [['Field' => 'Quota', 'Rule' => 'Exhausted', 'Value' => $resellerId . '/' . $categoryId . '/' . $tierId]],
            );
        }

        return $this->projectRow($row, $available);
    }

    /**
     * Consume one unit under lock. MUST be called inside a shard
     * transaction opened by the caller. Returns the inserted
     * LicenseLedger id so the caller can back-reference it on the
     * `Licenses.ResellerQuotaLedgerId` column for restore eligibility.
     */
    public function decrement(
        int $resellerId,
        int $categoryId,
        int $tierId,
        int $licenseId,
        string $tierName,
        string $requestId,
        int $actorUserId,
        ?Carbon $now = null,
    ): int {
        $this->assertTuple($resellerId, $categoryId, $tierId);
        $now = $now ?? Carbon::now();
        $row = $this->lookupActiveQuotaRow($resellerId, $categoryId, $tierId, $now, forUpdate: true);
        if ($this->availableFromRow($row) <= 0) {
            throw DomainConflictException::conflict('QuotaExhausted',
                'Reseller quota exhausted at decrement time.',
                [['Field' => 'Quota', 'Rule' => 'Exhausted', 'Value' => $resellerId . '/' . $categoryId . '/' . $tierId]],
            );
        }
        $this->bumpConsumedBy($row, 1, $now);
        $ledgerId = $this->insertLedgerRow(
            resellerId: $resellerId,
            categoryId: $categoryId,
            tierName: $tierName,
            action: self::LEDGER_ACTION_CONSUMED,
            delta: self::LEDGER_DELTA_CONSUMED,
            licenseId: $licenseId,
            requestId: $requestId,
            actorUserId: $actorUserId,
            now: $now,
        );
        Log::info('quota.decrement', [
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => $categoryId,
            'LicenseTierId' => $tierId,
            'LicenseId' => $licenseId,
            'LedgerId' => $ledgerId,
        ]);

        return $ledgerId;
    }

    /**
     * Restore one unit under lock. MUST be called inside a shard
     * transaction. Refuses to restore below zero and throws
     * `QuotaLedgerConflict` in that case so the invariant remains
     * observable rather than silently clamped.
     */
    public function restore(
        int $resellerId,
        int $categoryId,
        int $tierId,
        int $licenseId,
        string $tierName,
        string $requestId,
        int $actorUserId,
        ?Carbon $now = null,
    ): int {
        $this->assertTuple($resellerId, $categoryId, $tierId);
        $now = $now ?? Carbon::now();
        $row = $this->lookupActiveQuotaRow($resellerId, $categoryId, $tierId, $now, forUpdate: true);
        if ((int) $row->LicensesConsumed <= 0) {
            throw DomainConflictException::custom('QuotaLedgerConflict',
                'Cannot restore quota: LicensesConsumed is already zero.',
                [['Field' => 'LicensesConsumed', 'Rule' => 'Underflow', 'Value' => (string) $row->LicensesConsumed]],
            )
        }
        $this->bumpConsumedBy($row, -1, $now);
        $ledgerId = $this->insertLedgerRow(
            resellerId: $resellerId,
            categoryId: $categoryId,
            tierName: $tierName,
            action: self::LEDGER_ACTION_RESTORED,
            delta: self::LEDGER_DELTA_RESTORED,
            licenseId: $licenseId,
            requestId: $requestId,
            actorUserId: $actorUserId,
            now: $now,
        );
        Log::info('quota.restore', [
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => $categoryId,
            'LicenseTierId' => $tierId,
            'LicenseId' => $licenseId,
            'LedgerId' => $ledgerId,
        ]);

        return $ledgerId;
    }

    /**
     * Spec 48 §2 restore path. Looks up the ORIGINAL `QuotaConsumed`
     * ledger row via `License.ResellerQuotaLedgerId`, resolves the
     * funding `Quotas` row that was active at that ledger's
     * `CreatedAt` (NOT `NOW()`), and either credits it or returns a
     * deterministic skip reason. Runs under the caller's shard
     * transaction so the ledger insert and `LicensesConsumed`
     * decrement stay atomic.
     *
     * @return array{QuotaRestored: bool, RestoreSkippedReason: string, LedgerId: ?int}
     */
    public function restoreForLicense(
        int $resellerId,
        int $licenseCategoryId,
        string $tierName,
        int $licenseId,
        int $resellerQuotaLedgerId,
        string $requestId,
        int $actorUserId,
        ?Carbon $now = null,
    ): array {
        $now = $now ?? Carbon::now();
        $tierId = $this->tierNameToOrdinal($tierName);
        $this->assertTuple($resellerId, $licenseCategoryId, $tierId);
        $original = $this->loadOriginalConsumedLedger($licenseId, $resellerQuotaLedgerId);
        // Spec 48 §2 step 3: resolve the funding tuple from the ORIGINAL
        // ledger row, not from the current Licenses row (which may have
        // been amended between issue and revoke). If the two disagree,
        // trust the ledger and warn so operators can reconcile.
        $ledgerCategoryId = (int) $original->LicenseCategoryId;
        if ($ledgerCategoryId !== $licenseCategoryId) {
            Log::warning('quota.restore.category_mismatch', [
                'LicenseId' => $licenseId,
                'LicenseCategoryIdFromLicense' => $licenseCategoryId,
                'LicenseCategoryIdFromLedger' => $ledgerCategoryId,
            ]);
        }
        $fundingRow = $this->lookupFundingQuotaRow(
            resellerId: $resellerId,
            categoryId: $ledgerCategoryId,
            tierId: $tierId,
            issuedAt: Carbon::parse((string) $original->CreatedAt),
        );
        if ($this->isPeriodClosed($fundingRow, $now)) {
            Log::warning('quota.restore.skipped', [
                'LicenseId' => $licenseId,
                'ResellerId' => $resellerId,
                'LicenseCategoryId' => $ledgerCategoryId,
                'LicenseTierId' => $tierId,
                'Reason' => 'ClosedPeriod',
            ]);

            return ['QuotaRestored' => false, 'RestoreSkippedReason' => 'ClosedPeriod', 'LedgerId' => null];
        }
        // Idempotency guard (spec 48 §2 step 5): if a `QuotaRestored`
        // ledger row already exists for this LicenseId (unique partial
        // index `UX_LicenseLedger_RestoreOnce`), return the existing
        // ledger id without double-crediting. Cheap upfront lookup
        // avoids relying on catching a race-only PDOException.
        $existing = $this->findExistingRestoreLedger($licenseId);
        if ($existing !== null) {
            Log::info('quota.restore.replay', [
                'LicenseId' => $licenseId,
                'LedgerId' => (int) $existing->LicenseLedgerId,
            ]);

            return ['QuotaRestored' => true, 'RestoreSkippedReason' => '', 'LedgerId' => (int) $existing->LicenseLedgerId];
        }
        $locked = $this->lockQuotaRow($fundingRow);
        if ((int) $locked->LicensesConsumed <= 0) {
            throw DomainConflictException::custom('QuotaLedgerConflict',
                'Cannot restore quota: funding row LicensesConsumed is already zero.',
                [['Field' => 'LicensesConsumed', 'Rule' => 'Underflow', 'Value' => (string) $locked->LicensesConsumed]],
            )
        }
        $this->bumpConsumedBy($locked, -1, $now);
        try {
            $ledgerId = $this->insertLedgerRow(
                resellerId: $resellerId,
                categoryId: $ledgerCategoryId,
                tierName: $tierName,
                action: self::LEDGER_ACTION_RESTORED,
                delta: self::LEDGER_DELTA_RESTORED,
                licenseId: $licenseId,
                requestId: $requestId,
                actorUserId: $actorUserId,
                now: $now,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Race: concurrent revoke won the unique partial index.
            // Re-read and surface as replay so the caller stays
            // idempotent per spec 48 §2 step 5.
            if ($this->isUniqueViolation($e)) {
                Log::warning('quota.restore.race_replay', [
                    'LicenseId' => $licenseId,
                    'SqlState' => $e->getCode(),
                ]);
                $winner = $this->findExistingRestoreLedger($licenseId);
                if ($winner === null) {
                    throw $e;
                }

                return ['QuotaRestored' => true, 'RestoreSkippedReason' => '', 'LedgerId' => (int) $winner->LicenseLedgerId];
            }
            throw $e;
        }
        Log::info('quota.restore', [
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => $ledgerCategoryId,
            'LicenseTierId' => $tierId,
            'LicenseId' => $licenseId,
            'LedgerId' => $ledgerId,
        ]);

        return ['QuotaRestored' => true, 'RestoreSkippedReason' => '', 'LedgerId' => $ledgerId];
    }

    private function tierNameToOrdinal(string $tierName): int
    {
        $map = (array) config('lara.license_tiers', []);
        if (array_key_exists($tierName, $map) === false) {
            throw ValidationException::validationFailed(
                'Unknown TierName; not in closed set.',
                [['Field' => 'TierName', 'Rule' => 'MembershipRequired', 'Value' => $tierName]],
            );
        }

        return (int) $map[$tierName];
    }

    private function loadOriginalConsumedLedger(int $licenseId, int $ledgerId): object
    {
        $row = DB::connection(self::SHARD_CONNECTION)->table(self::LEDGER_TABLE)
            ->where('LicenseLedgerId', $ledgerId)
            ->where('LicenseId', $licenseId)
            ->where('LedgerAction', self::LEDGER_ACTION_CONSUMED)
            ->first();
        if ($row === null) {
            throw DomainConflictException::custom('QuotaLedgerConflict',
                'Original QuotaConsumed ledger row missing for license restore.',
                [['Field' => 'ResellerQuotaLedgerId', 'Rule' => 'NotFound', 'Value' => (string) $ledgerId]],
            )
        }

        return $row;
    }

    private function lookupFundingQuotaRow(int $resellerId, int $categoryId, int $tierId, Carbon $issuedAt): object
    {
        $row = DB::connection(self::SHARD_CONNECTION)->table(self::QUOTAS_TABLE)
            ->where('ResellerId', $resellerId)
            ->where('LicenseCategoryId', $categoryId)
            ->where('LicenseTierId', $tierId)
            ->where('PeriodStart', '<=', $issuedAt)
            ->where(function ($q) use ($issuedAt): void {
                $q->whereNull('PeriodEnd')->orWhere('PeriodEnd', '>', $issuedAt);
            })
            ->orderByRaw('"PeriodStart" DESC')
            ->first();
        if ($row === null) {
            throw DomainConflictException::custom('QuotaLedgerConflict',
                'Funding Quotas row missing for license restore.',
                [['Field' => 'Quotas', 'Rule' => 'NotFound', 'Value' => $resellerId . '/' . $categoryId . '/' . $tierId]],
            )
        }

        return $row;
    }

    private function isPeriodClosed(object $row, Carbon $now): bool
    {
        if ($row->PeriodEnd === null) {
            return false;
        }

        return Carbon::parse((string) $row->PeriodEnd)->lessThan($now);
    }

    private function lockQuotaRow(object $row): object
    {
        $locked = DB::connection(self::SHARD_CONNECTION)->table(self::QUOTAS_TABLE)
            ->where('ResellerId', (int) $row->ResellerId)
            ->where('LicenseCategoryId', (int) $row->LicenseCategoryId)
            ->where('LicenseTierId', (int) $row->LicenseTierId)
            ->where('PeriodStart', $row->PeriodStart)
            ->lockForUpdate()
            ->first();
        if ($locked === null) {
            throw DomainConflictException::custom('QuotaLedgerConflict',
                'Funding Quotas row disappeared under lock.',
                [['Field' => 'Quotas', 'Rule' => 'RaceLost']],
            )
        }

        return $locked;
    }


    private function assertTuple(int $resellerId, int $categoryId, int $tierId): void
    {
        if ($resellerId <= 0) {
            throw ValidationException::validationFailed(
                'ResellerId must be positive.',
                [['Field' => 'ResellerId', 'Rule' => 'Positive']],
            );
        }
        if ($categoryId < self::CATEGORY_ORDINAL_MIN || $categoryId > self::CATEGORY_ORDINAL_MAX) {
            throw ValidationException::validationFailed(
                'LicenseCategoryId out of range.',
                [['Field' => 'LicenseCategoryId', 'Rule' => 'MembershipRequired', 'Value' => (string) $categoryId]],
            );
        }
        if ($tierId < self::TIER_ORDINAL_MIN || $tierId > self::TIER_ORDINAL_MAX) {
            throw ValidationException::validationFailed(
                'LicenseTierId out of range.',
                [['Field' => 'LicenseTierId', 'Rule' => 'MembershipRequired', 'Value' => (string) $tierId]],
            );
        }
    }

    private function lookupActiveQuotaRow(int $resellerId, int $categoryId, int $tierId, Carbon $now, bool $forUpdate): object
    {
        // PeriodEnd is nullable: open-ended rows (PeriodEnd IS NULL) are the
        // hot path per shard migration 000007 partial index
        // IX_Quotas_Reseller_Active. Requiring PeriodEnd >= NOW() would
        // exclude every open-ended row and force spurious QuotaExhausted.
        $query = DB::connection(self::SHARD_CONNECTION)->table(self::QUOTAS_TABLE)
            ->where('ResellerId', $resellerId)
            ->where('LicenseCategoryId', $categoryId)
            ->where('LicenseTierId', $tierId)
            ->where('PeriodStart', '<=', $now)
            ->where(function ($q) use ($now): void {
                $q->whereNull('PeriodEnd')->orWhere('PeriodEnd', '>=', $now);
            })
            ->orderByRaw('"PeriodStart" DESC');
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw DomainConflictException::conflict('QuotaExhausted',
                'No active quota row for the requested tuple.',
                [['Field' => 'Quota', 'Rule' => 'NoActivePeriod', 'Value' => $resellerId . '/' . $categoryId . '/' . $tierId]],
            );
        }

        return $row;
    }


    private function availableFromRow(object $row): int
    {
        $granted = (int) $row->LicensesGranted;
        $consumed = (int) $row->LicensesConsumed;

        return $granted - $consumed;
    }

    private function bumpConsumedBy(object $row, int $delta, Carbon $now): void
    {
        DB::connection(self::SHARD_CONNECTION)->table(self::QUOTAS_TABLE)
            ->where('ResellerId', (int) $row->ResellerId)
            ->where('LicenseCategoryId', (int) $row->LicenseCategoryId)
            ->where('LicenseTierId', (int) $row->LicenseTierId)
            ->where('PeriodStart', $row->PeriodStart)
            ->update([
                'LicensesConsumed' => DB::raw('"LicensesConsumed" + (' . $delta . ')'),
                'UpdatedAt' => $now,
            ]);
    }

    private function insertLedgerRow(
        int $resellerId,
        int $categoryId,
        string $tierName,
        string $action,
        int $delta,
        int $licenseId,
        string $requestId,
        int $actorUserId,
        Carbon $now,
    ): int {
        return (int) DB::connection(self::SHARD_CONNECTION)->table(self::LEDGER_TABLE)->insertGetId([
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => $categoryId,
            'TierName' => $tierName,
            'LedgerAction' => $action,
            'Delta' => $delta,
            'LicenseId' => $licenseId,
            'QuotaRequestId' => null,
            'RequestId' => $requestId,
            'ActorUserId' => $actorUserId,
            'CreatedAt' => $now,
        ], 'LicenseLedgerId');
    }

    private function findExistingRestoreLedger(int $licenseId): ?object
    {
        return DB::connection(self::SHARD_CONNECTION)->table(self::LEDGER_TABLE)
            ->where('LicenseId', $licenseId)
            ->where('LedgerAction', self::LEDGER_ACTION_RESTORED)
            ->first();
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        // Postgres SQLSTATE 23505 = unique_violation.
        return $e->getCode() === '23505';
    }

    /**
     * @return array{ResellerId:int, LicenseCategoryId:int, LicenseTierId:int, PeriodStart:string, PeriodEnd:?string, LicensesGranted:int, LicensesConsumed:int, Available:int}
     */
    private function projectRow(object $row, int $available): array
    {
        return [
            'ResellerId' => (int) $row->ResellerId,
            'LicenseCategoryId' => (int) $row->LicenseCategoryId,
            'LicenseTierId' => (int) $row->LicenseTierId,
            'PeriodStart' => (string) $row->PeriodStart,
            'PeriodEnd' => $row->PeriodEnd === null ? null : (string) $row->PeriodEnd,
            'LicensesGranted' => (int) $row->LicensesGranted,
            'LicensesConsumed' => (int) $row->LicensesConsumed,
            'Available' => $available,
        ];
    }
}

