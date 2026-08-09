<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use App\Exceptions\LaraException;
use Tests\TestCase;

/**
 * Plan 11 step 21.
 *
 * Root cause guarded: `LaraException::newErrorId()` (backend/app/Exceptions/
 * LaraException.php lines 71-79) hand-rolls uuid v4 by flipping the version
 * and variant bits on 16 random bytes, but no test locked either the RFC 4122
 * shape or the uniqueness distribution, so a well-meaning refactor that
 * replaced the bit-flip with a naive `bin2hex(random_bytes(16))` (missing the
 * `4xxx` and `[89ab]xxx` positions) would still look uuid-ish while breaking
 * downstream log grep tools that anchor on the v4 pattern.
 */
final class ErrorIdUuidV4ShapeTest extends TestCase
{
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const SAMPLE_SIZE = 10000;

    public function test_error_id_matches_uuid_v4_shape(): void
    {
        $e = LaraException::make('ServerError', 'shape probe');
        $this->assertMatchesRegularExpression(self::UUID_V4_REGEX, $e->errorId);
    }

    public function test_error_id_is_unique_across_ten_thousand_invocations(): void
    {
        $seen = [];
        $malformed = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $id = LaraException::make('ServerError', 'uniq probe')->errorId;
            if (preg_match(self::UUID_V4_REGEX, $id) !== 1) {
                $malformed[] = $id;
            }
            $seen[$id] = ($seen[$id] ?? 0) + 1;
        }
        $this->assertSame([], $malformed, 'Every ErrorId must be a valid uuid v4.');
        $collisions = array_filter($seen, static fn (int $n): bool => $n > 1);
        $this->assertSame([], $collisions, 'ErrorId collisions detected across 10k samples.');
        $this->assertCount(self::SAMPLE_SIZE, $seen);
    }
}
