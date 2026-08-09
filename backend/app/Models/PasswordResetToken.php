<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Root DB row for a pending password reset request.
 *
 * @property int         $PasswordResetTokenId
 * @property string      $EmailLower
 * @property string      $TokenHash
 * @property \Carbon\CarbonImmutable $CreatedAt
 * @property \Carbon\CarbonImmutable $ExpiresAt
 * @property \Carbon\CarbonImmutable|null $ConsumedAt
 * @property string|null $RequestIp
 */
final class PasswordResetToken extends Model
{
    protected $connection = 'root';
    protected $table = 'PasswordResetTokens';
    protected $primaryKey = 'PasswordResetTokenId';
    public $timestamps = false;

    protected $fillable = ['EmailLower', 'TokenHash', 'ExpiresAt', 'ConsumedAt', 'RequestIp'];

    protected $casts = [
        'CreatedAt' => 'immutable_datetime',
        'ExpiresAt' => 'immutable_datetime',
        'ConsumedAt' => 'immutable_datetime',
    ];
}
