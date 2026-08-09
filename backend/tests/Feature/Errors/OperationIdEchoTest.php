<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Tests\TestCase;
use App\Exceptions\NotFoundException;
use Illuminate\Support\Facades\Route;

/**
 * Step 143: OperationIdEchoTest
 * @group error-contract
 */
final class OperationIdEchoTest extends TestCase
{
    public function test_operation_id_is_echoed_when_present(): void
    {
        Route::get('/_test/op-id', fn() => throw NotFoundException::notFound('test'));
        
        $res = $this->withHeaders([
            'X-Lara-Operation' => 'admin.licenses.show',
        ])->getJson('/_test/op-id');
        
        $res->assertStatus(404);
        
        $json = $res->json();
        $this->assertSame('admin.licenses.show', $json['Attributes']['OperationId'] ?? null);
    }

    public function test_missing_header_means_attribute_is_absent(): void
    {
        Route::get('/_test/op-id-missing', fn() => throw NotFoundException::notFound('test'));
        
        $res = $this->getJson('/_test/op-id-missing');
        
        $res->assertStatus(404);
        
        $json = $res->json();
        $this->assertArrayNotHasKey('OperationId', $json['Attributes']);
    }
}
