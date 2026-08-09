<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrManifestSchema;
use App\Exceptions\LaraException;

/**
 * Plan 14 step 13. Thin wrapper over PHP ext-zstd.
 *
 * Spec 26 §08 INV-BR-AF-2 (per-entry zstd, no whole-archive .tar.zst)
 * requires every archive body chunk to be zstd-compressed. This wrapper
 * is the ONLY place the extension is called so a missing binding
 * surfaces `BackupZstdUnavailable` (500) at boot instead of a stray
 * "call to undefined function" at chunk time.
 *
 * Level is pinned to `BrManifestSchema::CHUNK_LEVEL` (19) so
 * `manifest.chunkIndex.level` and the actual writer stay in lockstep;
 * a spec drift is a config diff, not a code fork.
 *
 * 15-line function cap held.
 */
final class BrZstd
{
    private const ERR_UNAVAILABLE = 'BackupZstdUnavailable';
    private const REASON_EXT_MISSING = 'php.ext-zstd.not-loaded';
    private const REASON_COMPRESS_FAILED = 'php.ext-zstd.compress.returned-false';
    private const REASON_DECOMPRESS_FAILED = 'php.ext-zstd.uncompress.returned-false';
    private const FN_COMPRESS = 'zstd_compress';
    private const FN_DECOMPRESS = 'zstd_uncompress';

    public function isAvailable(): bool
    {
        return function_exists(self::FN_COMPRESS);
    }

    public function compress(string $payload): string
    {
        if (! $this->isAvailable()) {
            throw InternalException::custom(self::ERR_UNAVAILABLE, 'PHP ext-zstd is not loaded on this host.', [
                ['Field' => 'runtime.extension.zstd', 'Rule' => self::REASON_EXT_MISSING],
            ]);
        }
        /** @var callable(string,int):(string|false) $fn */
        $fn = self::FN_COMPRESS;
        $out = $fn($payload, BrManifestSchema::CHUNK_LEVEL);
        if ($out === false) {
            throw InternalException::custom(self::ERR_UNAVAILABLE, 'zstd_compress returned false.', [
                ['Field' => 'runtime.extension.zstd', 'Rule' => self::REASON_COMPRESS_FAILED],
            ]);
        }

        return $out;
    }

    public function decompress(string $sealed): string
    {
        if (function_exists(self::FN_DECOMPRESS) === false) {
            throw InternalException::custom(self::ERR_UNAVAILABLE, 'PHP ext-zstd is not loaded on this host.', [
                ['Field' => 'runtime.extension.zstd', 'Rule' => self::REASON_EXT_MISSING],
            ]);
        }
        /** @var callable(string):(string|false) $fn */
        $fn = self::FN_DECOMPRESS;
        $out = $fn($sealed);
        if ($out === false) {
            throw InternalException::custom(self::ERR_UNAVAILABLE, 'zstd_uncompress returned false.', [
                ['Field' => 'runtime.extension.zstd', 'Rule' => self::REASON_DECOMPRESS_FAILED],
            ]);
        }

        return $out;
    }
}
