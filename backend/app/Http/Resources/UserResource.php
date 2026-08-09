<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Root-DB User wire shape. PasswordHash is stripped by
 * the model's `$hidden` list; roles are attached via
 * `additional(['Roles' => [...]])` by callers that resolved them from
 * `HasRolePolicy`/UserRoles catalog.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $u */
        $u = $this->resource;

        return [
            'UserId' => (int) $u->getAttribute('UserId'),
            'Email' => (string) $u->getAttribute('Email'),
            'TenantId' => $u->getAttribute('TenantId') === null
                ? null
                : (int) $u->getAttribute('TenantId'),
            'IsActive' => (bool) $u->getAttribute('IsActive'),
            'CreatedAt' => $this->isoOrEmpty($u->getAttribute('CreatedAt')),
            'UpdatedAt' => $this->isoOrEmpty($u->getAttribute('UpdatedAt')),
        ];
    }
}
