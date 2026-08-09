<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 39 (substrate). Shard-DB `UserBindings` row.
 *
 * One row per (License, UserIdentifier) active pair. Enforces the
 * `UserCount` cap from `spec/21-app/06-license-variations.md` line 26
 * and produces the `LicenseUserLimit` 409 path in
 * `spec/21-app/11-api-contracts/03-verification-contracts.md` line 53.
 * Callers MUST bind the correct shard via
 * `App\Db\ShardResolver::bind()` BEFORE querying this model.
 *
 * `IsReleased` and `ReleasedAt` MUST be set together (shard CHECK
 * `CkUserBindingReleasedConsistency`); a mismatched pair is a bug and
 * fails at the database layer rather than corrupting the count.
 */
final class UserBinding extends Model
{
    protected $connection = 'shard';
    protected $table = 'UserBindings';
    protected $primaryKey = 'UserBindingId';
    public $timestamps = false;

    protected $fillable = [
        'LicenseId',
        'UserIdentifier',
        'FirstSeenAt',
        'LastSeenAt',
        'IsReleased',
        'ReleasedAt',
        'CreatedAt',
        'UpdatedAt',
    ];

    protected $casts = [
        'LicenseId' => 'int',
        'IsReleased' => 'bool',
        'FirstSeenAt' => 'datetime',
        'LastSeenAt' => 'datetime',
        'ReleasedAt' => 'datetime',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
    ];

    /**
     * `true` when this row counts against `UserCount`. Kept as an
     * explicit accessor so the quota predicate lives in one place.
     */
    public function isActive(): bool
    {
        return $this->IsReleased === false;
    }
}
