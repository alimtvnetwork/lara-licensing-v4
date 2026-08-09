<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainConflictException;


use App\Exceptions\LaraException;
use RuntimeException;
use Throwable;

/**
 * Plan 16 step 58 (v0.563.0). Atomic read/write for repo-root `version.json`.
 *
 * Contract: spec/28-runtime-modes/01-version-json-schema.md +
 *           spec/28-runtime-modes/05-admin-runtime-toggle.md.
 *
 * Discipline:
 *  - PascalCase JSON keys (project memory Core rule).
 *  - Per-host file lock on `<path>.lock` guards the read-validate-write
 *    triple so parallel writers cannot skip the If-Match check (INV-RM-06).
 *  - Write goes to `<path>.tmp` + fsync + rename() so the file is never
 *    partial-observable to the SPA fetching `/version.json`.
 *  - 15-line function bodies enforced by `.lovable/coding-guidelines.md`.
 */
final class RuntimeConfigService
{
    public const CURRENT_VERSION_UNAVAILABLE = '0.0.0';
    private const MODE_PREVIEW = 'preview';
    private const MODE_DEV = 'dev';
    private const MODE_PRODUCTION = 'production';
    private const MUTABLE_KEYS = ['Mode', 'ApiBaseUrl', 'PreviewSeed', 'AllowRuntimeToggle'];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{Version:string, Mode:string, ApiBaseUrl:string|null, PreviewSeed:string, UpdatedAt:string, AllowRuntimeToggle:bool}
     */
    public function read(): array
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new RuntimeException("Runtime config file missing at '{$this->path}'.");
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            throw new RuntimeException('Runtime config JSON is not an object.');
        }

        return $this->normalize($decoded);
    }

    /**
     * Read + compute the strong ETag used by the admin toggle surface.
     *
     * @return array{Body: array<string,mixed>, ETag: string}
     */
    public function readForResponse(): array
    {
        $body = $this->read();

        return ['Body' => $body, 'ETag' => $this->etagFor($body['UpdatedAt'])];
    }

    /**
     * Atomically apply mutable-key changes. Callers MUST hold the caller-visible
     * If-Match check; we still re-verify under the lock (INV-RM-06 C-03).
     *
     * @param array<string,mixed> $incoming    Subset of MUTABLE_KEYS only.
     * @param string              $ifMatchEtag Strong ETag from the caller's last read.
     * @return array{Before: array<string,mixed>, After: array<string,mixed>, ChangedKeys: list<string>, ETag: string}
     */
    public function update(array $incoming, string $ifMatchEtag): array
    {
        $this->assertKeysMutable($incoming);

        return $this->withLock(function () use ($incoming, $ifMatchEtag): array {
            $before = $this->read();
            $this->assertNotLocked($before);
            $this->assertEtag($before['UpdatedAt'], $ifMatchEtag);
            $after = $this->composeAfter($before, $incoming);
            $this->assertModeInvariants($before, $after);
            $this->assertAllowToggleNotReenabled($before, $after);
            $this->writeAtomic($after);
            $changed = $this->diffKeys($before, $after);

            return ['Before' => $before, 'After' => $after, 'ChangedKeys' => $changed, 'ETag' => $this->etagFor($after['UpdatedAt'])];
        });
    }

    public function etagFor(string $updatedAt): string
    {
        return '"' . hash('sha256', $updatedAt) . '"';
    }

    /** @param array<string,mixed> $keys */
    private function assertKeysMutable(array $keys): void
    {
        foreach (array_keys($keys) as $k) {
            if (in_array($k, self::MUTABLE_KEYS, true) === false) {
                throw DomainConflictException::custom('RuntimeConfigInvalidField',
                    "Field '{$k}' is not mutable at runtime.",
                    [['Field' => (string) $k, 'Rule' => 'ImmutableAtRuntime']],
                )
            }
        }
    }

    /** @param array<string,mixed> $before */
    private function assertNotLocked(array $before): void
    {
        if ($before['AllowRuntimeToggle'] === false) {
            throw DomainConflictException::custom('RuntimeConfigLocked',
                'AllowRuntimeToggle is false; deploy a new version.json to unlock.',
                [['Field' => 'AllowRuntimeToggle', 'Rule' => 'Locked']],
            )
        }
    }

    private function assertEtag(string $updatedAt, string $ifMatchEtag): void
    {
        $current = $this->etagFor($updatedAt);
        if (hash_equals($current, $ifMatchEtag) === false) {
            throw DomainConflictException::custom('RuntimeConfigConflict',
                'version.json was modified since it was last read.',
                [['Field' => 'If-Match', 'Rule' => 'Stale', 'Value' => $current]],
            )
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $incoming
     * @return array{Version:string, Mode:string, ApiBaseUrl:string|null, PreviewSeed:string, UpdatedAt:string, AllowRuntimeToggle:bool}
     */
    private function composeAfter(array $before, array $incoming): array
    {
        $merged = array_replace($before, $incoming);
        $merged['UpdatedAt'] = gmdate('Y-m-d\TH:i:s\Z');

        return $this->normalize($merged);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assertModeInvariants(array $before, array $after): void
    {
        $mode = (string) $after['Mode'];
        if (in_array($mode, [self::MODE_PREVIEW, self::MODE_DEV, self::MODE_PRODUCTION], true) === false) {
            $this->throwMismatch('Mode', 'EnumInvalid');
        }
        $api = $after['ApiBaseUrl'];
        if ($mode === self::MODE_PREVIEW && $api !== null) {
            $this->throwMismatch('ApiBaseUrl', 'MustBeNullWhenPreview');
        }
        if ($mode !== self::MODE_PREVIEW && (!is_string($api) || $api === '')) {
            $this->throwMismatch('ApiBaseUrl', 'RequiredWhenNotPreview');
        }
        $this->assertProdToPreviewSafetyRail($before, $after);
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assertProdToPreviewSafetyRail(array $before, array $after): void
    {
        $wasProd = $before['Mode'] === self::MODE_PRODUCTION;
        $becomesPreview = $after['Mode'] === self::MODE_PREVIEW;
        if ($wasProd && $becomesPreview && !(bool) config('lara.runtime_config_allow_prod_to_preview', false)) {
            throw DomainConflictException::custom('RuntimeConfigForbidden',
                'Switching a production host to preview mode is disabled on this environment.',
                [['Field' => 'Mode', 'Rule' => 'ProdToPreviewDisabled']],
            )
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function assertAllowToggleNotReenabled(array $before, array $after): void
    {
        if ($before['AllowRuntimeToggle'] === false && $after['AllowRuntimeToggle'] === true) {
            throw DomainConflictException::custom('RuntimeConfigForbidden',
                'Re-enabling AllowRuntimeToggle requires a deploy, not a runtime write.',
                [['Field' => 'AllowRuntimeToggle', 'Rule' => 'ReenableViaDeployOnly']],
            )
        }
    }

    private function throwMismatch(string $field, string $rule): never
    {
        throw DomainConflictException::custom('RuntimeConfigModeMismatch',
            "Conditional field '{$field}' violates the Mode invariant.",
            [['Field' => $field, 'Rule' => $rule]],
        )
    }

    /**
     * @param array<string,mixed> $data
     * @return array{Version:string, Mode:string, ApiBaseUrl:string|null, PreviewSeed:string, UpdatedAt:string, AllowRuntimeToggle:bool}
     */
    private function normalize(array $data): array
    {
        return [
            'Version' => (string) ($data['Version'] ?? self::CURRENT_VERSION_UNAVAILABLE),
            'Mode' => (string) ($data['Mode'] ?? self::MODE_PREVIEW),
            'ApiBaseUrl' => isset($data['ApiBaseUrl']) && $data['ApiBaseUrl'] !== null ? (string) $data['ApiBaseUrl'] : null,
            'PreviewSeed' => (string) ($data['PreviewSeed'] ?? 'default'),
            'UpdatedAt' => (string) ($data['UpdatedAt'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'AllowRuntimeToggle' => (bool) ($data['AllowRuntimeToggle'] ?? true),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeAtomic(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $this->path . '.tmp';
        try {
            $bytes = @file_put_contents($tmp, $json, LOCK_EX);
            if ($bytes === false) {
                throw new RuntimeException("Failed to write tmp file '{$tmp}'.");
            }
            $this->fsyncPath($tmp);
            if (!@rename($tmp, $this->path)) {
                throw new RuntimeException("Failed to rename '{$tmp}' to '{$this->path}'.");
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            throw DomainConflictException::custom('RuntimeConfigWriteFailed', 'Failed to rewrite version.json.', [['Field' => 'FileSystem', 'Rule' => 'AtomicWriteFailed', 'Value' => $e->getMessage()]], $e);
        }
    }

    private function fsyncPath(string $path): void
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return;
        }
        try {
            if (function_exists('fsync')) {
                @fsync($h);
            }
        } finally {
            @fclose($h);
        }
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withLock(callable $fn): mixed
    {
        $lockPath = $this->path . '.lock';
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            throw new RuntimeException("Failed to open lock file '{$lockPath}'.");
        }
        try {
            if (flock($fh, LOCK_EX) === false) {
                throw new RuntimeException('Failed to acquire runtime-config exclusive lock.');
            }

            return $fn();
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return list<string>
     */
    private function diffKeys(array $before, array $after): array
    {
        $keys = [];
        foreach ($after as $k => $v) {
            if (!array_key_exists($k, $before) || $before[$k] !== $v) {
                $keys[] = (string) $k;
            }
        }

        return $keys;
    }
}
