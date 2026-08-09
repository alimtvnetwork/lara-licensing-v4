<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Models\License;
use App\Models\User;

uses(RefreshDatabase::class, SeedHelpers::class);

test('empty profile seeds identities but no transactional tables', function () {
    $this->seedWithProfile('empty');
    
    // Identities seeded
    if (class_exists(User::class)) {
        $this->assertGreaterThanOrEqual(3, User::count());
    }
    
    // Transactional tables empty
    if (class_exists(License::class)) {
        $this->assertSame(0, License::count());
    }
    // AuditEntries not implemented as model yet, so skip
});
