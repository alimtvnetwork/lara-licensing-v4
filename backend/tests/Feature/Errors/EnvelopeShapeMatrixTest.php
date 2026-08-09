<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use App\Exceptions\LaraException;
use App\Support\ApiEnvelope;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;


/**
 * Plan 11 step 11 (AC-ENV-001..004).
 *
 * Root cause guarded: no single test asserted the canonical envelope shape
 * `{Status, Attributes:{RequestId}, Results:[]}` across the full HTTP status
 * matrix (200/400/401/403/404/409/428/429/500), so a future renderer change
 * (bootstrap/app.php) could silently break the shape for a rarely-hit code
 * while individual controller tests still passed.
 *
 * Strategy: register ephemeral test-only routes that throw a chosen closed-set
 * error code (or return success), hit each, and lock the invariant.
 *
 * Note on 422: no closed-set error code maps to 422 (see
 * `backend/config/lara.php::error_codes`), because Laravel's ValidationException
 * is re-shaped to 400 `ValidationInputInvalid` in bootstrap/app.php. 428 is
 * covered via `LoginCaptchaRequired`, which stands in for the "extra
 * precondition" bucket.
 */
final class EnvelopeShapeMatrixTest extends TestCase
{
    /** @var array<int, array{level:string, message:string, context:array<string,mixed>}> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();


        Route::get('/Api/__test/envelope/success', function (Request $request) {
            $requestId = (string) $request->attributes->get('lara.request_id', 'test');

            return ApiEnvelope::success(results: [['Ok' => true]], requestId: $requestId);
        });

        // One route per closed-set code that maps to a target HTTP status.
        $codes = [
            'ValidationInputInvalid', // 400
            'AuthUnauthorized',        // 401
            'AuthForbidden',           // 403
            'LicenseNotFound',         // 404
            'LicenseConflict',         // 409
            'LoginCaptchaRequired',    // 428
            'RateLimited',             // 429
            'ServerError',             // 500
        ];
        foreach ($codes as $code) {
            Route::get('/Api/__test/envelope/fail/' . $code, function () use ($code) {
                throw LaraException::make($code, 'Forced ' . $code, []);
            });
        }

        // Unhandled Throwable path (feeds the \Throwable renderer -> 500).
        Route::get('/Api/__test/envelope/unhandled', function () {
            throw new RuntimeException('boom');
        });
    }

    public function test_success_envelope_shape(): void
    {
        $res = $this->getJson('/Api/__test/envelope/success', ['X-Request-Id' => 'req-success-1234567']);
        $res->assertStatus(200);
        $this->assertEnvelope($res->json(), expectSuccess: true, requestId: 'req-success-1234567');
    }

    /**
     * @dataProvider closedSetMatrix
     */
    public function test_failure_envelope_shape_for_code(string $code, int $status): void
    {
        $requestId = 'req-' . strtolower($code) . '-000000';
        // Pad to satisfy RequestIdMiddleware regex ^[A-Za-z0-9-]{16,64}$.
        $requestId = str_pad($requestId, 20, '0');
        $res = $this->getJson('/Api/__test/envelope/fail/' . $code, ['X-Request-Id' => $requestId]);
        $res->assertStatus($status);
        $json = $res->json();
        $this->assertEnvelope($json, expectSuccess: false, requestId: $requestId);
        $this->assertSame($code, $json['Attributes']['Error']['ErrorCode'] ?? null);
    }

    public function test_unhandled_throwable_produces_500_envelope_with_error_id_matching_log(): void
    {
        $this->startLogCapture();
        $res = $this->getJson('/Api/__test/envelope/unhandled', ['X-Request-Id' => 'req-unhandled-00000000']);
        $res->assertStatus(500);
        $json = $res->json();
        $this->assertEnvelope($json, expectSuccess: false, requestId: 'req-unhandled-00000000');
        $this->assertSame('ServerError', $json['Attributes']['Error']['ErrorCode'] ?? null);
        // Step 12: 5xx envelope carries Attributes.ErrorId, and it matches the id
        // logged by the \Throwable renderer to `lara.unhandled` in bootstrap/app.php.
        $envelopeErrorId = $json['Attributes']['ErrorId'] ?? null;
        $this->assertIsString($envelopeErrorId);
        $this->assertNotSame('', $envelopeErrorId);
        $loggedErrorId = $this->findLoggedErrorId('lara.unhandled');
        $this->assertSame($envelopeErrorId, $loggedErrorId, 'Envelope ErrorId must match the id logged for correlation.');
        // Response body MUST NOT leak the stack trace.
        $this->assertArrayNotHasKey('Trace', $json['Attributes']);
        $this->assertStringNotContainsString('"Trace"', json_encode($json, JSON_UNESCAPED_SLASHES));
    }

    public function test_lara_exception_5xx_envelope_carries_error_id_matching_log(): void
    {
        $this->startLogCapture();
        $res = $this->getJson('/Api/__test/envelope/fail/ServerError', ['X-Request-Id' => 'req-larafive-00000000']);
        $res->assertStatus(500);
        $json = $res->json();
        $envelopeErrorId = $json['Attributes']['ErrorId'] ?? null;
        $this->assertIsString($envelopeErrorId, 'Envelope must expose Attributes.ErrorId for LaraException 5xx.');
        $this->assertNotSame('', $envelopeErrorId);
        // LaraException renderer emits `lara.exception` at warning level.
        $loggedErrorId = $this->findLoggedErrorId('lara.exception');
        $this->assertSame($envelopeErrorId, $loggedErrorId, 'LaraException 5xx envelope ErrorId must match the logged ErrorId.');
    }

    /**
     * Plan 12 continuation: iterate EVERY closed-set 5xx code (not just
     * ServerError) and assert the response `Attributes.ErrorId` is a
     * non-empty UUIDv4-shaped string that matches the id logged by the
     * LaraException renderer as `lara.exception`. Guards against a
     * regression where a specific 5xx code (e.g. `UpdateAssetUploadFailed`
     * 502 or `ServiceUnavailable` 503) drifts and stops carrying / logging
     * the correlation handle while the 500 path still passes.
     *
     * @dataProvider fiveHundredMatrix
     */
    public function test_every_5xx_envelope_error_id_matches_logged_error_id(string $code, int $status): void
    {
        // Register the throw-route lazily so this test does not depend on
        // setUp() adding every 5xx code. Route names must be unique per
        // code so re-runs under --repeat do not collide.
        Route::get('/Api/__test/envelope/fail5xx/' . $code, function () use ($code) {
            throw LaraException::make($code, 'Forced ' . $code, []);
        });

        $this->startLogCapture();
        $requestId = str_pad('req-5xx-' . strtolower($code), 20, '0');
        // RequestIdMiddleware caps at 64 chars; long code names must be trimmed.
        $requestId = substr($requestId, 0, 64);

        $res = $this->getJson('/Api/__test/envelope/fail5xx/' . $code, ['X-Request-Id' => $requestId]);
        $res->assertStatus($status);
        $json = $res->json();

        $this->assertEnvelope($json, expectSuccess: false, requestId: $requestId);
        $this->assertSame($code, $json['Attributes']['Error']['ErrorCode'] ?? null);

        $envelopeErrorId = $json['Attributes']['ErrorId'] ?? null;
        $this->assertIsString($envelopeErrorId, "5xx code {$code} must expose Attributes.ErrorId.");
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $envelopeErrorId,
            "5xx code {$code} ErrorId must be a UUIDv4 string."
        );

        $loggedErrorId = $this->findLoggedErrorId('lara.exception');
        $this->assertSame(
            $envelopeErrorId,
            $loggedErrorId,
            "5xx code {$code}: envelope Attributes.ErrorId must equal the id logged as `lara.exception` context.ErrorId."
        );

        // Response MUST NOT leak stack trace for any 5xx code.
        $this->assertArrayNotHasKey('Trace', $json['Attributes']);
    }

    /**
     * Every closed-set code with a 5xx status in `config/lara.php`.
     * Keep in sync with the config; the CoreExceptionEnvelopeParityPest
     * test guards the code-to-status mapping itself.
     *
     * @return array<string, array{0:string,1:int}>
     */
    public static function fiveHundredMatrix(): array
    {
        return [
            'AuthSaltRotationFailed'      => ['AuthSaltRotationFailed', 500],
            'FeatureCatalogUnseeded'      => ['FeatureCatalogUnseeded', 500],
            'FeatureNotAvailable-501'     => ['FeatureNotAvailable', 501],
            'QuotaLedgerConflict'         => ['QuotaLedgerConflict', 500],
            'ServerError'                 => ['ServerError', 500],
            'ServiceUnavailable-503'      => ['ServiceUnavailable', 503],
            'UnknownServerError'          => ['UnknownServerError', 500],
            'UpdateAssetUploadFailed-502' => ['UpdateAssetUploadFailed', 502],
            'UpdateDownloadFailed'        => ['UpdateDownloadFailed', 500],
            'UpdateManifestUnavailable-503' => ['UpdateManifestUnavailable', 503],
        ];
    }

    public function test_lara_exception_4xx_envelope_exposes_error_id(): void
    {
        // v1.1 (v0.671.0): 4xx envelopes now carry Attributes.ErrorId so
        // support can correlate a caller-reported failure with the matching
        // `lara.exception` log entry, regardless of HTTP class. UUIDv4 shape
        // is guarded by ErrorIdUuidV4ShapeTest.
        $res = $this->getJson('/Api/__test/envelope/fail/AuthForbidden', ['X-Request-Id' => 'req-forbid-000000000']);
        $res->assertStatus(403);
        $json = $res->json();
        $this->assertArrayHasKey('ErrorId', $json['Attributes']);
        $envelopeErrorId = (string) $json['Attributes']['ErrorId'];
        $this->assertNotSame('', $envelopeErrorId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $envelopeErrorId,
            '4xx ErrorId must be a UUIDv4 string.'
        );
    }


    /**
     * Register a MessageLogged listener that snapshots log records into
     * `$this->capturedLogs`. Uses a class property so the listener closure
     * mutates state visible to the calling test after the HTTP request runs.
     */
    private function startLogCapture(): void
    {
        $this->capturedLogs = [];
        Event::listen(function (MessageLogged $event): void {
            $this->capturedLogs[] = [
                'level' => (string) $event->level,
                'message' => (string) $event->message,
                'context' => (array) $event->context,
            ];
        });
    }

    private function findLoggedErrorId(string $message): ?string
    {
        foreach ($this->capturedLogs as $rec) {
            if ($rec['message'] === $message && isset($rec['context']['ErrorId'])) {
                return (string) $rec['context']['ErrorId'];
            }
        }

        return null;
    }



    public static function closedSetMatrix(): array
    {
        return [
            '400' => ['ValidationInputInvalid', 400],
            '401' => ['AuthUnauthorized', 401],
            '403' => ['AuthForbidden', 403],
            '404' => ['LicenseNotFound', 404],
            '409' => ['LicenseConflict', 409],
            '428' => ['LoginCaptchaRequired', 428],
            '429' => ['RateLimited', 429],
            '500' => ['ServerError', 500],
        ];
    }

    private function assertEnvelope(mixed $json, bool $expectSuccess, string $requestId): void
    {
        $this->assertIsArray($json);
        $this->assertSame(['Status', 'Attributes', 'Results'], array_keys($json), 'Envelope key order must be Status, Attributes, Results.');
        $this->assertIsArray($json['Status']);
        $this->assertIsArray($json['Attributes']);
        $this->assertIsArray($json['Results']);
        $this->assertSame($expectSuccess, $json['Status']['IsSuccess'] ?? null);
        $this->assertArrayHasKey('RequestId', $json['Attributes']);
        $this->assertSame($requestId, $json['Attributes']['RequestId']);
        if (! $expectSuccess) {
            $this->assertSame([], $json['Results']);
            $this->assertArrayHasKey('Error', $json['Attributes']);
            $this->assertArrayHasKey('ErrorCode', $json['Attributes']['Error']);
            $this->assertArrayHasKey('ErrorMessage', $json['Attributes']['Error']);
        }
    }
}
