<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\Prefix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Prefix catalog wire shape.
 *
 * @mixin Prefix
 */
final class PrefixResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Prefix $p */
        $p = $this->resource;

        return [
            'PrefixId' => (int) $p->getAttribute('PrefixId'),
            'PrefixValue' => (string) $p->getAttribute('PrefixValue'),
            'ResellerId' => (int) $p->getAttribute('ResellerId'),
            'IsActive' => (bool) $p->getAttribute('IsActive'),
            'CreatedAt' => $this->isoOrEmpty($p->getAttribute('CreatedAt')),
            'UpdatedAt' => $this->isoOrEmpty($p->getAttribute('UpdatedAt')),
        ];
    }
}

