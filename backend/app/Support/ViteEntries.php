<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plan 06 step 79.
 *
 * The Blade root eagerly preloads the current Inertia page chunk so the first
 * paint does not wait on a second round trip. In production that tag is only
 * legal when the chunk exists in public/build/manifest.json; asking @vite() for
 * a missing key throws ViteManifestNotFoundException / "Unable to locate file in
 * Vite manifest" and blanks the console. This resolves the entry list defensively.
 */
final class ViteEntries
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $manifest = null;

    public const BASE = ['resources/css/app.css', 'resources/js/app.tsx'];

    /**
     * @return list<string>
     */
    public static function forPage(?string $component): array
    {
        $entries = self::BASE;

        if ($component === null || $component === '') {
            return $entries;
        }

        $pageEntry = 'resources/js/Pages/'.$component.'.tsx';

        if (self::isHot() || self::manifestHas($pageEntry)) {
            $entries[] = $pageEntry;
        }

        return $entries;
    }

    private static function isHot(): bool
    {
        return is_file(public_path('hot'));
    }

    private static function manifestHas(string $entry): bool
    {
        if (self::$manifest === null) {
            $path = public_path('build/manifest.json');

            if (is_file($path) === false) {
                self::$manifest = [];
            } else {
                $decoded = json_decode((string) file_get_contents($path), true);
                self::$manifest = is_array($decoded) ? $decoded : [];
            }
        }

        return array_key_exists($entry, self::$manifest);
    }

    public static function flushCache(): void
    {
        self::$manifest = null;
    }
}
