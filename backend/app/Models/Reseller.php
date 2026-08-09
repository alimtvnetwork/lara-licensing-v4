<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan 06 step 29. Root-DB reseller directory row.
 *
 * Backed by the `Resellers` table on the `root` connection created in
 * `2026_07_18_000002_create_root_reseller_tables.php`. Timestamps are
 * managed manually because the column names are PascalCase
 * (CreatedAt / UpdatedAt) and Laravel's default snake_case timestamp
 * columns would not match.
 */
final class Reseller extends Model
{
    protected $connection = 'root';
    protected $table = 'Resellers';
    protected $primaryKey = 'ResellerId';
    public $timestamps = false;

    protected $fillable = [
        'ResellerName',
        'ResellerSlug',
        'ContactEmail',
        'IsActive',
    ];

    protected $casts = [
        'ResellerId' => 'int',
        'IsActive' => 'bool',
        'CreatedAt' => 'datetime',
        'UpdatedAt' => 'datetime',
        'DeletedAt' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'ResellerSlug';
    }
}
