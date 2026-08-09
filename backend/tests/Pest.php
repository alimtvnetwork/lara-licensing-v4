<?php

declare(strict_types=1);

/**
 * Pest bootstrap. Binds the Feature and Unit directories to the
 * project's `Tests\TestCase` so Pest test files can call Laravel
 * helpers (`getJson`, `actingAs`, `app()->instance(...)`) with the
 * same lifecycle as the PHPUnit-style suites in this repo.
 *
 * Spec: coding-guidelines.md (Pest coexists with PHPUnit; both point
 * at the same TestCase so fixtures stay identical).
 */

uses(Tests\TestCase::class)->in('Feature', 'Unit');

dataset('profiles', [
    'default',
    'empty',
    'error',
]);
