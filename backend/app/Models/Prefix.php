<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 30. Root-DB prefix registry row.
 *
 * Backed by `Prefixes` on the `root` connection created in
 * `2026_07_18_000003_create_root_prefixes_table.php`. PrefixValue is
 * globally UNIQUE (cross-tenant) per spec/23-app-db/10 §Root DB tables.
 */
final class Prefix extends Model
{
    protected $connection = 'root';
    protected $table = 'Prefixes';
    protected $primaryKey = 'PrefixId';
    public $timestamps = false;

    protected $fillable = [
        'ResellerId',
        'PrefixValue',
        'IsActive',
    ];

    protected $casts = [
        'PrefixId' => 'int',
        'ResellerId' => 'int',
        'IsActive' => 'bool',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'PrefixValue';
    }
}
