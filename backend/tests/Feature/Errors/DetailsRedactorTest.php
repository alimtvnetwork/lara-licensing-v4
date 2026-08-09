<?php

declare(strict_types=1);

// Plan 11 step 33 verification: DetailsRedactor must mask sensitive fields
// inside a LaraException `$details` payload before they cross either the
// API surface (ApiEnvelope::failure) or the log surface (`lara.exception`).
// Guards spec/03-error-manage §4.2 and AC-ERR-005.

use App\Support\DetailsRedactor;

it('masks Value and Message on a detail item whose Field is sensitive', function (): void {
    $details = [
        ['Field' => 'password', 'Rule' => 'Min', 'Value' => 'hunter2', 'Message' => "password 'hunter2' too short"],
        ['Field' => 'email',    'Rule' => 'Email', 'Value' => 'a@b.co', 'Message' => 'not an email'],
    ];

    $out = DetailsRedactor::redact($details);

    expect($out[0]['Field'])->toBe('password');
    expect($out[0]['Rule'])->toBe('Min');
    expect($out[0]['Value'])->toBe('***REDACTED***');
    expect($out[0]['Message'])->toBe('***REDACTED***');
    // Non-sensitive item untouched.
    expect($out[1]['Value'])->toBe('a@b.co');
    expect($out[1]['Message'])->toBe('not an email');
});

it('matches sensitive Field names case-insensitively and via substring', function (): void {
    $details = [
        ['Field' => 'AccessToken', 'Rule' => 'Required', 'Value' => 'sk_live_abc'],
        ['Field' => 'X-API-Key',   'Rule' => 'Required', 'Value' => 'k-123'],
        ['Field' => 'refresh_token','Rule' => 'Required', 'Value' => 'r-123'],
    ];

    $out = DetailsRedactor::redact($details);
    expect($out[0]['Value'])->toBe('***REDACTED***');
    expect($out[1]['Value'])->toBe('***REDACTED***');
    expect($out[2]['Value'])->toBe('***REDACTED***');
});

it('redacts by key name inside arbitrary nested detail payloads', function (): void {
    $details = [
        'context' => [
            'user_id' => 42,
            'password' => 'hunter2',
            'nested' => ['authorization' => 'Bearer xyz', 'safe' => 'ok'],
        ],
    ];

    $out = DetailsRedactor::redact($details);
    expect($out['context']['user_id'])->toBe(42);
    expect($out['context']['password'])->toBe('***REDACTED***');
    expect($out['context']['nested']['authorization'])->toBe('***REDACTED***');
    expect($out['context']['nested']['safe'])->toBe('ok');
});

it('redacts long high-entropy strings even under non-sensitive keys', function (): void {
    $tok = str_repeat('a', 40);
    $details = ['note' => $tok, 'short' => 'ok'];
    $out = DetailsRedactor::redact($details);
    expect($out['note'])->toBe('***REDACTED***');
    expect($out['short'])->toBe('ok');
});

it('returns an empty array unchanged (no envelope shape drift)', function (): void {
    expect(DetailsRedactor::redact([]))->toBe([]);
});

it('caps recursion depth so deeply nested payloads still get masked', function (): void {
    // Build a chain deeper than the redactor's MAX_DEPTH (4).
    $leaf = ['password' => 'hunter2'];
    $nested = ['a' => ['b' => ['c' => ['d' => ['e' => $leaf]]]]];
    $out = DetailsRedactor::redact($nested);
    // Whatever the walker does below the cap, no raw 'hunter2' string may
    // survive anywhere in the output tree.
    $encoded = json_encode($out);
    expect($encoded)->not->toContain('hunter2');
});
