<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\Reseller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Root-DB Reseller wire shape.
 *
 * @mixin Reseller
 */
final class ResellerResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Reseller $r */
        $r = $this->resource;

        return [
            'ResellerId' => (int) $r->getAttribute('ResellerId'),
            'ResellerName' => (string) $r->getAttribute('ResellerName'),
            'ResellerSlug' => (string) $r->getAttribute('ResellerSlug'),
            'ContactEmail' => (string) ($r->getAttribute('ContactEmail') ?? ''),
            'IsActive' => (bool) $r->getAttribute('IsActive'),
            'CreatedAt' => $this->isoOrEmpty($r->getAttribute('CreatedAt')),
            'UpdatedAt' => $this->isoOrEmpty($r->getAttribute('UpdatedAt')),
        ];
    }
}
