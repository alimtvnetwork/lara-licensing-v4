<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Plan 11 step 23 (AC-ENV-001).
 *
 * Root cause guarded: any handler that returns response()->json([...])
 * directly leaks a non-enveloped body. AssertEnvelopeMiddleware turns that
 * silent regression into a hard 500 during local + testing.
 *
 * We register two throwaway routes inside the /Api/* prefix so the middleware
 * activation path is exactly the one production uses.
 */

it('accepts a well-formed envelope response', function () {
    Route::get('/Api/__test/envelope-ok', fn () => response()->json([
        'Status' => ['IsSuccess' => true, 'HttpCode' => 200, 'Message' => 'OK'],
        'Attributes' => ['RequestId' => 'test-req-1'],
        'Results' => [],
    ]));

    $this->getJson('/Api/__test/envelope-ok')
        ->assertOk()
        ->assertJsonPath('Attributes.RequestId', 'test-req-1');
});

it('rejects a response missing Attributes.RequestId', function () {
    Route::get('/Api/__test/envelope-broken', fn () => response()->json([
        'Status' => ['IsSuccess' => true, 'HttpCode' => 200, 'Message' => 'OK'],
        'Attributes' => [],
        'Results' => [],
    ]));

    // Middleware throws RuntimeException; Laravel renders it as 500 in tests.
    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/Api/__test/envelope-broken'))
        ->toThrow(RuntimeException::class, 'Attributes.RequestId');
});

it('rejects a response missing Results', function () {
    Route::get('/Api/__test/envelope-no-results', fn () => response()->json([
        'Status' => ['IsSuccess' => true, 'HttpCode' => 200, 'Message' => 'OK'],
        'Attributes' => ['RequestId' => 'x'],
    ]));

    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/Api/__test/envelope-no-results'))
        ->toThrow(RuntimeException::class, 'Results');
});

it('skips non-Api paths', function () {
    Route::get('/other/plain', fn () => response()->json(['hello' => 'world']));
    $this->getJson('/other/plain')->assertOk();
});
