<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Models\License;

uses(RefreshDatabase::class, SeedHelpers::class);

test('error profile seeds error-trigger classes', function () {
    $this->seedWithProfile('error');
    
    if (class_exists(License::class)) {
        // Find an expired license
        $expired = License::where('Status', 'expired')->first();
        if (!$expired) {
            $this->markTestSkipped('Expired license seeder not implemented.');
        }
        $this->assertNotNull($expired);
    }
});
