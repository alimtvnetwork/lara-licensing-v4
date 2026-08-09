<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Admin dashboard KPI wire shape for
 * `GET /Api/Admin/Metrics`. Backed by the aggregate array assembled in
 * `Admin\MetricsController::index` (Root `Resellers` and `AuthSessions`
 * counts plus a shard fanout for `Licenses` and pending
 * `QuotaRequests`). Keys and casts mirror that payload exactly (5 keys:
 * ResellersActive, SessionsActive, LicensesTotal, QuotaRequestsPending,
 * GeneratedAt). Envelope-level `Warnings` stay on `Attributes`.
 */
final class MetricsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;

        return [
            'ResellersActive' => (int) ($row['ResellersActive'] ?? 0),
            'SessionsActive' => (int) ($row['SessionsActive'] ?? 0),
            'LicensesTotal' => (int) ($row['LicensesTotal'] ?? 0),
            'QuotaRequestsPending' => (int) ($row['QuotaRequestsPending'] ?? 0),
            'GeneratedAt' => (string) ($row['GeneratedAt'] ?? ''),
        ];
    }
}
