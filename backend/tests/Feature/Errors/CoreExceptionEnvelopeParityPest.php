<?php

declare(strict_types=1);

use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Route;

/**
 * Plan 11 step 47.
 *
 * Root cause guarded (one sentence): the existing `EnvelopeShapeMatrixTest`
 * locks the envelope shape for exactly one closed-set ErrorCode per HTTP
 * status bucket, so any of the 60+ other closed-set codes in
 * `config('lara.error_http_status')` could silently drift its code->status
 * binding, be renamed, or lose its exception-handler mapping while every
 * committed test kept passing.
 *
 * This Pest suite iterates the WHOLE closed-set catalog and, for every
 * ErrorCode, throws `LaraException::make($code, ...)` through a synthetic
 * route, then asserts the exception handler emits the canonical envelope
 * `{Status, Attributes:{Error:{ErrorCode}, RequestId}, Results:[]}` with:
 *
 *   - HTTP status equal to `config('lara.error_http_status')[$code].status`
 *   - `Attributes.Error.ErrorCode` equal to the thrown code (no rename drift)
 *   - `Status.IsSuccess === false`
 *   - `Results === []`
 *   - key order Status,Attributes,Results (envelope invariant)
 *
 * If a new closed-set code is added, this test exercises it for free. If a
 * code is renamed or its status changes without updating the config, this
 * test fails at the exact drifted code instead of a downstream feature.
 */

/**
 * Ephemeral route registered per test iteration. Route path encodes the
 * code so the URL is unique per case (avoids route cache collisions).
 */
function coreExcRegisterFailureRoute(string $code): string
{
    $path = '/Api/__test/core-exc/' . rawurlencode($code);
    Route::get($path, function () use ($code) {
        throw LaraException::make($code, 'Forced ' . $code, []);
    });

    return $path;
}

it('emits canonical envelope + matching ErrorCode for every closed-set code', function (string $code, int $expectedStatus): void {
    $path = coreExcRegisterFailureRoute($code);

    // Pad request id to satisfy RequestIdMiddleware regex ^[A-Za-z0-9-]{16,64}$.
    $requestId = str_pad('req-' . strtolower(preg_replace('/[^A-Za-z0-9-]/', '-', $code) ?? 'x'), 20, '0');

    $res = $this->getJson($path, ['X-Request-Id' => $requestId]);

    $res->assertStatus($expectedStatus);

    $json = $res->json();
    expect($json)->toBeArray()
        ->and(array_keys($json))->toBe(['Status', 'Attributes', 'Results'])
        ->and($json['Status']['IsSuccess'] ?? true)->toBeFalse()
        ->and($json['Results'])->toBe([])
        ->and($json['Attributes']['RequestId'] ?? null)->toBe($requestId)
        ->and($json['Attributes']['Error']['ErrorCode'] ?? null)->toBe($code);
})->with(function (): array {
    /** @var array<string, array{status:int}> $map */
    $map = (array) config('lara.error_http_status', []);
    $rows = [];
    foreach ($map as $code => $meta) {
        // MethodNotAllowed (405) cannot be thrown via LaraException::make from a
        // GET route without also short-circuiting routing; it is exercised by
        // the router's own 405 renderer elsewhere. Skip here to keep this test
        // focused on the domain-throw path.
        if ($code === 'MethodNotAllowed') {
            continue;
        }
        $status = (int) ($meta['status'] ?? 0);
        $rows[$code] = [$code, $status];
    }

    return $rows;
});
