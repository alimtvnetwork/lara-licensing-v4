<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;


/**
 * Universal response envelope.
 *
 * Canonical shape per spec/21-app/11-api-contracts/05-envelope-schema.md v1.1.0:
 *
 *   { "Status": {...}, "Attributes": {...}, "Results": [...] }
 *
 * Field order MUST be Status, Attributes, Results in every response.
 * All three keys are always present. Results is always an array (empty on failure,
 * exactly-one for single-resource endpoints, zero-or-more for list endpoints).
 *
 * ACs locked here:
 *  - AC-ENV-001: every JSON response validates this shape (2xx and 4xx/5xx).
 *  - AC-ENV-002: ErrorCode lives under Attributes.Error, NEVER under Status.
 *  - AC-ENV-003: Results is an array; empty [] on failure, never null.
 *  - AC-ENV-004: Attributes.RequestId echoes the X-Request-Id response header
 *                (header is set by RequestIdMiddleware, Plan 06 step 7).
 */
final class ApiEnvelope
{
    /**
     * Success envelope. $results MUST already be a plain array.
     * Single-resource endpoints pass [$item]; list endpoints pass the list.
     */
    public static function success(
        array $results,
        string $requestId,
        int $httpCode = 200,
        string $message = 'OK',
        array $extraAttributes = []
    ): JsonResponse {
        return self::respond(
            isSuccess: true,
            httpCode: $httpCode,
            message: $message,
            requestId: $requestId,
            results: $results,
            extraAttributes: $extraAttributes,
        );
    }

    /**
     * Failure envelope. Results is forced to [] per AC-ENV-003.
     * $errorCode MUST be a member of config('lara.error_codes') (Plan 06 step 3).
     */
    public static function failure(
        string $errorCode,
        string $errorMessage,
        string $requestId,
        int $httpCode,
        string $message,
        array $extraAttributes = [],
        array $details = []
    ): JsonResponse {
        $errorBlock = [
            'ErrorCode' => $errorCode,
            'ErrorMessage' => $errorMessage,
        ];
        if ($details !== []) {
            // Plan 11 step 33 (spec/03-error-manage §4.2, AC-ERR-005). Redact
            // sensitive `Value`/`Message` entries (password, token, otp, ...)
            // before they cross the API surface. Single choke point so every
            // failure envelope, whatever the throw site, is guaranteed safe.
            $errorBlock['Details'] = DetailsRedactor::redact($details);
        }

        return self::respond(
            isSuccess: false,
            httpCode: $httpCode,
            message: $message,
            requestId: $requestId,
            results: [],
            extraAttributes: array_merge($extraAttributes, ['Error' => $errorBlock]),
        );
    }

    private static function respond(
        bool $isSuccess,
        int $httpCode,
        string $message,
        string $requestId,
        array $results,
        array $extraAttributes,
    ): JsonResponse {
        // Field order MUST be Status, Attributes, Results. PHP preserves insertion order.
        $payload = [
            'Status' => [
                'IsSuccess' => $isSuccess,
                'Code' => $httpCode,
                'Message' => $message,
            ],
            'Attributes' => array_merge(
                [
                    'RequestId' => $requestId,
                    'RequestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                $extraAttributes,
            ),
            'Results' => $results,
        ];

        return new JsonResponse(
            data: $payload,
            status: $httpCode,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Request-Id' => $requestId,
            ],
            options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
