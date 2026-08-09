<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 06 step 48 (AC-IDL-001, AC-IDL-002, AC-IDL-004).
 *
 * Locks the Idempotency-Key middleware contract on a POST endpoint:
 *   - Fresh key + 2xx body -> snapshot persisted, 200.
 *   - Same key + same body -> byte-identical replay from snapshot.
 *   - Same key + different body -> IdempotencyConflict (409).
 *
 * The middleware only serialises 2xx responses; failing paths are
 * covered by ErrorTaxonomyTest (follow-up step). We register a
 * throwaway route inside the test so we do not need to seed the
 * Root schema, and materialise a portable sqlite `IdempotencyRecords`
 * table matching the Postgres migration's column contract.
 */
final class IdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdempotencyRecordsTable();
        $this->registerProbeRoute();
    }

    public function test_fresh_key_persists_and_replays(): void
    {
        $key = str_repeat('a', 32);
        $body = ['Payload' => 'first'];
        $first = $this->postJson('/api/admin/licenses/probe', $body, ['Idempotency-Key' => $key]);
        $first->assertStatus(200);
        $second = $this->postJson('/api/admin/licenses/probe', $body, ['Idempotency-Key' => $key]);
        $second->assertStatus(200);
        $this->assertSame($first->getContent(), $second->getContent(), 'Replay must be byte-identical.');
        $this->assertSame(1, DB::connection('root')->table('IdempotencyRecords')->count());
    }

    public function test_reused_key_with_different_body_returns_409(): void
    {
        $key = str_repeat('b', 32);
        $this->postJson('/api/admin/licenses/probe', ['Payload' => 'one'], ['Idempotency-Key' => $key])
            ->assertStatus(200);
        $conflict = $this->postJson('/api/admin/licenses/probe', ['Payload' => 'two'], ['Idempotency-Key' => $key]);
        $conflict->assertStatus(409);
        $json = $conflict->json();
        $this->assertSame('IdempotencyConflict', $json['Attributes']['Error']['ErrorCode'] ?? null);
    }

    public function test_missing_key_on_required_prefix_returns_400(): void
    {
        $res = $this->postJson('/api/admin/licenses/probe', ['Payload' => 'x']);
        $res->assertStatus(400);
        $json = $res->json();
        $this->assertSame('IdempotencyKeyRequired', $json['Attributes']['Error']['ErrorCode'] ?? null);
    }

    private function registerProbeRoute(): void
    {
        Route::post('/api/admin/licenses/probe', function () {
            return new JsonResponse(['Ok' => true, 'Nonce' => bin2hex(random_bytes(4))]);
        });
    }

    private function createIdempotencyRecordsTable(): void
    {
        DB::connection('root')->statement(
            'CREATE TABLE IF NOT EXISTS "IdempotencyRecords" (
                "IdempotencyRecordId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "Endpoint" TEXT NOT NULL,
                "ActorId" TEXT NOT NULL,
                "IdempotencyKey" TEXT NOT NULL,
                "BodyHash" TEXT NOT NULL,
                "ResponseStatus" INTEGER NOT NULL,
                "ResponseHeadersJson" TEXT NOT NULL,
                "ResponseBody" TEXT NOT NULL,
                "CreatedAt" TEXT NOT NULL,
                "ExpiresAt" TEXT NOT NULL,
                UNIQUE ("Endpoint", "ActorId", "IdempotencyKey")
            )'
        );
    }
}
