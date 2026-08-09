<?php

declare(strict_types=1);

// Plan 11 step 6 verification: assert the `lara-diag` Monolog channel is
// defined, uses the `daily` driver with the configured retention, and can
// actually write a line to a dated file under storage/logs/.
//
// Guards the invariant that Plan 11 step 7's gated stack-trace logging
// (Log::channel('lara-diag')->error(...)) cannot silently fall back to
// the default stack channel or throw InvalidArgumentException at runtime.

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

it('defines the lara-diag channel with daily driver and 14-day retention default', function (): void {
    $config = config('logging.channels.lara-diag');

    expect($config)->toBeArray()
        ->and($config['driver'] ?? null)->toBe('daily')
        ->and($config['days'] ?? null)->toBeGreaterThanOrEqual(14)
        ->and($config['path'] ?? null)->toEndWith('lara-diag.log')
        ->and($config['replace_placeholders'] ?? null)->toBeTrue();
});

it('writes a line to lara-diag-YYYY-MM-DD.log when Log::channel(lara-diag) is called', function (): void {
    $today = date('Y-m-d');
    $expectedPath = storage_path("logs/lara-diag-{$today}.log");

    if (File::exists($expectedPath)) {
        File::delete($expectedPath);
    }

    $marker = 'LaraDiagChannelTest:'.uniqid('', true);
    Log::channel('lara-diag')->error($marker, ['ErrorId' => 'test-error-id']);

    expect(File::exists($expectedPath))->toBeTrue(
        "Expected daily log file at {$expectedPath} after writing to lara-diag channel."
    );
    expect(File::get($expectedPath))->toContain($marker);
});
