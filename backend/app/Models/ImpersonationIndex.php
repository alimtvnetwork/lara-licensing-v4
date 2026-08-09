<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Root `ImpersonationIndex` row (spec/21-app/46-impersonation.md §4.3.5).
 *
 * @property string $SessionId              UUID (shared with AuthSessions.SessionId)
 * @property int    $ImpersonatorUserId
 * @property int    $TargetUserId
 * @property ?int   $TargetResellerId       NULL for Root-scoped targets
 * @property \Illuminate\Support\Carbon $StartedAt
 * @property ?\Illuminate\Support\Carbon $EndedAt
 * @property ?string $EndReason
 */
final class ImpersonationIndex extends Model
{
    public const END_OPERATOR = 'OperatorEnded';
    public const END_TIMEOUT = 'Timeout';
    public const END_ADMIN_FORCED = 'AdminForced';

    protected $connection = 'root';
    protected $table = 'ImpersonationIndex';
    protected $primaryKey = 'SessionId';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'ImpersonatorUserId' => 'integer',
        'TargetUserId' => 'integer',
        'TargetResellerId' => 'integer',
        'StartedAt' => 'datetime',
        'EndedAt' => 'datetime',
    ];
}
