<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Plan 06 step 43 (login substrate). Root-connected Sanctum token model.
 *
 * The token `name` column carries `AuthSessions.SessionId` (UUID) so that
 * downstream request handlers can look up the parent session row via
 * `request()->user()->currentAccessToken()->name`. Registered via
 * `Sanctum::usePersonalAccessTokenModel(...)` in AppServiceProvider.
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'root';
    protected $table = 'personal_access_tokens';
}
