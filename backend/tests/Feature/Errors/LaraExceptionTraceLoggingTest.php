<?php

declare(strict_types=1);

// Plan 11 SS-01 verification: LaraException + unhandled Throwable renderers
// must emit a full stack trace to the `lara-diag` channel and NEVER include
// it in the caller-visible response envelope.

use App\Exceptions\LaraException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $today = date('Y-m-d');
    $path = storage_path("logs/lara-diag-{$today}.log");
    if (File::exists($path)) {
        File::delete($path);
    }
});

it('logs LaraException trace to lara-diag and keeps Trace out of the envelope', function (): void {
    Route::get('/Api/_test/lara-throw', function () {
        throw LaraException::make('ServerError', 'boom for trace test');
    });

    $response = $this->getJson('/Api/_test/lara-throw', ['X-Request-Id' => 'req-trace-1']);

    $response->assertStatus(500);
    $body = $response->json();
    expect($body)->not->toHaveKey('Trace');
    expect(json_encode($body))->not->toContain('getTraceAsString');

    $path = storage_path('logs/lara-diag-'.date('Y-m-d').'.log');
    expect(File::exists($path))->toBeTrue();
    $contents = File::get($path);
    expect($contents)->toContain('lara.exception.trace');
    expect($contents)->toContain('req-trace-1');
    expect($contents)->toContain('LaraExceptionTraceLoggingTest');
});

it('logs unhandled Throwable trace to lara-diag with ErrorId correlation', function (): void {
    Route::get('/Api/_test/unhandled', function () {
        throw new \RuntimeException('unhandled boom for trace test');
    });

    $response = $this->getJson('/Api/_test/unhandled', ['X-Request-Id' => 'req-trace-2']);

    $response->assertStatus(500);
    $body = $response->json();
    expect($body)->not->toHaveKey('Trace');
    $errorId = data_get($body, 'Attributes.ErrorId') ?? data_get($body, 'attributes.ErrorId');
    expect($errorId)->not->toBeNull();

    $path = storage_path('logs/lara-diag-'.date('Y-m-d').'.log');
    expect(File::exists($path))->toBeTrue();
    $contents = File::get($path);
    expect($contents)->toContain('lara.unhandled.trace');
    expect($contents)->toContain((string) $errorId);
    expect($contents)->toContain('RuntimeException');
});
