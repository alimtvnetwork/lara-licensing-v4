<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Support\SeedHelpers;
use App\Models\User;
use App\Lib\DemoIdentities;

uses(RefreshDatabase::class, SeedHelpers::class);

test('demo identities are present under all profiles', function (string $profile) {
    $this->seedWithProfile($profile);
    
    if (!class_exists(User::class)) {
        $this->markTestSkipped('User model not implemented.');
    }
    
    $emails = array_column(DemoIdentities::all(), 'Email');
    $users = User::whereIn('email', $emails)->get();
    
    $this->assertCount(3, $users);
    
    $password = DemoIdentities::password();
    foreach ($users as $user) {
        $this->assertTrue(Hash::check($password, $user->password));
    }
})->with('profiles');
