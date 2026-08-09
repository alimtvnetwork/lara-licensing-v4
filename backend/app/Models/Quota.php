<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 37 pre-req. Shard-scoped `Quotas` row (aka `ResellerQuotas`).
 *
 * Composite PK is `(ResellerId, LicenseCategoryId, LicenseTierId, PeriodStart)`.
 * Eloquent's default single-key routing is not used; callers must always
 * scope queries with the full tuple. `LicensesRemaining` is computed by the
 * caller (`LicensesGranted - LicensesConsumed`) per spec 41 §2 and is never
 * persisted.
 *
 * Normative source: spec/21-app/41-reseller-quotas.md v1.0.0.
 */
final class Quota extends Model
{
    protected $connection = 'shard';

    protected $table = 'Quotas';

    /** Composite PK; Eloquent single-PK helpers must not be used. */
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'ResellerId',
        'LicenseCategoryId',
        'LicenseTierId',
        'LicensesGranted',
        'LicensesConsumed',
        'PeriodStart',
        'PeriodEnd',
    ];

    protected $casts = [
        'ResellerId' => 'integer',
        'LicenseCategoryId' => 'integer',
        'LicenseTierId' => 'integer',
        'LicensesGranted' => 'integer',
        'LicensesConsumed' => 'integer',
        'PeriodStart' => 'datetime',
        'PeriodEnd' => 'datetime',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
    ];
}
