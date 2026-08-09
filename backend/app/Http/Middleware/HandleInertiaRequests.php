<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use App\Services\SelfUpdateService;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $role = $user ? $this->effectiveRole((int) $user->UserId) : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'UserId' => $user->UserId,
                    'Email' => $user->Email,
                    'DisplayName' => $user->DisplayName ?? $user->Email,
                    'RoleName' => $role,
                    'TenantId' => $user->TenantId,
                ] : null,
            ],
            'lara' => [
                'version' => config('app.version', '0.0.0'),
            ],
            'update' => $this->updateBanner($role),
            'flash' => [
                'message' => $request->session()->get('message'),
            ],
        ]);
    }

    /**
     * Plan 06 step 71. Server-resolved update banner payload.
     *
     * Root cause this closes: `GET /Api/App/UpdateManifest` had no console
     * consumer, so an operator on an outdated desktop client saw no signal
     * anywhere in the web UI. Resolution happens here (server truth); the
     * banner component receives a plain, already-compared payload and never
     * fetches the manifest, so `Sha256` and the detached signature stay off
     * the browser trust path entirely.
     *
     * Renders only for the `EndUser` shell per spec/21-app/16-ui-surfaces.md
     * §3a ("Never renders for Admin or Reseller shells"), which also keeps
     * the Root `AppUpdates` query off every Admin/Reseller page render.
     * Channel is hard-pinned to `Stable` per spec 17 §"v1.0 rollout policy".
     *
     * @return array{LatestVersion:string,CurrentVersion:string,ReleaseNotesUrl:?string,ViewUpdateHref:string}|null
     */
    private function updateBanner(?string $role): ?array
    {
        if ($role !== 'EndUser') {
            return null;
        }
        $current = (string) config('lara.self_update.installed_client_version', '0.0.0');
        $picked = app(SelfUpdateService::class)->latestManifest(
            (string) config('lara.self_update.banner_product', 'lara-cli'),
            (string) config('lara.self_update.channel', 'Stable'),
            (string) config('lara.self_update.banner_platform', 'WindowsAmd64'),
        );
        if ($picked === null) {
            return null;
        }
        $latest = (string) $picked['Update']->Version;
        if (version_compare($latest, $current, '<=')) {
            return null;
        }

        return [
            'LatestVersion' => $latest,
            'CurrentVersion' => $current,
            'ReleaseNotesUrl' => $picked['Update']->ReleaseNotesUrl,
            'ViewUpdateHref' => (string) config('lara.self_update.banner_view_update_href', '/app/update'),
        ];
    }

    /**
     * Plan 06 step 68. Effective role for shell/nav decisions.
     *
     * Root cause this replaces: `$user->RoleName` does not exist on the
     * `Users` row (roles live in Root `UserRoles`/`Roles`, see
     * Admin\UserController::listRoles), so every Inertia page received
     * RoleName='User' and `navTreeForRole` returned only the Account
     * items. Precedence mirrors spec/21-app/40-permissions.md: the
     * highest-privilege grant wins.
     *
     * @return string
     */
    private function effectiveRole(int $userId): string
    {
        $names = DB::connection('root')
            ->table('UserRoles as ur')
            ->join('Roles as r', 'ur.RoleId', '=', 'r.RoleId')
            ->where('ur.UserId', $userId)
            ->pluck('r.RoleName')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        foreach (['SuperAdmin', 'Admin', 'Reseller', 'Support', 'Auditor'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return 'EndUser';
    }
}
