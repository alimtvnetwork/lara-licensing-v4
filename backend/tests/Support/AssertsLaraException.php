<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Plan 10 step 15 (Pest matrix). Shared assertion helper for the canonical
 * LaraException envelope so every Feature test spells the invariant the same
 * way and drift in `App\Support\ApiEnvelope::failure()` is caught by one
 * change to this trait rather than N test files.
 *
 * Root cause guarded: prior to v0.354.0, each Feature test hand-rolled its
 * own combination of `assertStatus()` + `->json('Attributes.Error.ErrorCode')`
 * checks, so an envelope refactor (renaming a field, moving the code path,
 * dropping the `Results` array) could slip past several tests that happened
 * to check only one property.
 *
 * Contract: on a LaraException, the wire response is
 *   {
 *     "Status": { "IsSuccess": false, "RequestId": "..." },
 *     "Attributes": { "Error": { "ErrorCode": "...", "ErrorId": "...", "Details": [...] } },
 *     "Results": []
 *   }
 * with the HTTP status code bound to `ErrorCode` via `config('lara.error_http_status')`.
 */
trait AssertsLaraException
{
    /**
     * Assert the response is a well-formed LaraException envelope for the
     * given ErrorCode/HTTP status pair.
     *
     * @param  array<int, array{Field:string, Rule:string}>|null  $expectedDetailFields
     *         When non-null, asserts every listed (Field, Rule) tuple is present
     *         in `Attributes.Error.Details`. Extra details are allowed.
     */
    protected function assertLaraException(
        TestResponse $response,
        string $expectedErrorCode,
        int $expectedHttpStatus,
        ?array $expectedDetailFields = null,
    ): void {
        $response->assertStatus($expectedHttpStatus);
        $json = $response->json();

        Assert::assertIsArray($json, 'LaraException envelope must decode to an array.');
        Assert::assertArrayHasKey('Status', $json);
        Assert::assertArrayHasKey('Attributes', $json);
        Assert::assertArrayHasKey('Results', $json);

        Assert::assertFalse(
            $json['Status']['IsSuccess'] ?? true,
            'LaraException envelope must have Status.IsSuccess === false.',
        );
        Assert::assertSame([], $json['Results'], 'LaraException envelope must return Results === [].');

        $error = $json['Attributes']['Error'] ?? null;
        Assert::assertIsArray($error, 'Attributes.Error must be an object.');
        Assert::assertArrayHasKey('ErrorCode', $error, 'Attributes.Error.ErrorCode must be present.');
        Assert::assertSame(
            $expectedErrorCode,
            $error['ErrorCode'] ?? null,
            "Attributes.Error.ErrorCode expected '{$expectedErrorCode}'."
        );
        Assert::assertArrayHasKey('Category', $error, 'Attributes.Error.Category must be present.');
        // ErrorId is only emitted by the global LaraException handler; direct
        // ApiEnvelope::failure() call sites (e.g. HealthController) omit it.
        // When present, it must be a non-empty string.
        if (array_key_exists('ErrorId', $error)) {
            Assert::assertIsString($error['ErrorId']);
            Assert::assertNotSame('', $error['ErrorId'], 'When present, Attributes.Error.ErrorId must be non-empty.');
        }

        if ($expectedDetailFields !== null) {
            $actualDetails = $error['Details'] ?? [];
            Assert::assertIsArray($actualDetails);
            foreach ($expectedDetailFields as $needle) {
                $matched = false;
                foreach ($actualDetails as $row) {
                    if (
                        is_array($row)
                        && ($row['Field'] ?? null) === $needle['Field']
                        && ($row['Rule'] ?? null) === $needle['Rule']
                    ) {
                        $matched = true;
                        break;
                    }
                }
                Assert::assertTrue(
                    $matched,
                    "Attributes.Error.Details missing (Field='{$needle['Field']}', Rule='{$needle['Rule']}').",
                );
            }
        }
    }
}
