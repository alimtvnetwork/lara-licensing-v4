<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\AppUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4 (wave 4). AppUpdate admin-list wire shape.
 *
 * Mirrors Admin\AppUpdateController::index() (lines 84-101) byte-for-byte:
 * PublishedAt/YankedAt are ISO-8601 or literal null (no empty-string
 * collapse), ReleaseNotesUrl is passed through unchanged (null|string),
 * IsYanked is coerced to bool via the `=== 1` idiom, and Assets are
 * projected by the caller and attached via
 * `additional(['Assets' => [...]])`. Note: YankedByUserId is intentionally
 * omitted to match the controller's existing shape; publish/yank
 * responses use a different downstream-facing envelope built inline.
 *
 * @mixin AppUpdate
 */
final class AppUpdateResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AppUpdate $u */
        $u = $this->resource;

        return [
            'AppUpdateId' => (int) $u->getAttribute('AppUpdateId'),
            'Product' => (string) $u->getAttribute('Product'),
            'Channel' => (string) $u->getAttribute('Channel'),
            'Version' => (string) $u->getAttribute('Version'),
            'MinRequiredVersion' => (string) $u->getAttribute('MinRequiredVersion'),
            'ReleaseNotesUrl' => $u->getAttribute('ReleaseNotesUrl'),
            'PublishedAt' => $this->isoOrNull($u->getAttribute('PublishedAt')),
            'PublishedByUserId' => (int) $u->getAttribute('PublishedByUserId'),
            'IsYanked' => (int) $u->getAttribute('IsYanked') === 1,
            'YankedAt' => $this->isoOrNull($u->getAttribute('YankedAt')),
            'Assets' => array_values((array) ($this->additional['Assets'] ?? [])),
        ];
    }
}
