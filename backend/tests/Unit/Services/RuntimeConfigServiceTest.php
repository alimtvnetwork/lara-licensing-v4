<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\LaraException;
use App\Services\RuntimeConfigService;
use Tests\TestCase;

/**
 * Plan 16 step 58 (v0.563.0). Unit coverage for atomic version.json rewrites.
 *
 * Root cause guarded (one sentence): the admin surface trusts
 * `RuntimeConfigService` for If-Match verification, mutable-key filtering,
 * Mode invariants (Preview <-> ApiBaseUrl), safety rails, and atomic write,
 * so any slip would let the SPA fetch a partial document or accept an
 * unsafe (Mode, ApiBaseUrl) pair.
 *
 * See spec/28-runtime-modes/05-admin-runtime-toggle.md §M-01..M-03, C-01..C-03.
 */
final class RuntimeConfigServiceTest extends TestCase
{
    private string $path;
    private RuntimeConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'runtime-cfg-') . '.json';
        $this->writeInitial([
            'Version' => '0.563.0',
            'Mode' => 'preview',
            'ApiBaseUrl' => null,
            'PreviewSeed' => 'default',
            'UpdatedAt' => '2026-07-20T09:20:00Z',
            'AllowRuntimeToggle' => true,
        ]);
        $this->service = new RuntimeConfigService($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
        @unlink($this->path . '.tmp');
        parent::tearDown();
    }

    public function test_read_returns_normalized_document(): void
    {
        $doc = $this->service->read();
        $this->assertSame('preview', $doc['Mode']);
        $this->assertNull($doc['ApiBaseUrl']);
        $this->assertTrue($doc['AllowRuntimeToggle']);
    }

    public function test_update_happy_path_rewrites_mode_and_bumps_updated_at(): void
    {
        $etag = $this->service->readForResponse()['ETag'];
        $result = $this->service->update(['Mode' => 'production', 'ApiBaseUrl' => 'https://api.example.com/'], $etag);
        $this->assertSame('production', $result['After']['Mode']);
        $this->assertSame('https://api.example.com/', $result['After']['ApiBaseUrl']);
        $this->assertNotSame($result['Before']['UpdatedAt'], $result['After']['UpdatedAt']);
        $this->assertContains('Mode', $result['ChangedKeys']);
        $this->assertContains('ApiBaseUrl', $result['ChangedKeys']);
    }

    public function test_update_with_stale_etag_throws_runtime_config_conflict(): void
    {
        $this->assertLaraException(
            'RuntimeConfigConflict',
            412,
            fn () => $this->service->update(['Mode' => 'dev', 'ApiBaseUrl' => 'http://localhost:8000/'], '"deadbeef"'),
        );
    }

    public function test_update_rejects_non_mutable_key(): void
    {
        $etag = $this->service->readForResponse()['ETag'];
        $this->assertLaraException(
            'RuntimeConfigInvalidField',
            422,
            fn () => $this->service->update(['Version' => '9.9.9'], $etag),
        );
    }

    public function test_update_rejects_preview_with_api_base_url(): void
    {
        $etag = $this->service->readForResponse()['ETag'];
        $this->assertLaraException(
            'RuntimeConfigModeMismatch',
            422,
            fn () => $this->service->update(['Mode' => 'preview', 'ApiBaseUrl' => 'https://api.example.com/'], $etag),
        );
    }

    public function test_update_rejects_production_without_api_base_url(): void
    {
        $etag = $this->service->readForResponse()['ETag'];
        $this->assertLaraException(
            'RuntimeConfigModeMismatch',
            422,
            fn () => $this->service->update(['Mode' => 'production', 'ApiBaseUrl' => null], $etag),
        );
    }

    public function test_update_blocked_when_allow_runtime_toggle_false(): void
    {
        $this->writeInitial([
            'Version' => '0.563.0',
            'Mode' => 'production',
            'ApiBaseUrl' => 'https://api.example.com/',
            'PreviewSeed' => 'default',
            'UpdatedAt' => '2026-07-20T09:20:00Z',
            'AllowRuntimeToggle' => false,
        ]);
        $etag = $this->service->readForResponse()['ETag'];
        $this->assertLaraException(
            'RuntimeConfigLocked',
            423,
            fn () => $this->service->update(['Mode' => 'dev', 'ApiBaseUrl' => 'http://localhost:8000/'], $etag),
        );
    }

    public function test_prod_to_preview_blocked_unless_env_flag_set(): void
    {
        $this->writeInitial([
            'Version' => '0.563.0',
            'Mode' => 'production',
            'ApiBaseUrl' => 'https://api.example.com/',
            'PreviewSeed' => 'default',
            'UpdatedAt' => '2026-07-20T09:20:00Z',
            'AllowRuntimeToggle' => true,
        ]);
        config()->set('lara.runtime_config_allow_prod_to_preview', false);
        $etag = $this->service->readForResponse()['ETag'];
        $this->assertLaraException(
            'RuntimeConfigForbidden',
            403,
            fn () => $this->service->update(['Mode' => 'preview', 'ApiBaseUrl' => null], $etag),
        );
    }

    /** @param callable():mixed $fn */
    private function assertLaraException(string $expectedCode, int $expectedStatus, callable $fn): void
    {
        try {
            $fn();
        } catch (LaraException $e) {
            $this->assertSame($expectedCode, $e->errorCode);
            $this->assertSame($expectedStatus, $e->httpStatus);

            return;
        }
        $this->fail("Expected LaraException '{$expectedCode}' ({$expectedStatus}); none thrown.");
    }

    /** @param array<string,mixed> $data */
    private function writeInitial(array $data): void
    {
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT) . "\n");
    }
}
