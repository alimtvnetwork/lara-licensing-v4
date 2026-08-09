<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 39b. Shard-DB `VerifyKeys` row.
 *
 * Server-issued nonce with single-use, 5-minute expiry, and SHA-256
 * HashKey digest binding per spec/21-app/09-verify-key.md §Generation
 * and migration 000010. Callers MUST bind the correct shard via
 * `App\Db\ShardResolver::bind()` BEFORE querying this model. The raw
 * `HashKey` is NEVER persisted; only `HashKeyDigest` is stored so
 * `Verify/Final` can perform a constant-time compare per AC-API-VER-001.
 */
final class VerifyKey extends Model
{
    protected $connection = 'shard';
    protected $table = 'VerifyKeys';
    protected $primaryKey = 'VerifyKeyId';
    public $timestamps = false;

    protected $fillable = [
        'LicenseId',
        'SerialId',
        'HashKeyDigest',
        'VerifyKeyValue',
        'IssuedAt',
        'ExpiresAt',
        'IsConsumed',
        'ConsumedAt',
        'RequestId',
        'CreatedAt',
        'UpdatedAt',
    ];

    protected $casts = [
        'LicenseId' => 'int',
        'SerialId' => 'int',
        'IsConsumed' => 'bool',
        'IssuedAt' => 'datetime',
        'ExpiresAt' => 'datetime',
        'ConsumedAt' => 'datetime',
    ];
}
