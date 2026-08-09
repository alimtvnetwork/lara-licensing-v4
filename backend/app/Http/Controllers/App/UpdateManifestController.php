<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use App\Services\SelfUpdateService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plan 06 steps 44 + 45. `GET /App/UpdateManifest` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0.
 *
 * v1.0 rollout policy (spec §"v1.0 rollout policy"): Stable channel is
 * anonymous. Beta enum is reserved but the auth gate returns
 * `AuthzRoleDenied` (403) until the AppBuilder|Admin role check lands
 * with the frontend Beta toggle (post-v1.0).
 *
 * Cache-Control: Stable responses carry `public, max-age=30` per spec
 * §"Response 200". Beta MUST carry `no-store`; not exercised in v1.0.
 */
final class UpdateManifestController
{
    public function __construct(
        private readonly SelfUpdateService $service,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $q = $this->validateQuery($request);
        $this->assertChannelAuth($q['Channel']);
        $picked = $this->service->resolve($q['Product'], $q['Channel'], $q['CurrentVersion'], $q['Platform']);
        if ($picked === null) {
            throw InternalException::custom('UpdateManifestUnavailable',
                'No finalized manifest is available for the requested product/channel/platform.',
                [['Field' => 'Manifest', 'Rule' => 'NoFinalizedRow']],
            )
        }

        return $this->respond($request, $picked, $q['Channel']);
    }

    /**
     * @return array{Product:string,Channel:string,CurrentVersion:string,Platform:string}
     */
    private function validateQuery(Request $request): array
    {
        $product = trim((string) $request->query('Product', ''));
        $channel = trim((string) $request->query('Channel', ''));
        $current = trim((string) $request->query('CurrentVersion', ''));
        $platform = trim((string) $request->query('Platform', ''));
        $this->assertNonEmpty('Product', $product);
        $this->assertNonEmpty('Channel', $channel);
        $this->assertNonEmpty('CurrentVersion', $current);
        $this->assertNonEmpty('Platform', $platform);
        $this->assertSemver('CurrentVersion', $current);

        return [
            'Product' => $product,
            'Channel' => $channel,
            'CurrentVersion' => $current,
            'Platform' => $platform,
        ];
    }

    private function assertNonEmpty(string $field, string $value): void
    {
        if ($value === '') {
            throw ValidationException::custom('ValidationInputInvalid',
                sprintf('Query parameter %s is required.', $field),
                [['Field' => $field, 'Rule' => 'Required']],
            )
        }
    }

    private function assertSemver(string $field, string $value): void
    {
        // Loose semver: MAJOR.MINOR.PATCH with optional pre-release/build.
        if (preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.\-]+)?$/', $value) !== 1) {
            throw ValidationException::custom('ValidationInvalidVersion',
                'CurrentVersion is not a valid semver value.',
                [['Field' => $field, 'Rule' => 'SemverFormat']],
            )
        }
    }

    private function assertChannelAuth(string $channel): void
    {
        if ($channel === 'Stable') {
            return;
        }
        // Beta reserved for post-v1.0. Auth gate lives here so switching
        // channels never crosses this line without a role check landing.
        throw AuthException::custom('AuthzRoleDenied',
            'Beta channel requires AppBuilder or Admin role (reserved post-v1.0).',
            [['Field' => 'Channel', 'Rule' => 'BetaRoleGate']],
        )
    }

    /**
     * @param array{Update: \App\Models\AppUpdate, Asset: \App\Models\AppUpdateAsset} $picked
     */
    private function respond(Request $request, array $picked, string $channel): JsonResponse
    {
        $update = $picked['Update'];
        $asset = $picked['Asset'];
        $result = [
            'Product' => (string) $update->Product,
            'Channel' => (string) $update->Channel,
            'LatestVersion' => (string) $update->Version,
            'MinRequiredVersion' => (string) $update->MinRequiredVersion,
            'PublishedAt' => $update->PublishedAt?->format('Y-m-d\TH:i:s\Z'),
            'Assets' => [[
                'Platform' => (string) $asset->Platform,
                'Url' => sprintf('/App/UpdateAsset/%s/%s', $update->Version, $asset->Platform),
                'SizeBytes' => (int) $asset->SizeBytes,
                'Sha256' => (string) $asset->Sha256,
                'SignatureUrl' => $asset->SignatureSha256 === null
                    ? null
                    : sprintf('/App/UpdateAsset/%s/%s.sig', $update->Version, $asset->Platform),
            ]],
            'ReleaseNotesUrl' => $update->ReleaseNotesUrl,
        ];
        $requestId = (string) ($request->attributes->get('lara.request_id') ?? '');
        $response = ApiEnvelope::success([$result], $requestId);
        $maxAge = (int) config('lara.self_update.manifest_cache_max_age_seconds', 30);
        $cacheControl = $channel === 'Stable' ? sprintf('public, max-age=%d', $maxAge) : 'no-store';
        $response->headers->set('Cache-Control', $cacheControl);

        return $response;
    }
}
