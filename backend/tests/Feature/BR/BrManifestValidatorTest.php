<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrManifestValidator;
use Tests\TestCase;

/**
 * Plan 14 step 7 contract tests for `BrManifestValidator`.
 *
 * Locks:
 *  - Golden fixture (`manifest-golden.json`) passes untouched (INV-BR-MS-1).
 *  - Missing required key -> `BackupCorrupt` with JSON pointer.
 *  - `contentHash` != `chunkIndex.merkleRoot` -> `BackupCorrupt`
 *    (INV-BR-MS-3).
 *  - Unknown `excluded[]` entry -> `BackupCorrupt` (INV-BR-MS-4).
 *  - Major-version drift on Export -> `BackupVersionMismatch`.
 *  - Snapshot with any `appVersion` drift -> `BackupVersionMismatch`.
 *  - Producer role != `super_admin` -> `BackupCorrupt`
 *    (spec §"Validation Contract" step 6).
 */
final class BrManifestValidatorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/BR/manifest-golden.json';

    public function test_golden_manifest_passes(): void
    {
        app(BrManifestValidator::class)->validate($this->load(), '0.612.0', 'br-manifest-ok');
        $this->assertTrue(true);
    }

    public function test_missing_required_key_throws_backup_corrupt(): void
    {
        $m = $this->load();
        unset($m['contentHash']);
        $this->assertFails($m, '0.612.0', 'BackupCorrupt', '/contentHash');
    }

    public function test_merkle_root_mismatch_throws_backup_corrupt(): void
    {
        $m = $this->load();
        $m['chunkIndex']['merkleRoot'] = str_repeat('0', 64);
        $this->assertFails($m, '0.612.0', 'BackupCorrupt', '/chunkIndex/merkleRoot');
    }

    public function test_unknown_excluded_entry_throws_backup_corrupt(): void
    {
        $m = $this->load();
        $m['excluded'][] = 'public.unknown_table';
        $this->assertFails($m, '0.612.0', 'BackupCorrupt', '/excluded/');
    }

    public function test_major_drift_on_export_throws_version_mismatch(): void
    {
        $m = $this->load();
        $this->assertFails($m, '1.0.0', 'BackupVersionMismatch', '/appVersion');
    }

    public function test_snapshot_requires_exact_app_version(): void
    {
        $m = $this->load();
        $m['archiveKind'] = 'snapshot';
        $this->assertFails($m, '0.612.1', 'BackupVersionMismatch', '/appVersion');
    }

    public function test_non_super_admin_producer_throws_backup_corrupt(): void
    {
        $m = $this->load();
        $m['producedBy']['role'] = 'admin';
        $this->assertFails($m, '0.612.0', 'BackupCorrupt', '/producedBy/role');
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $raw = file_get_contents(self::FIXTURE);
        self::assertNotFalse($raw, 'Golden fixture missing.');
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @param array<string, mixed> $m */
    private function assertFails(array $m, string $appVer, string $code, string $pointerPrefix): void
    {
        try {
            app(BrManifestValidator::class)->validate($m, $appVer, 'br-manifest-fail');
            $this->fail("Expected {$code} at {$pointerPrefix}.");
        } catch (LaraException $e) {
            $this->assertSame($code, $e->errorCode, 'errorCode check');
            $field = $e->details[0]['Field'] ?? '';
            $this->assertStringStartsWith($pointerPrefix, (string) $field);
        }
    }
}
