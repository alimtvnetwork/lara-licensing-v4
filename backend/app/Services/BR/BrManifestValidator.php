<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Domain\BR\BrArchiveKind;
use App\Domain\BR\BrManifestSchema;
use App\Domain\BR\BrProducedByRole;
use App\Exceptions\InternalException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 7. BR archive manifest validator.
 *
 * Normative source: spec/26-backup-restore/07-manifest-schema.md v1.0.0
 * §"JSON Schema" + §"Validation Contract" (six semantic checks) +
 * INV-BR-MS-1..5. Validation runs BEFORE any DB tx opens or any
 * chunk body is read (INV-BR-MS-1).
 *
 * Failure contract: throws `LaraException('BackupCorrupt')` whose
 * details carry the JSON pointer of the first failing keyword.
 * `appVersion` major mismatch on Export surfaces the distinct
 * `BackupVersionMismatch` code, matching spec §"Validation Contract"
 * step 5. Every failure logs `br.manifest.invalid` with `RequestId`,
 * `Pointer`, `Rule` before rethrow; success logs `br.manifest.ok`.
 *
 * Function bodies capped at 15 lines. No magic strings: every
 * literal is a `private const` here or a constant on
 * `BrManifestSchema`.
 */
final class BrManifestValidator
{
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const ERR_VERSION = 'BackupVersionMismatch';
    private const RULE_MISSING = 'MissingRequired';
    private const RULE_EXTRA   = 'ExtraProperty';
    private const RULE_PATTERN = 'PatternMismatch';
    private const RULE_CONST   = 'ConstMismatch';
    private const RULE_TYPE    = 'TypeMismatch';
    private const RULE_MERKLE  = 'MerkleRootMismatch';
    private const RULE_DISJOINT = 'ExcludedIntersectsDomain';
    private const RULE_UNKNOWN_EXCLUDED = 'ExcludedNotInCatalogue';
    private const RULE_MAJOR   = 'AppVersionMajorMismatch';
    private const RULE_SNAPSHOT_EXACT = 'SnapshotAppVersionMustBeExact';
    private const RULE_PRODUCER = 'ProducerRoleNotSuperAdmin';

    /**
     * @param  array<string, mixed>  $manifest  JSON-decoded manifest object.
     * @param  string  $currentAppVersion  Current app SemVer (root package.json).
     * @param  string  $requestId  Correlation id for logs.
     */
    public function validate(array $manifest, string $currentAppVersion, string $requestId): void
    {
        try {
            $this->validateTopShape($manifest);
            $this->validateContentIntegrity($manifest);
            $this->validateExcluded($manifest);
            $this->validateVersionCompatibility($manifest, $currentAppVersion);
            $this->validateProducer($manifest);
        } catch (LaraException $e) {
            Log::error('br.manifest.invalid', ['RequestId' => $requestId, 'Code' => $e->getMessage()]);
            throw $e;
        }
        Log::info('br.manifest.ok', ['RequestId' => $requestId, 'ArchiveId' => $manifest['archiveId'] ?? null]);
    }

    /** @param array<string, mixed> $m */
    private function validateTopShape(array $m): void
    {
        foreach (BrManifestSchema::REQUIRED_TOP as $key) {
            if (array_key_exists($key, $m) === false) {
                $this->fail('/' . $key, self::RULE_MISSING, self::ERR_CORRUPT);
            }
        }
        $allowed = array_merge(BrManifestSchema::REQUIRED_TOP, BrManifestSchema::OPTIONAL_TOP);
        foreach (array_keys($m) as $key) {
            if (in_array($key, $allowed, true) === false) {
                $this->fail('/' . $key, self::RULE_EXTRA, self::ERR_CORRUPT);
            }
        }
        $this->assertConst($m, 'version', BrManifestSchema::VERSION);
        $this->assertEnum($m, 'archiveKind', array_map(static fn ($c) => $c->value, BrArchiveKind::cases()));
        $this->assertPattern($m, 'appVersion', BrManifestSchema::SEMVER);
        $this->assertPattern($m, 'schemaHash', BrManifestSchema::HEX_SHA256);
        $this->assertPattern($m, 'contentHash', BrManifestSchema::HEX_SHA256);
        $this->validateScope($m);
    }

    /** @param array<string, mixed> $m */
    private function validateScope(array $m): void
    {
        if (!isset($m['scope']) || !is_array($m['scope'])) {
            $this->fail('/scope', self::RULE_TYPE, self::ERR_CORRUPT);
        }
        $scope = $m['scope'];
        foreach (BrManifestSchema::SCOPE_KEYS as $key) {
            if (array_key_exists($key, $scope) === false) {
                $this->fail('/scope/' . $key, self::RULE_MISSING, self::ERR_CORRUPT);
            }
        }
        foreach (array_keys($scope) as $key) {
            if (in_array($key, BrManifestSchema::SCOPE_KEYS, true) === false) {
                $this->fail('/scope/' . $key, self::RULE_EXTRA, self::ERR_CORRUPT);
            }
        }
    }

    /** @param array<string, mixed> $m */
    private function validateContentIntegrity(array $m): void
    {
        $index = $m['chunkIndex'] ?? null;
        if (!is_array($index) || !isset($index['merkleRoot'], $m['contentHash'])) {
            $this->fail('/chunkIndex/merkleRoot', self::RULE_MISSING, self::ERR_CORRUPT);
        }
        if ($index['merkleRoot'] !== $m['contentHash']) {
            $this->fail('/chunkIndex/merkleRoot', self::RULE_MERKLE, self::ERR_CORRUPT);
        }
    }

    /** @param array<string, mixed> $m */
    private function validateExcluded(array $m): void
    {
        $excluded = $m['excluded'] ?? null;
        if (is_array($excluded) === false) {
            $this->fail('/excluded', self::RULE_TYPE, self::ERR_CORRUPT);
        }
        $catalog = BrManifestSchema::EXCLUDED_CATALOGUE;
        foreach ($excluded as $i => $name) {
            if (!is_string($name) || !in_array($name, $catalog, true)) {
                $this->fail('/excluded/' . $i, self::RULE_UNKNOWN_EXCLUDED, self::ERR_CORRUPT);
            }
        }
        $domain = $m['scope']['domain']['tables'] ?? [];
        foreach (is_array($domain) ? $domain : [] as $i => $t) {
            if (is_array($t) && isset($t['name']) && in_array($t['name'], $excluded, true)) {
                $this->fail('/scope/domain/tables/' . $i . '/name', self::RULE_DISJOINT, self::ERR_CORRUPT);
            }
        }
    }

    /** @param array<string, mixed> $m */
    private function validateVersionCompatibility(array $m, string $currentAppVersion): void
    {
        $appVer = (string) ($m['appVersion'] ?? '');
        $kind = $m['archiveKind'] ?? null;
        $manifestMajor = (int) explode('.', $appVer)[0];
        $currentMajor  = (int) explode('.', $currentAppVersion)[0];
        if ($manifestMajor !== $currentMajor) {
            $this->fail('/appVersion', self::RULE_MAJOR, self::ERR_VERSION);
        }
        if ($kind === BrArchiveKind::Snapshot->value && $appVer !== $currentAppVersion) {
            $this->fail('/appVersion', self::RULE_SNAPSHOT_EXACT, self::ERR_VERSION);
        }
    }

    /** @param array<string, mixed> $m */
    private function validateProducer(array $m): void
    {
        $role = $m['producedBy']['role'] ?? null;
        if ($role !== BrProducedByRole::SuperAdmin->value) {
            $this->fail('/producedBy/role', self::RULE_PRODUCER, self::ERR_CORRUPT);
        }
    }

    /** @param array<string, mixed> $m */
    private function assertConst(array $m, string $key, string $expected): void
    {
        if (($m[$key] ?? null) !== $expected) {
            $this->fail('/' . $key, self::RULE_CONST, self::ERR_CORRUPT);
        }
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<int, string>  $allowed
     */
    private function assertEnum(array $m, string $key, array $allowed): void
    {
        if (in_array($m[$key] ?? null, $allowed, true) === false) {
            $this->fail('/' . $key, self::RULE_CONST, self::ERR_CORRUPT);
        }
    }

    /** @param array<string, mixed> $m */
    private function assertPattern(array $m, string $key, string $pattern): void
    {
        $v = $m[$key] ?? null;
        if (!is_string($v) || preg_match($pattern, $v) !== 1) {
            $this->fail('/' . $key, self::RULE_PATTERN, self::ERR_CORRUPT);
        }
    }

    private function fail(string $pointer, string $rule, string $code): never
    {
        throw InternalException::custom($code, "manifest validation failed at {$pointer} ({$rule})", [
            ['Field' => $pointer, 'Rule' => $rule, 'Value' => null],
        ]);
    }
}
