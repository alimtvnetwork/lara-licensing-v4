<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Plan 06 step 47. Base test case for the Lara backend.
 *
 * Kept intentionally minimal: individual test suites opt into database
 * migrations via `RefreshDatabase` on their own, so the envelope-shape
 * suite can run without booting the full Root + Shard schema.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
