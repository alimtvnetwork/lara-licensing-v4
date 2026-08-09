<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Models\Reseller;
use App\Models\License;
use App\Models\QuotaRequest;

uses(RefreshDatabase::class, SeedHelpers::class);

test('default profile populates row counts matching seeder coverage plan', function () {
    $this->seedWithProfile('default');
    
    if (class_exists(Reseller::class)) {
        $this->assertGreaterThanOrEqual(8, Reseller::count());
    }
    
    if (class_exists(License::class)) {
        $this->assertGreaterThanOrEqual(120, License::count());
    }
    
    if (class_exists(QuotaRequest::class)) {
        $this->assertGreaterThanOrEqual(24, QuotaRequest::count());
    }
});

test('idempotent: second run does not double rows', function () {
    $this->seedWithProfile('default');
    
    if (class_exists(Reseller::class)) {
        $resellersCount = Reseller::count();
        $this->seedWithProfile('default');
        $this->assertSame($resellersCount, Reseller::count());
    }
});
