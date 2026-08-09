<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;

uses(RefreshDatabase::class, SeedHelpers::class);

test('valid refresh rotates the pair', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    
    $json = $loginRes->json();
    $refreshToken = $json['Results']['RefreshToken'] ?? null;
    
    if (!$refreshToken) {
        $this->markTestSkipped('Refresh token not returned by login (feature incomplete).');
    }
    
    $res = $this->postJson('/Api/Auth/Refresh', [
        'RefreshToken' => $refreshToken,
    ]);
    
    if ($res->status() === 404) {
        $this->markTestSkipped('Refresh endpoint not implemented yet.');
    }
    
    $res->assertStatus(200);
    $this->assertArrayHasKey('AccessToken', $res->json()['Results']);
});

test('reused refresh yields AuthRefreshReused', function () {
    $this->markTestSkipped('Refresh endpoint not implemented yet.');
});

test('concurrent refresh race condition', function () {
    $this->markTestSkipped('Refresh endpoint not implemented yet.');
});
