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

/**
 * Step 145: ExceptionHierarchyTest
 * @group error-contract
 */
final class ExceptionHierarchyTest extends TestCase
{
    public function test_subclass_factories(): void
    {
        $auth = AuthException::unauthorized('msg');
        $this->assertSame(401, $auth->httpStatus);
        $this->assertSame('AuthUnauthorized', $auth->errorCode);
        
        $val = ValidationException::invalidInput('msg');
        $this->assertSame(400, $val->httpStatus);
        $this->assertSame('ValidationInputInvalid', $val->errorCode);
        
        $rate = RateLimitException::rateLimited('msg');
        $this->assertSame(429, $rate->httpStatus);
        $this->assertSame('RateLimited', $rate->errorCode);
        
        $dom = DomainConflictException::conflict('msg');
        $this->assertSame(409, $dom->httpStatus);
        $this->assertSame('DomainConflict', $dom->errorCode);
        
        $not = NotFoundException::notFound('msg');
        $this->assertSame(404, $not->httpStatus);
        $this->assertSame('NotFound', $not->errorCode);
        
        $int = InternalException::fatal('msg');
        $this->assertSame(500, $int->httpStatus);
        $this->assertSame('InternalFatal', $int->errorCode);
    }

    public function test_base_lara_exception_still_works(): void
    {
        $base = new LaraException('msg', 'CustomError', 418);
        $this->assertSame(418, $base->httpStatus);
        $this->assertSame('CustomError', $base->errorCode);
    }
}
