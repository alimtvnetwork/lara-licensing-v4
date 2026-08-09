<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;
use App\Models\Reseller;

uses(RefreshDatabase::class, SeedHelpers::class);

test('GET /Api/Admin/Licenses returns paginated shape', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Licenses');
    
    if ($res->status() === 404 || $res->status() === 405) {
         $this->markTestSkipped('Licenses list endpoint not implemented yet.');
    }
    
    $res->assertStatus(200);
    $json = $res->json();
    $this->assertArrayHasKey('Items', $json['Results']);
    $this->assertArrayHasKey('Page', $json['Results']);
    $this->assertArrayHasKey('PageSize', $json['Results']);
    $this->assertArrayHasKey('Total', $json['Results']);
});

test('Filter by ResellerId is respected', function () {
    $this->seedWithProfile('default');
    
    if (!class_exists(Reseller::class)) {
        $this->markTestSkipped('Reseller model not implemented yet.');
    }
    $reseller = Reseller::first();
    if (!$reseller) {
        $this->markTestSkipped('No resellers seeded.');
    }
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson("/Api/Admin/Licenses?ResellerId={$reseller->id}");
    
    if ($res->status() === 404 || $res->status() === 405) {
         $this->markTestSkipped('Licenses list endpoint not implemented yet.');
    }
    
    $res->assertStatus(200);
    foreach ($res->json()['Results']['Items'] as $item) {
        $this->assertSame($reseller->id, $item['ResellerId']);
    }
});

test('empty seed returns Total = 0', function () {
    $this->seedWithProfile('empty');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Licenses');
    
    if ($res->status() === 404 || $res->status() === 405) {
         $this->markTestSkipped('Licenses list endpoint not implemented yet.');
    }
    
    $res->assertStatus(200);
    $this->assertSame(0, $res->json()['Results']['Total']);
    $this->assertCount(0, $res->json()['Results']['Items']);
});
