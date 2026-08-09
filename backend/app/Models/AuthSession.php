<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Root `AuthSessions` row (spec/21-app/46-impersonation.md §3).
 *
 * PascalCase table, PK, and column names are preserved on the wire and
 * in Eloquent to match spec/23-app-db/01-schema.md. Timestamps are
 * application-managed (see migration comment).
 *
 * @property string $SessionId              UUID
 * @property int    $UserId
 * @property string $Kind                   Normal | Impersonation | ServiceAccount
 * @property ?int   $ImpersonatorUserId
 * @property ?string $ParentSessionId       UUID
 * @property \Illuminate\Support\Carbon $CreatedAt
 * @property \Illuminate\Support\Carbon $ExpiresAt
 * @property ?\Illuminate\Support\Carbon $EndedAt
 * @property ?string $RevokeReason
 */
final class AuthSession extends Model
{
    public const KIND_NORMAL = 'Normal';
    public const KIND_IMPERSONATION = 'Impersonation';
    public const KIND_SERVICE_ACCOUNT = 'ServiceAccount';

    protected $connection = 'root';
    protected $table = 'AuthSessions';
    protected $primaryKey = 'SessionId';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'UserId' => 'integer',
        'ImpersonatorUserId' => 'integer',
        'CreatedAt' => 'datetime',
        'ExpiresAt' => 'datetime',
        'EndedAt' => 'datetime',
    ];

    public function isActive(): bool
    {
        if ($this->EndedAt !== null) {
            return false;
        }

        return $this->ExpiresAt > now();
    }
}
