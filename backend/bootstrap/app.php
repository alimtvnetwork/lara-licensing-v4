<?php

use App\Exceptions\LaraException;
use App\Support\ApiEnvelope;
use App\Support\DetailsRedactor;
use App\Support\TraceRedactor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Plan 06 step 44 (AC-IMP-006, spec 47 §4). Fixed 15s cadence.
        $schedule->command('impersonation:timeout-sweep')
            ->everyFifteenSeconds()
            ->withoutOverlapping(60)
            ->runInBackground();
        // Plan 06 step 60 (spec 21/17 §"Upload ticket expiry"). Reclaims
        // orphaned upload tickets so the UX_AppUpdateAssets_TicketTriple
        // partial unique index does not permanently block retries of the
        // same (Product, Version, Platform) triple after a failed PUT.
        $schedule->command('retention:sweep-orphan-tickets')
            ->everyFifteenMinutes()
            ->withoutOverlapping(600)
            ->runInBackground();
        // v0.298.0 (spec 31). Daily AuthSessions retention sweep.
        $schedule->command('auth:sessions-retention-sweep')
            ->dailyAt('03:15')
            ->withoutOverlapping(3600)
            ->runInBackground();

    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Plan 06 step 78: nonce must be in the attribute bag before
            // HandleInertiaRequests renders app.blade.php.
            \App\Http\Middleware\ContentSecurityPolicyMiddleware::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);


        // Global middleware registered in Plan 06 steps 7-9.
        $middleware->append(\App\Http\Middleware\RequestIdMiddleware::class);

        $middleware->append(\App\Http\Middleware\IdempotencyKeyMiddleware::class);
        $middleware->append(\App\Http\Middleware\EtagMiddleware::class);
        // Plan 11 step 23 (AC-ENV-001). Dev + test guardrail. Prod skips
        // itself via app()->environment() check inside the middleware.
        if (app()->environment(['local', 'testing'])) {
            $middleware->append(\App\Http\Middleware\AssertEnvelopeMiddleware::class);
        }
        // Plan 06 step 25: RBAC gate for individual routes.
        // Plan 06 step 25 + step 28: named middleware aliases.
        $middleware->alias([
            'require.role' => \App\Http\Middleware\RequireRoleMiddleware::class,
            'require.signature' => \App\Http\Middleware\SignedRequestMiddleware::class,
            'session.active' => \App\Http\Middleware\AssertActiveSessionMiddleware::class,
            // v0.297.0: throttle the unauthenticated auth surface.
            'rate.auth' => \App\Http\Middleware\RateLimitAuthMiddleware::class,
            'casbin.pep' => \App\Http\Middleware\CasbinPepMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Plan 06 step 6: LaraException -> envelope; unknown -> ServerError.
        $exceptions->render(function (LaraException $e, Request $request) {
            $requestId = (string) ($request->attributes->get('lara.request_id') ?: ($request->headers->get('X-Request-Id') ?? ''));
            $category = match (true) {
                $e instanceof \App\Exceptions\AuthException => 'Auth',
                $e instanceof \App\Exceptions\ValidationException => 'Validation',
                $e instanceof \App\Exceptions\RateLimitException => 'RateLimit',
                $e instanceof \App\Exceptions\DomainConflictException => 'DomainConflict',
                $e instanceof \App\Exceptions\NotFoundException => 'NotFound',
                $e instanceof \App\Exceptions\InternalException => 'Internal',
                default => 'Internal',
            };
            
            Log::warning('lara.exception', [
                'RequestId' => $requestId,
                'ErrorId' => $e->errorId,
                'ErrorCode' => $e->errorCode,
                'HttpStatus' => $e->httpStatus,
                'Route' => optional($request->route())->uri(),
                'Method' => $request->method(),
                'OperationId' => $request->headers->get('X-Lara-Operation'),
                // Plan 11 step 33: include Details in the observability log
                // (support needs to see WHICH field failed) but redact
                // sensitive Value/Message entries first. Same choke point
                // rule as ApiEnvelope::failure so the caller-visible envelope
                // and the operator-visible log agree on what is masked.
                'Details' => DetailsRedactor::redact($e->details),
            ]);
            // Plan 11 SS-01: full trace goes to lara-diag only; never to caller.
            Log::channel('lara-diag')->debug('lara.exception.trace', [
                'RequestId' => $requestId,
                'ErrorId' => $e->errorId,
                'ErrorCode' => $e->errorCode,
                'Exception' => $e::class,
                'Trace' => TraceRedactor::redactString($e),
                'Previous' => optional($e->getPrevious())?->getMessage(),
            ]);

            // Plan 18 Step 93/101: audit trail for admin errors UI
            Log::channel('lara-audit-errors')->info('lara.exception', [
                'RequestedAt' => now()->toIso8601String(),
                'RequestId' => $requestId,
                'ErrorId' => $e->errorId,
                'ErrorCode' => $e->errorCode,
                'Category' => $category,
                'HttpStatus' => $e->httpStatus,
                'OperationId' => $request->headers->get('X-Lara-Operation'),
                'Exception' => $e::class,
                'Details' => DetailsRedactor::redact($e->details),
                'Trace' => TraceRedactor::redactString($e),
            ]);
            // v1.1 (v0.671.0): expose ErrorId on ALL LaraException responses,
            // 4xx and 5xx alike. Prior policy hid it below 500 to shrink the
            // log-fishing surface, but spec/03 audit input #5 asks the
            // opposite: support needs a correlation handle for every failed
            // call, not just server-side ones. The id is a UUIDv4 already
            // minted per exception and is safe to echo. lara-diag remains
            // the only place the redacted trace lives.
            // Plan 06 step 77: web (Inertia) navigations must not receive the
            // JSON envelope; they get Pages/Error.tsx with spec 12 copy plus the
            // RequestId/ErrorId correlation handles.
            if (! $request->expectsJson() && ! $request->is('Api/*', 'App/*')) {
                return \Inertia\Inertia::render('Error', [
                    'status' => $e->httpStatus,
                    'errorCode' => $e->errorCode,
                    'requestId' => $requestId !== '' ? $requestId : null,
                    'errorId' => $e->errorId,
                    'retryAfterSeconds' => isset($e->headers['Retry-After'])
                        ? (int) $e->headers['Retry-After']
                        : null,
                ])->toResponse($request)->setStatusCode($e->httpStatus);
            }

            $response = ApiEnvelope::failure(

                errorCode: $e->errorCode,
                errorMessage: $e->getMessage(),
                requestId: $requestId,
                httpCode: $e->httpStatus,
                message: $e->errorCode,
                extraAttributes: [
                    'ErrorId' => $e->errorId,
                    'Category' => $category,
                    'OperationId' => $request->headers->get('X-Lara-Operation'),
                ],
                details: $e->details,
            );


            // v0.297.0: propagate extra headers (Retry-After for RateLimited, etc.).
            foreach ($e->headers as $headerName => $headerValue) {
                $response->headers->set((string) $headerName, (string) $headerValue);
            }
            // Echo X-Request-Id on error responses too, so caller has a
            // grep handle even when the middleware threw before $next().
            if ($requestId !== '') {
                $response->headers->set('X-Request-Id', $requestId);
            }
            $response->headers->set('X-Error-Id', $e->errorId);
            return $response;
        });


        // Plan 06 step 47 (AC-ENV-001). Laravel's built-in renderers short-circuit
        // before our generic \Throwable handler, so ValidationException and
        // AuthenticationException would otherwise emit non-enveloped JSON
        // ({message, errors}) that breaks the {Status, Attributes, Results} shape.
        // Root cause: FormRequest / auth:sanctum throw exceptions that Laravel
        // converts to responses via its own renderer; we intercept both and
        // route them through ApiEnvelope::failure.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('Api/*', 'App/*')) {
                return null;
            }
            $requestId = (string) ($request->attributes->get('lara.request_id') ?: ($request->headers->get('X-Request-Id') ?? ''));
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $msg) {
                    $details[] = ['Field' => (string) $field, 'Rule' => 'Invalid', 'Message' => (string) $msg];
                }
            }
            return ApiEnvelope::failure(
                errorCode: 'ValidationInputInvalid',
                errorMessage: 'Request payload failed validation.',
                requestId: $requestId,
                httpCode: 400,
                message: 'ValidationInputInvalid',
                details: $details,
            );
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('Api/*', 'App/*')) {
                return null;
            }
            $requestId = (string) ($request->attributes->get('lara.request_id') ?: ($request->headers->get('X-Request-Id') ?? ''));
            return ApiEnvelope::failure(
                errorCode: 'AuthUnauthorized',
                errorMessage: 'Authentication is required to access this endpoint.',
                requestId: $requestId,
                httpCode: 401,
                message: 'AuthUnauthorized',
            );
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            // Only fires if no earlier renderer matched.
            if ($e instanceof LaraException) {
                return null; // handled above; defensive
            }
            $requestId = (string) ($request->attributes->get('lara.request_id') ?: ($request->headers->get('X-Request-Id') ?? ''));
            $errorId = bin2hex(random_bytes(8));
            Log::error('lara.unhandled', [
                'RequestId' => $requestId,
                'ErrorId' => $errorId,
                'ErrorCode' => 'ServerError',
                'HttpStatus' => 500,
                'Exception' => $e::class,
                'Message' => $e->getMessage(),
                'Route' => optional($request->route())->uri(),
                'Method' => $request->method(),
            ]);
            // Plan 11 SS-01: unhandled trace to lara-diag; response envelope stays trace-free.
            Log::channel('lara-diag')->error('lara.unhandled.trace', [
                'RequestId' => $requestId,
                'ErrorId' => $errorId,
                'Exception' => $e::class,
                'Trace' => TraceRedactor::redactString($e),
                'Previous' => optional($e->getPrevious())?->getMessage(),
            ]);
            // Plan 06 step 77: same web/JSON split as the LaraException renderer.
            if (! $request->expectsJson() && ! $request->is('Api/*', 'App/*')) {
                return \Inertia\Inertia::render('Error', [
                    'status' => 500,
                    'errorCode' => 'ServerError',
                    'requestId' => $requestId !== '' ? $requestId : null,
                    'errorId' => $errorId,
                    'retryAfterSeconds' => null,
                ])->toResponse($request)->setStatusCode(500);
            }
            return ApiEnvelope::failure(

                errorCode: 'ServerError',
                errorMessage: 'Internal server error. Reference errorId in logs.',
                requestId: $requestId,
                httpCode: 500,
                message: 'ServerError',
                extraAttributes: ['ErrorId' => $errorId],
            );
        });
    })
    ->create();
