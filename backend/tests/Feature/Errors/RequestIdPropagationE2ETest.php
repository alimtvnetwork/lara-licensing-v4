<?php

declare(strict_types=1);

namespace Tests\Feature\Errors;

use App\Exceptions\LaraException;
use App\Support\ApiEnvelope;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * End-to-end propagation test for the `X-Request-Id` header, mirroring
 * the FE contract in `src/lib/lara-api-client.ts` (generateRequestId +
 * REQUEST_ID_HEADER) and `src/lib/lara-api-response.ts` (HEADER.RequestId
 * + Attributes.RequestId parser).
 *
 * Root cause this guards: `RequestIdMiddleware` binds the inbound id
 * to three surfaces at once (response header, `Attributes.RequestId`
 * in the envelope, and `Log::withContext(['RequestId' => $id])`).
 * If any of the three drifts (for example a controller mints its own
 * id, or `withContext` is dropped by a bootstrap edit), the FE
 * `GlobalErrorModal` correlation link and the operator log-fishing
 * runbook (`docs/operator/error-id-log-correlation.md`) silently
 * break: caller reports a `RequestId`, but no log line carries it.
 *
 * Contract asserted end-to-end for a single request:
 *   1. Response header `X-Request-Id` == inbound value (FE-minted).
 *   2. Envelope `Attributes.RequestId` == inbound value.
 *   3. Every log record emitted during the request has `RequestId`
 *      in its context (via `Log::withContext`) equal to inbound
 *      value, including the middleware's own `http.request` info line.
 *   4. On the LaraException 5xx path, the `lara-diag` daily channel
 *      file contains the same `RequestId` (diagnostic trace + id
 *      travel together per Plan 11 step 7).
 *   5. Missing header on a non-strict path -> server mints a
 *      UUIDv4, all three surfaces agree, and the same id lands in
 *      the log context.
 *   6. Missing header on a strict path (`/api/admin/*`) -> the
 *      middleware throws `RequestIdMissing`, the envelope carries
 *      that ErrorCode, and the caller-facing header still echoes an
 *      id (so a support agent has something to grep in the logs).
 *
 * Spec anchors: spec/21-app/20-observability.md v1.0.0 (RequestId
 * ingress/echo), spec/03-error-manage/02-error-architecture (log
 * correlation), AC-ERR-004, AC-OBS-*.
 */
final class RequestIdPropagationE2ETest extends TestCase
{
    /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Success route: reads the middleware-bound attribute and echoes it
        // back via the canonical envelope. Mirrors what real controllers do.
        Route::get('/Api/__test/reqid/success', function (Request $request) {
            $requestId = (string) $request->attributes->get('lara.request_id', '');

            return ApiEnvelope::success(results: [['Ok' => true]], requestId: $requestId);
        });

        // Failure route: throws a LaraException with a 5xx-mapped code so
        // the exception renderer runs the lara-diag trace path.
        Route::get('/Api/__test/reqid/fail', function () {
            throw LaraException::make('ServerError', 'Forced for propagation test', []);
        });
    }

    public function test_fe_minted_request_id_propagates_to_header_envelope_and_log_context(): void
    {
        $this->startLogCapture();
        // Shaped like `crypto.randomUUID()` on the FE. Satisfies the
        // middleware regex ^[A-Za-z0-9-]{16,64}$.
        $feRequestId = '11111111-2222-4333-8444-555555555555';

        $res = $this->getJson('/Api/__test/reqid/success', ['X-Request-Id' => $feRequestId]);

        $res->assertStatus(200);
        $this->assertSame($feRequestId, $res->headers->get('X-Request-Id'), 'Response header must echo FE-minted X-Request-Id.');

        $json = $res->json();
        $this->assertSame($feRequestId, $json['Attributes']['RequestId'] ?? null, 'Envelope Attributes.RequestId must equal FE-minted id.');

        $this->assertLogContextContainsRequestId($feRequestId, atLeastMessage: 'http.request');
    }

    public function test_missing_header_on_non_strict_path_mints_server_uuid_and_propagates_it_to_all_surfaces(): void
    {
        $this->startLogCapture();

        $res = $this->getJson('/Api/__test/reqid/success');

        $res->assertStatus(200);
        $headerId = $res->headers->get('X-Request-Id');
        $this->assertIsString($headerId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $headerId, 'Server-minted id must be a UUIDv4.');

        $envelopeId = $res->json()['Attributes']['RequestId'] ?? null;
        $this->assertSame($headerId, $envelopeId, 'Envelope RequestId must equal the header id when server mints it.');

        $this->assertLogContextContainsRequestId((string) $headerId, atLeastMessage: 'http.request');
    }

    public function test_lara_exception_path_writes_request_id_to_lara_diag_daily_file(): void
    {
        $this->startLogCapture();
        $feRequestId = 'req-diag-aaaaaaaaaaaaaaaaaa';

        $res = $this->getJson('/Api/__test/reqid/fail', ['X-Request-Id' => $feRequestId]);

        $res->assertStatus(500);
        $this->assertSame($feRequestId, $res->headers->get('X-Request-Id'));
        $json = $res->json();
        $this->assertSame($feRequestId, $json['Attributes']['RequestId'] ?? null);
        $this->assertSame('ServerError', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $envelopeErrorId = $json['Attributes']['ErrorId'] ?? null;
        $this->assertIsString($envelopeErrorId, 'ServerError envelope must expose Attributes.ErrorId for correlation.');

        // Log context carries the FE id (bound by RequestIdMiddleware via
        // Log::withContext) on every record for this request.
        $this->assertLogContextContainsRequestId($feRequestId, atLeastMessage: 'http.request');

        // The lara-diag daily file must contain the FE-minted RequestId and
        // the same ErrorId, so operators can grep either one and land on
        // the same trace.
        $today = date('Y-m-d');
        $diagPath = storage_path("logs/lara-diag-{$today}.log");
        $this->assertTrue(File::exists($diagPath), "Expected lara-diag daily file at {$diagPath} after LaraException render.");
        $diagContents = (string) File::get($diagPath);
        $this->assertStringContainsString($feRequestId, $diagContents, 'lara-diag must record the RequestId for log-fishing correlation.');
        $this->assertStringContainsString($envelopeErrorId, $diagContents, 'lara-diag must record the ErrorId alongside the RequestId.');
    }

    public function test_strict_path_without_header_returns_request_id_missing_envelope(): void
    {
        $this->startLogCapture();

        // /api/admin/* is a strict-list prefix in RequestIdMiddleware.
        // The middleware throws before route dispatch, so the admin
        // route does not need to exist for this test.
        $res = $this->getJson('/api/admin/does-not-matter');

        $json = $res->json();
        $this->assertSame('RequestIdMissing', $json['Attributes']['Error']['ErrorCode'] ?? null, 'Strict path without X-Request-Id must throw RequestIdMissing.');

        // v1.1 fix: the middleware now mints a fallback UUIDv4 and binds
        // it to attribute + Log context BEFORE throwing, so the failure
        // envelope, response header, and log context all correlate on
        // the same id (AC-ERR-004). This gives the support agent a
        // grep handle even on the strict-path failure path.
        $headerId = (string) $res->headers->get('X-Request-Id');
        $envelopeId = (string) ($json['Attributes']['RequestId'] ?? '');
        $this->assertNotSame('', $headerId, 'Response header must echo a fallback X-Request-Id even on RequestIdMissing.');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $headerId, 'Fallback id must be a UUIDv4.');
        $this->assertSame($headerId, $envelopeId, 'Envelope RequestId must equal the response header id.');
        $this->assertLogContextContainsRequestId($headerId, atLeastMessage: 'lara.exception');
    }

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

    /**
     * Asserts that at least one captured log record has the given
     * message and that its context binds `RequestId` to `$expected`.
     * Also asserts that no captured record carries a different
     * `RequestId` (guards against a controller that resets the shared
     * context mid-request).
     */
    private function assertLogContextContainsRequestId(string $expected, string $atLeastMessage): void
    {
        $sawTargetMessage = false;
        foreach ($this->capturedLogs as $rec) {
            $ctxId = $rec['context']['RequestId'] ?? null;
            if ($rec['message'] === $atLeastMessage) {
                $sawTargetMessage = true;
                $this->assertSame($expected, $ctxId, "Log record '{$atLeastMessage}' must carry RequestId={$expected} in context.");
            }
            if ($ctxId !== null) {
                $this->assertSame($expected, (string) $ctxId, 'A different RequestId leaked into a log record; middleware context must be stable for the whole request.');
            }
        }
        $this->assertTrue($sawTargetMessage, "Expected at least one '{$atLeastMessage}' log record during the request.");
    }
}
