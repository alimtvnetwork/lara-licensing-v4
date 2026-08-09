<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 36. Shard-scoped `QuotaRequests` row.
 *
 * Table lives on the reseller's shard per
 * spec/23-app-db/10-reseller-shard-split-db.md §App-tier tables.
 * Column set matches spec/23-app-db/01-schema.md §QuotaRequests and
 * the shard migration `2026_07_18_000004_create_shard_quota_requests_table.php`.
 * `Status` is enum-backed per SA-031 with an integer 1..4 range; the
 * mapping to `Pending|Approved|Denied|Cancelled` is owned by
 * spec/21-app/42-quota-requests.md §State machine.
 */
final class QuotaRequest extends Model
{
    protected $connection = 'shard';

    protected $table = 'QuotaRequests';

    protected $primaryKey = 'QuotaRequestId';

    public $timestamps = false;

    protected $fillable = [
        'ResellerId',
        'LicenseCategoryId',
        'LicenseTierId',
        'RequestedDelta',
        'ApprovedDelta',
        'Status',
        'Justification',
        'DenialReason',
        'SubmittedByUserId',
        'DecidedByUserId',
        'SubmittedAt',
        'DecidedAt',
        'RequestId',
        'IdempotencyKey',
    ];

    protected $casts = [
        'ResellerId' => 'integer',
        'LicenseCategoryId' => 'integer',
        'LicenseTierId' => 'integer',
        'RequestedDelta' => 'integer',
        'ApprovedDelta' => 'integer',
        'Status' => 'integer',
        'SubmittedByUserId' => 'integer',
        'DecidedByUserId' => 'integer',
        'SubmittedAt' => 'datetime',
        'DecidedAt' => 'datetime',
    ];
}
