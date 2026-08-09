<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 11 step 20.
 *
 * Root cause guarded: `bootstrap/app.php` propagates `LaraException::$headers`
 * onto the failure response (line 96-98), but no test asserted that the
 * `Retry-After` header actually reaches the HTTP response for the two codes
 * that ship it (`RateLimited` -> 429, `QuotaExhausted` -> 409). A future
 * renderer refactor could drop the `foreach ($e->headers ...)` loop and
 * clients relying on `Retry-After` for backoff would silently degrade.
 */
final class RetryAfterHeaderPropagationTest extends TestCase
{
    private const RETRY_AFTER_SECONDS = '30';
    private const REQUEST_ID = 'req-retryafter-000000';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/Api/__test/retry-after/rate-limited', function () {
            throw LaraException::make(
                'RateLimited',
                'Forced RateLimited',
                [],
                null,
                ['Retry-After' => self::RETRY_AFTER_SECONDS],
            );
        });

        Route::get('/Api/__test/retry-after/quota-exhausted', function () {
            throw LaraException::make(
                'QuotaExhausted',
                'Forced QuotaExhausted',
                [],
                null,
                ['Retry-After' => self::RETRY_AFTER_SECONDS],
            );
        });

        Route::get('/Api/__test/retry-after/no-header', function () {
            throw LaraException::make('LicenseNotFound', 'Forced LicenseNotFound');
        });
    }

    public function test_rate_limited_propagates_retry_after_header(): void
    {
        $res = $this->getJson('/Api/__test/retry-after/rate-limited', [
            'X-Request-Id' => self::REQUEST_ID,
        ]);
        $res->assertStatus(429);
        $this->assertSame(self::RETRY_AFTER_SECONDS, $res->headers->get('Retry-After'));
        $this->assertSame('RateLimited', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_quota_exhausted_propagates_retry_after_header(): void
    {
        $res = $this->getJson('/Api/__test/retry-after/quota-exhausted', [
            'X-Request-Id' => self::REQUEST_ID,
        ]);
        $res->assertStatus(409);
        $this->assertSame(self::RETRY_AFTER_SECONDS, $res->headers->get('Retry-After'));
        $this->assertSame('QuotaExhausted', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_non_retry_codes_do_not_emit_retry_after(): void
    {
        $res = $this->getJson('/Api/__test/retry-after/no-header', [
            'X-Request-Id' => self::REQUEST_ID,
        ]);
        $res->assertStatus(404);
        $this->assertNull($res->headers->get('Retry-After'));
    }
}
