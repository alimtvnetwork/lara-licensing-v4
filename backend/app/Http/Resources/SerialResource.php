<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\Serial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Serial wire shape for the Portal issuance endpoint
 * (`POST /Api/Portal/Serials`). Deliberately slim: never leaks device
 * fingerprint hashes, feature payload hashes, idempotency keys, or
 * last-verified timestamps to end-user devices (see
 * spec/21-app/11-api-contracts/03-verification-contracts.md and
 * AC-API-VER-011). Admin/inspector surfaces that need those fields
 * should introduce a dedicated Resource rather than widening this one.
 *
 * @mixin Serial
 */
final class SerialResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Serial $s */
        $s = $this->resource;

        return [
            'SerialId' => (int) $s->getAttribute('SerialId'),
            'LicenseId' => (int) $s->getAttribute('LicenseId'),
            'SerialValue' => (string) $s->getAttribute('SerialValue'),
            'EnvironmentName' => (string) $s->getAttribute('EnvironmentName'),
            'IsRevoked' => (bool) $s->getAttribute('IsRevoked'),
            'IssuedAt' => $this->isoOrEmpty($s->getAttribute('IssuedAt')),
        ];
    }
}

