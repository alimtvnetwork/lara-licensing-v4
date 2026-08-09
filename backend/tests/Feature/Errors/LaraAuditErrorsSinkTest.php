<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Tests\TestCase;
use App\Exceptions\InternalException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

/**
 * Step 144: LaraAuditErrorsSinkTest
 * @group error-contract
 */
final class LaraAuditErrorsSinkTest extends TestCase
{
    public function test_exception_writes_to_audit_sink(): void
    {
        // Use a mock logger to intercept the log
        $mockLogger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $mockLogger->shouldReceive('error')->once()->withArgs(function ($message, $context) {
            $this->assertArrayHasKey('RequestId', $context);
            $this->assertArrayHasKey('ErrorId', $context);
            $this->assertArrayHasKey('ErrorCode', $context);
            $this->assertArrayHasKey('HttpStatus', $context);
            
            // Should not contain PII
            $this->assertArrayNotHasKey('Password', $context);

            return true;
        });
        
        Log::shouldReceive('channel')->with('lara-audit-errors')->andReturn($mockLogger);

        Route::post('/_test/audit-sink', fn() => throw InternalException::fatal('test_error', ['Password' => 'secret123']));
        
        $res = $this->withHeaders([
            'X-Lara-Operation' => 'test.op',
        ])->postJson('/_test/audit-sink', []);
        
        $res->assertStatus(500);
    }
}
