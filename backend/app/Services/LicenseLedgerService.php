<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 48. Shard-scoped read service for `LicenseLedger`.
 *
 * The ledger is append-only (spec 23-app-db §ResellerQuotaLedger, DB
 * triggers `TrgLicenseLedgerNoUpdate` / `TrgLicenseLedgerNoDelete`), so
 * this service exposes only reads. All queries run on the `shard`
 * connection bound by `ShardBindingMiddleware` (Reseller surface) or
 * `ShardResolver::bind()` (Admin surface with `?ResellerSlug=`); the
 * caller is responsible for shard binding BEFORE calling here.
 *
 * Row scope is enforced by `LicenseId` plus a defensive `ResellerId`
 * predicate so a bug in the caller cannot leak another tenant's rows
 * through this service (spec 21-app/04-roles.md §Reseller row-scope).
 * Ordering is `CreatedAt ASC, LicenseLedgerId ASC` so ties within the
 * same instant remain deterministic per spec 21-app/48 §Ledger contract.
 */
final class LicenseLedgerService
{
    public const CONNECTION = 'shard';
    public const TABLE = 'LicenseLedger';
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    /**
     * @return list<array<string, mixed>>
     */
    public function listForLicense(int $resellerId, int $licenseId, int $limit = self::DEFAULT_LIMIT): array
    {
        $bounded = max(1, min(self::MAX_LIMIT, $limit));
        $rows = DB::connection(self::CONNECTION)->table(self::TABLE)
            ->where('ResellerId', $resellerId)
            ->where('LicenseId', $licenseId)
            ->orderBy('CreatedAt')
            ->orderBy('LicenseLedgerId')
            ->limit($bounded)
            ->get();

        return $rows->map(fn ($r): array => $this->project($r))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function project(object $row): array
    {
        return [
            'LicenseLedgerId' => (int) $row->LicenseLedgerId,
            'ResellerId' => (int) $row->ResellerId,
            'LicenseCategoryId' => isset($row->LicenseCategoryId) ? (int) $row->LicenseCategoryId : null,
            'TierName' => (string) $row->TierName,
            'LedgerAction' => (string) $row->LedgerAction,
            'Delta' => (int) $row->Delta,
            'LicenseId' => (int) $row->LicenseId,
            'QuotaRequestId' => isset($row->QuotaRequestId) && $row->QuotaRequestId !== null ? (int) $row->QuotaRequestId : null,
            'RequestId' => (string) $row->RequestId,
            'ActorUserId' => (int) $row->ActorUserId,
            'CreatedAt' => (string) $row->CreatedAt,
        ];
    }
}
