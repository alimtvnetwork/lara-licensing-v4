<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Per-shard reachability row wire shape for
 * `GET /Api/Admin/Metrics/ShardStatus`. Backed by the array assembled
 * inline in `MetricsController::shardStatus`; keys and null handling
 * mirror that projection exactly (3 keys: `ResellerSlug`, `Reachable`,
 * nullable `Error`). The envelope-level `CheckedAt` and
 * `UnreachableCount` remain on `Attributes`, not on the row, per the
 * controller's existing contract.
 */
final class ShardStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;
        $error = $row['Error'] ?? null;

        return [
            'ResellerSlug' => (string) ($row['ResellerSlug'] ?? ''),
            'Reachable' => (bool) ($row['Reachable'] ?? false),
            'Error' => $error === null ? null : (string) $error,
        ];
    }
}
