<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Exceptions\DomainConflictException;


use App\Exceptions\LaraException;
use App\Models\AppUpdate;
use App\Models\AppUpdateAsset;

/**
 * Plan 06 step 44. Self-update manifest resolver.
 *
 * Normative: spec/21-app/17-self-update-endpoint.md v1.3.0
 *   §"GET /App/UpdateManifest" (contract),
 *   §"Platform enum" (closed set),
 *   §"v1.0 rollout policy" (Stable pin),
 *   §"Publish state machine" (finalization filter),
 *   §"Errors" (UpdateChannelUnknown, UpdateVersionDowngradeBlocked,
 *              UpdateManifestUnavailable, ValidationInputInvalid).
 *
 * Root cause this service exists to close (one sentence): the CLI
 * self-update flow cannot bootstrap because there was no server-side
 * resolver returning the latest finalized non-yanked manifest row
 * for a `(Product, Channel, Platform)` triple, so every CLI call to
 * `GET /App/UpdateManifest` would have 404'd against a missing route
 * and blocked the entire v1.0 rollout.
 *
 * Semver ordering: SQL cannot rank semver natively, so we fetch a
 * bounded window of candidate rows already filtered on Product +
 * Channel + IsYanked=0 + Assets.IsFinalized=1 for the requested
 * Platform, then rank in PHP via `version_compare`. The candidate
 * window is capped at 50 rows: manifest writes are infrequent (one
 * per release), and Stable + Beta together are unlikely to accumulate
 * more than a few dozen live rows in a v1.0 lifetime; if they do,
 * the retention job for yanked rows keeps the visible slice small.
 */
final class SelfUpdateService
{
    private const CANDIDATE_WINDOW = 50;

    /**
     * @return array{Update: AppUpdate, Asset: AppUpdateAsset}|null
     *   Null when no manifest row satisfies the filter; caller maps
     *   to `UpdateManifestUnavailable` (503) or `UpdateAssetNotFound`
     *   depending on the query surface per spec §"Errors".
     */
    public function resolve(string $product, string $channel, string $currentVersion, string $platform): ?array
    {
        $this->assertClosedSets($product, $channel, $platform);
        $candidates = $this->fetchCandidates($product, $channel, $platform);
        if ($candidates === []) {
            return null;
        }
        $picked = $this->pickHighest($candidates);
        $this->assertNoDowngrade($picked['Update']->Version, $currentVersion);

        return $picked;
    }

    /**
     * Plan 06 step 71. Banner read path: highest finalized, non-yanked
     * manifest row for the triple, WITHOUT the downgrade assertion.
     *
     * `resolve()` throws `UpdateVersionDowngradeBlocked` whenever the
     * caller is already at or above the latest version, which is the
     * correct contract for the CLI but is not an error condition for a
     * banner: "already up to date" simply means "render nothing". The
     * caller compares versions itself and only surfaces a banner when
     * the installed version is strictly behind.
     *
     * @return array{Update: AppUpdate, Asset: AppUpdateAsset}|null
     */
    public function latestManifest(string $product, string $channel, string $platform): ?array
    {
        $this->assertClosedSets($product, $channel, $platform);
        $candidates = $this->fetchCandidates($product, $channel, $platform);
        if ($candidates === []) {
            return null;
        }

        return $this->pickHighest($candidates);
    }

    private function assertClosedSets(string $product, string $channel, string $platform): void
    {
        $this->assertProduct($product);
        $this->assertChannel($channel);
        $this->assertPlatform($platform);
    }

    private function assertProduct(string $product): void
    {
        $products = (array) config('lara.self_update.products', []);
        if (in_array($product, $products, true) === false) {
            throw ValidationException::custom('ValidationInputInvalid',
                'Product value is not in the closed enum.',
                [['Field' => 'Product', 'Rule' => 'ProductEnum']],
            )
        }
    }

    private function assertChannel(string $channel): void
    {
        $channels = (array) config('lara.self_update.channels', []);
        if (in_array($channel, $channels, true) === false) {
            throw ValidationException::custom('UpdateChannelUnknown',
                'Channel is not a member of the closed enum.',
                [['Field' => 'Channel', 'Rule' => 'ChannelEnum']],
            )
        }
    }

    private function assertPlatform(string $platform): void
    {
        $platforms = (array) config('lara.self_update.platforms', []);
        if (in_array($platform, $platforms, true) === false) {
            throw ValidationException::custom('ValidationInputInvalid',
                'Platform value is not in the closed enum.',
                [['Field' => 'Platform', 'Rule' => 'PlatformEnum']],
            )
        }
    }

    /**
     * @return array<int,array{Update: AppUpdate, Asset: AppUpdateAsset}>
     */
    private function fetchCandidates(string $product, string $channel, string $platform): array
    {
        $rows = AppUpdate::query()
            ->where('Product', $product)
            ->where('Channel', $channel)
            ->where('IsYanked', 0)
            ->orderByDesc('PublishedAt')
            ->limit(self::CANDIDATE_WINDOW)
            ->with(['assets' => function ($q) use ($platform): void {
                $q->where('Platform', $platform)->where('IsFinalized', 1);
            }])
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $asset = $row->assets->first();
            if ($asset instanceof AppUpdateAsset) {
                $out[] = ['Update' => $row, 'Asset' => $asset];
            }
        }

        return $out;
    }

    /**
     * @param array<int,array{Update: AppUpdate, Asset: AppUpdateAsset}> $candidates
     * @return array{Update: AppUpdate, Asset: AppUpdateAsset}
     */
    private function pickHighest(array $candidates): array
    {
        $highest = $candidates[0];
        foreach ($candidates as $c) {
            if (version_compare($c['Update']->Version, $highest['Update']->Version, '>')) {
                $highest = $c;
            }
        }

        return $highest;
    }

    private function assertNoDowngrade(string $latest, string $current): void
    {
        if ($current === '') {
            return;
        }
        if (version_compare($current, $latest, '>')) {
            throw DomainConflictException::custom('UpdateVersionDowngradeBlocked',
                'CurrentVersion is newer than the latest available manifest version.',
                [
                    ['Field' => 'CurrentVersion', 'Rule' => 'DowngradeBlocked'],
                    ['LatestVersion' => $latest, 'CurrentVersion' => $current],
                ],
            )
        }
    }
}
