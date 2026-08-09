<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;

uses(RefreshDatabase::class, SeedHelpers::class);

test('GET /Api/Admin/Metrics returns 200 with non-zero KPIs under default seed', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0]; // SuperAdmin
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Metrics');
    $res->assertStatus(200);
    
    $json = $res->json();
    $this->assertGreaterThanOrEqual(8, $json['Results']['Resellers']['TotalCount']);
    $this->assertGreaterThanOrEqual(120, $json['Results']['Licenses']['TotalCount']);
    $this->assertGreaterThanOrEqual(24, $json['Results']['QuotaRequests']['TotalCount']);
});

test('under empty seed, every tile returns 0 without erroring', function () {
    $this->seedWithProfile('empty');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Metrics');
    $res->assertStatus(200);
    
    $json = $res->json();
    $this->assertSame(0, $json['Results']['Resellers']['TotalCount']);
    $this->assertSame(0, $json['Results']['Licenses']['TotalCount']);
    $this->assertSame(0, $json['Results']['QuotaRequests']['TotalCount']);
});

test('reseller role gets AuthzRoleDenied', function () {
    $this->seedWithProfile('default');
    
    $identity = collect(DemoIdentities::all())->firstWhere('Role', 'Reseller');
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Metrics');
    
    $res->assertStatus(403);
    $json = $res->json();
    $this->assertSame('AuthzRoleDenied', $json['Attributes']['Error']['ErrorCode']);
});
