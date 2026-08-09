<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 34. Root-DB UserRole assignment row.
 *
 * Backed by `UserRoles(UserRoleId, UserId, RoleId, CreatedAt)` with a
 * UNIQUE (UserId, RoleId) constraint per
 * `2026_07_18_000001_create_root_identity_tables.php`. RoleName joins
 * are performed via HasRolePolicy queries; this model does not eager
 * load the Roles catalog to keep queries cheap.
 */
final class UserRole extends Model
{
    protected $connection = 'root';
    protected $table = 'UserRoles';
    protected $primaryKey = 'UserRoleId';
    public $timestamps = false;

    protected $fillable = [
        'UserId',
        'RoleId',
    ];

    protected $casts = [
        'UserRoleId' => 'int',
        'UserId' => 'int',
        'RoleId' => 'int',
        'CreatedAt' => 'datetime',
    ];
}
