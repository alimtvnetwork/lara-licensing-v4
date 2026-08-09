<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Services\BR\BrManifestBuilder;
use App\Services\BR\BrManifestValidator;
use Tests\TestCase;

/**
 * Plan 14 step 12 contract tests for `BrManifestBuilder`.
 *
 * Locks:
 *  - Shadow manifest round-trips `BrManifestValidator::validate` under the
 *    current app version (INV-BR-MS-1..3).
 *  - Canonical bytes are deterministic under recursive key sort + LF
 *    terminator (spec §"Manifest Location").
 *  - `contentHash === chunkIndex.merkleRoot` (INV-BR-MS-3).
 *  - Manifest declares `producedBy.role = super_admin` (spec §
 *    "Validation Contract" step 6).
 *  - Empty `userId` collapses to null UUID (no leak of caller identity
 *    when the enqueuer omitted `Payload.UserId`).
 */
final class BrManifestBuilderTest extends TestCase
{
    private const ARCHIVE_ID = '018fa7c3-1a2b-7c4d-8e5f-6a7b8c9d0e1f';
    private const APP_VERSION = '0.620.0';
    private const USER_ID = '018fa7c3-1a2b-7c4d-8e5f-6a7b8c9d0e20';
    private const REQUEST_ID = 'req-builder-0001';

    public function test_shadow_manifest_validates(): void
    {
        $builder = app(BrManifestBuilder::class);
        $manifest = $builder->buildShadow(self::ARCHIVE_ID, self::APP_VERSION, self::USER_ID, self::REQUEST_ID);
        app(BrManifestValidator::class)->validate($manifest, self::APP_VERSION, self::REQUEST_ID);
        $this->assertSame($manifest['contentHash'], $manifest['chunkIndex']['merkleRoot']);
        $this->assertSame('super_admin', $manifest['producedBy']['role']);
    }

    public function test_canonical_bytes_are_deterministic(): void
    {
        $builder = app(BrManifestBuilder::class);
        $manifest = $builder->buildShadow(self::ARCHIVE_ID, self::APP_VERSION, self::USER_ID, self::REQUEST_ID);
        $a = $builder->canonicalize($manifest);
        $b = $builder->canonicalize($manifest);
        $this->assertSame($a, $b);
        $this->assertStringEndsWith("\n", $a);
    }

    public function test_missing_user_id_falls_back_to_null_uuid(): void
    {
        $builder = app(BrManifestBuilder::class);
        $manifest = $builder->buildShadow(self::ARCHIVE_ID, self::APP_VERSION, '', self::REQUEST_ID);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $manifest['producedBy']['userId']);
    }
}
