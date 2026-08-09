<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Tests\TestCase;
use App\Exceptions\InternalException;
use Illuminate\Support\Facades\Route;

/**
 * Step 142: ErrorIdHeaderTest
 * @group error-contract
 */
final class ErrorIdHeaderTest extends TestCase
{
    public function test_error_responses_carry_error_id_header(): void
    {
        Route::get('/_test/error-id', fn() => throw InternalException::fatal('test'));
        
        $res = $this->getJson('/_test/error-id');
        $res->assertStatus(500);
        
        $json = $res->json();
        $errorId = $json['Attributes']['Error']['ErrorId'] ?? null;
        
        $this->assertNotNull($errorId);
        $this->assertTrue((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $errorId), 'ErrorId must be UUID v4');
        
        $this->assertSame($errorId, $res->headers->get('X-Error-Id'));
    }

    public function test_success_responses_do_not_carry_error_id_header(): void
    {
        Route::get('/_test/success-no-error-id', fn() => response()->json(['Status' => ['IsSuccess' => true], 'Attributes' => [], 'Results' => []]));
        
        $res = $this->getJson('/_test/success-no-error-id');
        $res->assertStatus(200);
        
        $this->assertFalse($res->headers->has('X-Error-Id'));
    }
}
