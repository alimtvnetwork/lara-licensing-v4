<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Plan 06 step 34 + step 43 (login substrate). Root-DB user row.
 *
 * Backed by the Root `Users` table created in
 * `2026_07_18_000001_create_root_identity_tables.php`. Timestamps are
 * managed manually because column names are PascalCase.
 *
 * TenantId is the reseller anchor: NULL means Root-scope (SuperAdmin
 * or global Admin); non-NULL points at `Resellers.ResellerId`.
 */
final class User extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'root';
    protected $table = 'Users';
    protected $primaryKey = 'UserId';
    public $timestamps = false;

    protected $fillable = [
        'Email',
        'PasswordHash',
        'TenantId',
        'IsActive',
    ];

    protected $hidden = [
        'PasswordHash',
    ];

    protected $casts = [
        'UserId' => 'int',
        'TenantId' => 'int',
        'IsActive' => 'bool',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
        'DeletedAt' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->PasswordHash;
    }
}
