<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Quota;

/**
 * Plan 06 step 80.
 *
 * Single projection of the shard-bound `Quotas` rows consumed by the reseller
 * dashboard tiles and by the quota-request submit form's client preflight
 * (resources/js/lib/quotaPreflight.ts). `LicensesRemaining` is derived here and
 * never persisted, per spec/21-app/41-reseller-quotas.md §2, so both surfaces
 * cannot disagree about headroom.
 */
final class ResellerQuotaProjection
{
    /**
     * @return list<array<string, int|string|null>>
     */
    public static function forReseller(int $resellerId): array
    {
        return Quota::query()
            ->where('ResellerId', $resellerId)
            ->orderBy('LicenseCategoryId')
            ->orderBy('LicenseTierId')
            ->get()
            ->map(static fn (Quota $q): array => [
                'LicenseCategoryId' => (int) $q->LicenseCategoryId,
                'LicenseTierId' => (int) $q->LicenseTierId,
                'LicensesGranted' => (int) $q->LicensesGranted,
                'LicensesConsumed' => (int) $q->LicensesConsumed,
                'LicensesRemaining' => (int) $q->LicensesGranted - (int) $q->LicensesConsumed,
                'PeriodStart' => $q->PeriodStart === null ? null : (string) $q->PeriodStart,
                'PeriodEnd' => $q->PeriodEnd === null ? null : (string) $q->PeriodEnd,
            ])
            ->all();
    }
}
