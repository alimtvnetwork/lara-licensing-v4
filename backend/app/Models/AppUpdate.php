<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Root-scoped Eloquent model for `AppUpdates` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"Database bindings".
 * PascalCase column mapping is intentional and matches
 * spec/23-app-db/01-schema.md conventions.
 */
final class AppUpdate extends Model
{
    protected $connection = 'root';
    protected $table = 'AppUpdates';
    protected $primaryKey = 'AppUpdateId';
    public $timestamps = false;

    protected $fillable = [
        'Product',
        'Channel',
        'Version',
        'MinRequiredVersion',
        'ReleaseNotesUrl',
        'PublishedAt',
        'PublishedByUserId',
        'IsYanked',
        'YankedAt',
        'YankedByUserId',
    ];

    protected $casts = [
        'AppUpdateId' => 'integer',
        'PublishedByUserId' => 'integer',
        'YankedByUserId' => 'integer',
        'IsYanked' => 'integer',
        'PublishedAt' => 'datetime',
        'YankedAt' => 'datetime',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(AppUpdateAsset::class, 'AppUpdateId', 'AppUpdateId');
    }
}
