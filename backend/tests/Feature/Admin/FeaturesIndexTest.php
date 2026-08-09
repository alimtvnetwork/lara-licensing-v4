<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\SeedHelpers;
use App\Lib\DemoIdentities;

uses(RefreshDatabase::class, SeedHelpers::class);

test('GET /Api/Admin/Features returns Items matching shape', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Features');
    $res->assertStatus(200);
    
    $json = $res->json();
    $this->assertArrayHasKey('Items', $json['Results']);
    $this->assertIsArray($json['Results']['Items']);
    
    if (count($json['Results']['Items']) > 0) {
        $first = $json['Results']['Items'][0];
        $this->assertArrayHasKey('Slug', $first);
        $this->assertArrayHasKey('Name', $first);
        $this->assertArrayHasKey('Description', $first);
    }
});

test('ordering is deterministic by Slug asc', function () {
    $this->seedWithProfile('default');
    
    $identity = DemoIdentities::all()[0];
    $loginRes = $this->postJson('/Api/Auth/Login', [
        'Email' => $identity['Email'],
        'Password' => DemoIdentities::password(),
    ]);
    $token = $loginRes->json()['Results']['AccessToken'];
    
    $res = $this->withToken($token)->getJson('/Api/Admin/Features');
    $res->assertStatus(200);
    
    $items = $res->json()['Results']['Items'];
    $slugs = array_column($items, 'Slug');
    $sortedSlugs = $slugs;
    sort($sortedSlugs);
    
    $this->assertSame($sortedSlugs, $slugs);
});

test('RBAC: SuperAdmin gets full list, scoped admin filtered', function () {
    $this->markTestSkipped('Assertion delegated to Plan 05 (marker only)');
});
