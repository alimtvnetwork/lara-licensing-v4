<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;

uses(RefreshDatabase::class, SeedHelpers::class);

test('demo identities can login under default profile', function () {
    $this->seedWithProfile('default');

    foreach (DemoIdentities::all() as $identity) {
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => $identity['Email'],
            'Password' => DemoIdentities::password(),
        ]);
        
        $res->assertStatus(200);
        
        $json = $res->json();
        $this->assertArrayHasKey('AccessToken', $json['Results']);
        $this->assertArrayHasKey('RefreshToken', $json['Results']);
        $this->assertArrayHasKey('Me', $json['Results']);
        
        $me = $json['Results']['Me'];
        $this->assertSame($identity['Role'], $me['Role']);
        $this->assertSame($identity['Capabilities'], $me['Capabilities']);
    }
});

test('demo identities can login under empty profile', function () {
    $this->seedWithProfile('empty');

    foreach (DemoIdentities::all() as $identity) {
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => $identity['Email'],
            'Password' => DemoIdentities::password(),
        ]);
        
        $res->assertStatus(200);
    }
});

test('bad password returns AuthInvalidCredentials', function () {
    $this->seedWithProfile('default');
    
    $res = $this->postJson('/Api/Auth/Login', [
        'Email' => DemoIdentities::all()[0]['Email'],
        'Password' => 'wrong-password',
    ]);
    
    $res->assertStatus(401);
    
    $json = $res->json();
    $this->assertSame('AuthInvalidCredentials', $json['Attributes']['Error']['ErrorCode']);
    $this->assertSame('Auth', $json['Attributes']['Error']['Category']);
});
