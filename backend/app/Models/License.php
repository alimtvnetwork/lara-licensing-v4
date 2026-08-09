<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 31. Shard-DB license row.
 *
 * Backed by `Licenses` on the `shard` connection bound per-request by
 * `App\Db\ShardResolver::bind()`. Callers MUST bind the correct shard
 * BEFORE touching this model or queries will hit the previously bound
 * tenant. Never reference this model from Root-only code paths.
 */
final class License extends Model
{
    protected $connection = 'shard';
    protected $table = 'Licenses';
    protected $primaryKey = 'LicenseId';
    public $timestamps = false;

    protected $fillable = [
        'LicenseKey',
        'PrefixValue',
        'ResellerId',
        'IssuedByUserId',
        'IssuerActorType',
        'LicenseCategoryId',
        'TierName',
        'EnvironmentName',
        'ProductVersion',
        'Status',
        'IssuedAt',
        'ExpiresAt',
        'Version',
        'RevokedAt',
        'RevokedByUserId',
        'RevokeReason',
        'ResellerQuotaLedgerId',
    ];


    protected $casts = [
        'LicenseId' => 'int',
        'ResellerId' => 'int',
        'IssuedByUserId' => 'int',
        'LicenseCategoryId' => 'int',
        'RevokedByUserId' => 'int',

        'ResellerQuotaLedgerId' => 'int',
        'Version' => 'int',
        'IssuedAt' => 'datetime',
        'ExpiresAt' => 'datetime',
        'RevokedAt' => 'datetime',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
        'DeletedAt' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'LicenseKey';
    }
}
