<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 06 step 49 (AC-CONCUR-001..004).
 *
 * Locks the EtagMiddleware contract:
 *   - GET JSON responses receive a strong SHA-256 ETag header, quoted
 *     per RFC 9110, computed over the canonical body (this test
 *     asserts shape, not the canonical hash which is covered by
 *     IdempotencyCanonicalizer unit tests).
 *   - PATCH on a License route without If-Match returns 428
 *     (PreconditionRequired) with the enveloped error code.
 *   - Wildcard `*` and weak `W/"..."` If-Match values are rejected
 *     with a ValidationFailed error.
 *
 * A throwaway GET route is registered inside the test so we do not
 * depend on any DB-backed License endpoint.
 */
final class EtagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/api/etag-probe', function () {
            return new JsonResponse(['Value' => 'stable', 'Nested' => ['Key' => 1]]);
        });
    }

    public function test_get_json_response_has_strong_etag_header(): void
    {
        $res = $this->getJson('/api/etag-probe');
        $res->assertStatus(200);
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag, 'ETag header must be attached to GET JSON responses.');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{64}"$/', $etag, 'ETag must be strong quoted lowercase SHA-256.');
    }

    public function test_patch_license_without_if_match_returns_428(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';
        $res = $this->json('PATCH', "/api/licenses/{$id}", ['Note' => 'x']);
        $res->assertStatus(428);
        $this->assertSame('PreconditionRequired', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_patch_license_with_wildcard_if_match_returns_400(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';
        $res = $this->json('PATCH', "/api/licenses/{$id}", ['Note' => 'x'], ['If-Match' => '*']);
        $res->assertStatus(400);
        $this->assertSame('ValidationFailed', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_patch_license_with_weak_if_match_returns_400(): void
    {
        $id = '00000000-0000-4000-8000-000000000000';
        $res = $this->json('PATCH', "/api/licenses/{$id}", ['Note' => 'x'], ['If-Match' => 'W/"abc"']);
        $res->assertStatus(400);
        $this->assertSame('ValidationFailed', $res->json('Attributes.Error.ErrorCode'));
    }
}
