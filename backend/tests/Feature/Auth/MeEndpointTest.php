<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;
use App\Models\User;

uses(RefreshDatabase::class, SeedHelpers::class);

test('authenticated GET /Api/Auth/Me returns 200 with Me payload', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    
    // Login to get token
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    
    $token = $loginRes->json()['Results']['AccessToken'];
    
    // Request Me
    $res = $this->withToken($token)->getJson('/Api/Auth/Me');
    
    $res->assertStatus(200);
    $json = $res->json();
    $me = $json['Results']['Me'];
    
    $this->assertSame($identity['Role'], $me['Role']);
    $this->assertSame($identity['Capabilities'], $me['Capabilities']);
});

test('unauthenticated call returns AuthUnauthorized', function () {
    $res = $this->getJson('/Api/Auth/Me');
    
    $res->assertStatus(401);
    $json = $res->json();
    $this->assertSame('AuthUnauthorized', $json['Attributes']['Error']['ErrorCode']);
});

test('impersonation active returns Me.Impersonation with TargetUserId', function () {
    $this->seedWithProfile('default');
    
    $adminIdentity = DemoIdentities::all()[0]; // SuperAdmin
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $adminIdentity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    // Find target user
    $targetUser = User::where('email', '!=', $adminIdentity['Email'])->first();
    
    // Start Impersonation
    $impRes = $this->withToken($token)->postJson("/Api/Admin/Users/{$targetUser->id}/Impersonate");
    $impRes->assertStatus(200);
    
    // Now request Me
    $res = $this->withToken($token)->getJson('/Api/Auth/Me');
    $res->assertStatus(200);
    
    $json = $res->json();
    $me = $json['Results']['Me'];
    
    $this->assertArrayHasKey('Impersonation', $me);
    $this->assertSame($targetUser->id, $me['Impersonation']['TargetUserId']);
});
