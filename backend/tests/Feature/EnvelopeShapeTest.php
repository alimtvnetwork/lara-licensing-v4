<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Plan 06 step 47 (AC-ENV-001..004).
 *
 * Locks the universal response envelope shape `{Status, Attributes, Results}`
 * for both failure paths that Laravel would otherwise short-circuit before
 * our generic exception renderer:
 *
 *  - ValidationException  (FormRequest rejection on POST /Api/Auth/Login)
 *  - AuthenticationException (auth:sanctum on POST /Api/Auth/Logout)
 *
 * Root cause this guards: Laravel converts these two exceptions to
 * non-enveloped JSON via built-in renderers; bootstrap/app.php now
 * intercepts both. If either renderer regresses, this test fails.
 */
final class EnvelopeShapeTest extends TestCase
{
    public function test_validation_failure_returns_envelope(): void
    {
        $res = $this->postJson('/Api/Auth/Login', [], ['X-Request-Id' => 'test-req-1']);
        $res->assertStatus(400);
        $json = $res->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame(false, $json['Status']['IsSuccess'] ?? true);
        $this->assertSame('ValidationInputInvalid', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $this->assertSame('Validation', $json['Attributes']['Error']['Category'] ?? null);
        $this->assertSame('test-req-1', $json['Attributes']['RequestId'] ?? null);
        $this->assertSame([], $json['Results']);
    }

    public function test_unauthenticated_returns_envelope(): void
    {
        $res = $this->postJson('/Api/Auth/Logout', [], ['X-Request-Id' => 'test-req-2']);
        $res->assertStatus(401);
        $json = $res->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('AuthUnauthorized', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $this->assertSame('Auth', $json['Attributes']['Error']['Category'] ?? null);
        $this->assertSame([], $json['Results']);
    }

    public function test_operation_id_is_echoed(): void
    {
        $res = $this->withHeaders([
            'X-Lara-Operation' => 'admin.test.op',
        ])->postJson('/Api/Auth/Login', []);
        
        $res->assertStatus(400);
        $json = $res->json();
        $this->assertSame('admin.test.op', $json['Attributes']['OperationId'] ?? null);
    }

    private function assertEnvelopeShape(mixed $json): void
    {
        $this->assertIsArray($json);
        $this->assertArrayHasKey('Status', $json);
        $this->assertArrayHasKey('Attributes', $json);
        $this->assertArrayHasKey('Results', $json);
        $this->assertIsArray($json['Results']);
        $keys = array_keys($json);
        $this->assertSame(['Status', 'Attributes', 'Results'], $keys, 'Envelope key order must be Status, Attributes, Results.');
    }
}
