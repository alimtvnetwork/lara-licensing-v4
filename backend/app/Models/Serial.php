<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 38. Shard-DB Serial row.
 *
 * Backed by shard `Serials` table (see migration 000002). Idempotency
 * for `POST /Api/Portal/Serials` is enforced at the DB by
 * UNIQUE("LicenseId","DeviceIdHash") so a retry of the same
 * (license, device) hits the same row and never mints a fresh serial.
 */
final class Serial extends Model
{
    protected $connection = 'shard';
    protected $table = 'Serials';
    protected $primaryKey = 'SerialId';
    public $timestamps = false;

    protected $fillable = [
        'LicenseId',
        'SerialValue',
        'DeviceIdHash',
        'EnvironmentName',
        'FeaturePayloadHash',
        'IdempotencyKey',
        'IsRevoked',
        'IssuedAt',
        'LastVerifiedAt',
    ];

    protected $casts = [
        'SerialId' => 'int',
        'LicenseId' => 'int',
        'IsRevoked' => 'bool',
        'IssuedAt' => 'datetime',
        'LastVerifiedAt' => 'datetime',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
    ];
}
