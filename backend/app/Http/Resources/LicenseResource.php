<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Wire shape for a License row. Mirrors
 * `Admin\LicenseController::project()` (lines 237-257) byte-for-byte so
 * existing Pest expectations and clients caching by `Version` (ETag) still
 * see the same envelope. Features are supplied via `additional(['Features' => ...])`
 * by the caller when the LicenseFeatures join is available.
 *
 * @mixin License
 */
final class LicenseResource extends JsonResource
{
    use FormatsPascalCase;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var License $license */
        $license = $this->resource;

        return [
            'LicenseId' => (int) $license->getAttribute('LicenseId'),
            'LicenseKey' => (string) $license->getAttribute('LicenseKey'),
            'PrefixValue' => (string) $license->getAttribute('PrefixValue'),
            'ResellerId' => (int) $license->getAttribute('ResellerId'),
            'IssuedByUserId' => (int) $license->getAttribute('IssuedByUserId'),
            'IssuerActorType' => (string) ($license->getAttribute('IssuerActorType') ?? 'Admin'),
            'TierName' => (string) $license->getAttribute('TierName'),
            'EnvironmentName' => (string) $license->getAttribute('EnvironmentName'),
            'ProductVersion' => (string) $license->getAttribute('ProductVersion'),
            'Status' => (string) $license->getAttribute('Status'),
            'IssuedAt' => $this->isoOrEmpty($license->getAttribute('IssuedAt')),
            'ExpiresAt' => $this->isoOrEmpty($license->getAttribute('ExpiresAt')),
            'RevokedAt' => $this->isoOrEmpty($license->getAttribute('RevokedAt')),
            'RevokeReason' => (string) ($license->getAttribute('RevokeReason') ?? ''),
            'Version' => (int) $license->getAttribute('Version'),
            'Features' => (object) ($this->additional['Features'] ?? []),
        ];
    }
}
