<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Root-scoped Eloquent model for `AppUpdateAssets` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"Publish state machine".
 * `IsFinalized = 0` rows are invisible to the read path per spec §"Rule".
 */
final class AppUpdateAsset extends Model
{
    protected $connection = 'root';
    protected $table = 'AppUpdateAssets';
    protected $primaryKey = 'AppUpdateAssetId';
    public $timestamps = false;

    protected $fillable = [
        'AppUpdateId',
        'Product',
        'Version',
        'Platform',
        'SizeBytes',
        'Sha256',
        'SignatureSha256',
        'StoragePath',
        'SignatureStoragePath',
        'IsFinalized',
        'UploadToken',
        'UploadTicketExpiresAt',
        'CreatedAt',
        'FinalizedAt',
    ];

    protected $casts = [
        'AppUpdateAssetId' => 'integer',
        'AppUpdateId' => 'integer',
        'SizeBytes' => 'integer',
        'IsFinalized' => 'integer',
        'CreatedAt' => 'datetime',
        'FinalizedAt' => 'datetime',
        'UploadTicketExpiresAt' => 'datetime',
    ];

    public function update_(): BelongsTo
    {
        return $this->belongsTo(AppUpdate::class, 'AppUpdateId', 'AppUpdateId');
    }
}
