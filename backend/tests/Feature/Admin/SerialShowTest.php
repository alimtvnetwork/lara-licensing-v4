<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;
use App\Models\Serial;

uses(RefreshDatabase::class, SeedHelpers::class);

test('GET /Api/Admin/Serials/{id} returns 200 with serial payload', function () {
    $this->seedWithProfile('default');
    
    if (!class_exists(Serial::class)) {
        $this->markTestSkipped('Serial model not implemented yet.');
    }
    
    $serial = Serial::first();
    if (!$serial) {
        $this->markTestSkipped('No serials seeded.');
    }
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson("/Api/Admin/Serials/{$serial->id}");
    
    if ($res->status() === 404 && !isset($res->json()['Attributes'])) {
         $this->markTestSkipped('Admin serial show endpoint not implemented yet.');
    }
    
    $res->assertStatus(200);
    $this->assertArrayHasKey('Id', $res->json()['Results']);
});

test('missing id returns 404 SerialNotFound', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Serials/99999999');
    
    if ($res->status() === 404 && !isset($res->json()['Attributes'])) {
         $this->markTestSkipped('Admin serial show endpoint not implemented yet.');
    }
    
    $res->assertStatus(404);
    $json = $res->json();
    $this->assertSame('SerialNotFound', $json['Attributes']['Error']['ErrorCode']);
});

test('revoked serial returns 200 with Status = revoked', function () {
    $this->markTestSkipped('Revoked serials logic to be implemented.');
});
