<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use Tests\TestCase;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\InternalException;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Route;

/**
 * Step 141: ErrorEnvelopeCategoryTest
 * @group error-contract
 */
final class ErrorEnvelopeCategoryTest extends TestCase
{
    public function test_categories_are_mapped_correctly(): void
    {
        // Bind temporary routes to throw exceptions
        Route::get('/_test/auth-ex', fn() => throw AuthException::unauthorized('test'));
        Route::get('/_test/val-ex', fn() => throw ValidationException::invalidInput('test', []));
        Route::get('/_test/rate-ex', fn() => throw RateLimitException::rateLimited('test'));
        Route::get('/_test/dom-ex', fn() => throw DomainConflictException::conflict('test'));
        Route::get('/_test/not-ex', fn() => throw NotFoundException::notFound('test'));
        Route::get('/_test/int-ex', fn() => throw InternalException::fatal('test'));
        
        $map = [
            '/_test/auth-ex' => 'Auth',
            '/_test/val-ex'  => 'Validation',
            '/_test/rate-ex' => 'RateLimit',
            '/_test/dom-ex'  => 'DomainConflict',
            '/_test/not-ex'  => 'NotFound',
            '/_test/int-ex'  => 'Internal',
        ];

        foreach ($map as $uri => $expectedCategory) {
            $res = $this->getJson($uri);
            $json = $res->json();
            $this->assertSame($expectedCategory, $json['Attributes']['Error']['Category'] ?? null, "URI $uri failed category match");
        }
    }
}
