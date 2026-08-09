<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

/**
 * Plan 18 Step 122: Shared seed helpers for Pest tests.
 *
 * Ensures deterministic seeding by isolating the SEED_PROFILE environment variable
 * to the current test case before running the DatabaseSeeder.
 */
trait SeedHelpers
{
    /**
     * Seeds the database with the given profile.
     * Must be called in a test that uses RefreshDatabase.
     */
    protected function seedWithProfile(string $profile = 'default'): void
    {
        $original = env('SEED_PROFILE');
        putenv("SEED_PROFILE={$profile}");
        $_ENV['SEED_PROFILE'] = $profile;
        
        $this->artisan('db:seed');
        
        if ($original !== false) {
            putenv("SEED_PROFILE={$original}");
            $_ENV['SEED_PROFILE'] = $original;
        } else {
            putenv('SEED_PROFILE');
            unset($_ENV['SEED_PROFILE']);
        }
    }
}
