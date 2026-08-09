<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan 11 step 23 (AC-ENV-001).
 *
 * Dev + test guardrail. After a JSON response is produced for /Api/* or
 * /App/*, verify the body matches the canonical envelope:
 *
 *   { "Status": {...}, "Attributes": {"RequestId": ...}, "Results": [...] }
 *
 * Root cause this guards: any controller that returns response()->json([...])
 * directly, or any renderer that short-circuits ApiEnvelope::failure, will
 * leak a non-enveloped body. The bug is silent in prod but surfaces here as
 * a hard failure that stops the merge.
 *
 * Production intentionally does not run this: the check parses the JSON on
 * every request and we do not want that overhead. The failure mode we care
 * about is caught by CI, not by end users.
 */
final class AssertEnvelopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCheck($request, $response)) {
            return $response;
        }

        $body = $response instanceof JsonResponse
            ? $response->getData(true)
            : json_decode((string) $response->getContent(), true);

        $this->assertShape($request, $body, $response->getStatusCode());

        return $response;
    }

    private function shouldCheck(Request $request, Response $response): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }
        if (! $request->is('Api/*', 'App/*')) {
            return false;
        }
        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'application/json');
    }

    /**
     * @param  mixed  $body
     */
    private function assertShape(Request $request, $body, int $status): void
    {
        $missing = [];
        if (is_array($body) === false) {
            $missing[] = 'body-not-array';
        } else {
            foreach (['Status', 'Attributes', 'Results'] as $key) {
                if (array_key_exists($key, $body) === false) {
                    $missing[] = $key;
                }
            }
            if (isset($body['Attributes']) && ! array_key_exists('RequestId', (array) $body['Attributes'])) {
                $missing[] = 'Attributes.RequestId';
            }
            if (isset($body['Results']) && ! is_array($body['Results'])) {
                $missing[] = 'Results-not-array';
            }
        }

        if ($missing === []) {
            return;
        }

        $ctx = [
            'Path' => $request->path(),
            'Method' => $request->method(),
            'HttpStatus' => $status,
            'Missing' => $missing,
        ];
        Log::channel('lara-diag')->error('envelope.shape.violation', $ctx);
        throw new RuntimeException(
            'AssertEnvelopeMiddleware: response envelope violation ('
            .implode(',', $missing).') on '.$request->method().' /'.$request->path()
        );
    }
}
