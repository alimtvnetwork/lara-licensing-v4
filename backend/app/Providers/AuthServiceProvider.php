<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AppUpdate;
use App\Models\AuthSession;
use App\Models\License;
use App\Models\Prefix;
use App\Models\QuotaRequest;
use App\Models\Reseller;
use App\Models\User;
use App\Policies\AppUpdatePolicy;
use App\Policies\AuthSessionPolicy;
use App\Policies\LicensePolicy;
use App\Policies\PrefixPolicy;
use App\Policies\QuotaRequestPolicy;
use App\Policies\ResellerPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

/**
 * Plan 10 step 3. Wires model Policies through Laravel's Gate registry.
 *
 * Each Policy delegates to `HasRolePolicy` (Root.UserRoles/Roles catalog)
 * per spec/21-app/04-roles.md; no role names live on Profiles. Controllers
 * still enforce coarse-grained access via route middleware; these Policies
 * provide the fine-grained per-model gates (`$user->can('revoke', $license)`).
 */
final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        License::class => LicensePolicy::class,
        Reseller::class => ResellerPolicy::class,
        User::class => UserPolicy::class,
        QuotaRequest::class => QuotaRequestPolicy::class,
        AppUpdate::class => AppUpdatePolicy::class,
        Prefix::class => PrefixPolicy::class,
        AuthSession::class => AuthSessionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
