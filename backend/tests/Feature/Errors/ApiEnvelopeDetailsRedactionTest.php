<?php

declare(strict_types=1);

// Plan 11 step 33 integration guard: ApiEnvelope::failure MUST redact
// sensitive Value/Message details before they land in the response JSON.
// This locks the choke point: any future call site that passes raw
// $details still emits a safe envelope.

use App\Support\ApiEnvelope;

it('redacts sensitive Value entries in the emitted failure envelope', function (): void {
    $resp = ApiEnvelope::failure(
        errorCode: 'ValidationInputInvalid',
        errorMessage: 'Request payload failed validation.',
        requestId: 'req-test-1',
        httpCode: 400,
        message: 'ValidationInputInvalid',
        details: [
            ['Field' => 'password', 'Rule' => 'Min', 'Value' => 'hunter2', 'Message' => 'too short'],
            ['Field' => 'email',    'Rule' => 'Email', 'Value' => 'a@b.co'],
        ],
    );

    $body = json_decode($resp->getContent() ?: '', true);
    $items = $body['Attributes']['Error']['Details'];

    expect($items[0]['Value'])->toBe('***REDACTED***');
    expect($items[0]['Message'])->toBe('***REDACTED***');
    // Never leak the raw secret anywhere in the payload.
    expect($resp->getContent())->not->toContain('hunter2');
    // Non-sensitive item passes through untouched.
    expect($items[1]['Value'])->toBe('a@b.co');
});
