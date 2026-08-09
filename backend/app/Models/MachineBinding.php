<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 39 (substrate). Shard-DB `MachineBindings` row.
 *
 * Persists ONLY the canonical `FingerprintHash` per AC-MB-001 in
 * `spec/21-app/30-machine-bindings.md`. Raw MAC / motherboard / CPUID /
 * MachineGuid MUST NEVER be assigned here or serialized to JSON.
 * Callers MUST bind the correct shard via `App\Db\ShardResolver::bind()`
 * BEFORE querying this model.
 *
 * `ReleasedAt` and `RebindCooldownUntil` MUST be set together in one
 * UPDATE (AC-MB-005); the shard-level CHECK constraint enforces the
 * pair invariant so a partial write becomes a database error rather
 * than a silent audit gap.
 */
final class MachineBinding extends Model
{
    protected $connection = 'shard';
    protected $table = 'MachineBindings';
    protected $primaryKey = 'MachineBindingId';
    public $timestamps = false;

    protected $fillable = [
        'LicenseId',
        'FingerprintHash',
        'FirstSeenAt',
        'LastSeenAt',
        'ReleasedAt',
        'RebindCooldownUntil',
        'CreatedAt',
        'UpdatedAt',
    ];

    protected $casts = [
        'LicenseId' => 'int',
        'FirstSeenAt' => 'datetime',
        'LastSeenAt' => 'datetime',
        'ReleasedAt' => 'datetime',
        'RebindCooldownUntil' => 'datetime',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
    ];

    /**
     * `true` when this row counts against the concurrent-binding quota
     * per AC-MB-003 (`COUNT(*) WHERE LicenseId = ? AND ReleasedAt IS NULL`).
     * Kept as an explicit accessor so quota logic never open-codes the
     * predicate and drifts from the spec.
     */
    public function isActive(): bool
    {
        return $this->ReleasedAt === null;
    }
}
