<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan 06 step 78. Content-Security-Policy for the Inertia console per
 * spec/19-main-worker-service/12-jwt-delivery-contract.md lines 83-98: the
 * access token lives in memory, which only beats localStorage if a CSP blocks
 * the XSS exfiltration vectors.
 *
 * A per-request nonce is minted and stashed in the request attribute bag so
 * `resources/views/app.blade.php` can stamp it onto `@vite` / `@viteReactRefresh`
 * / `@inertiaHead` inline scripts. Without the nonce a strict `script-src 'self'`
 * blanks the console, so the nonce and the header MUST come from the same place.
 *
 * Dev (`local`) additionally needs `'unsafe-eval'` and the Vite dev server
 * origin for HMR websockets; production emits the strict policy.
 */
final class ContentSecurityPolicyMiddleware
{
    public const ATTR = 'lara.csp_nonce';

    private const HEADER = 'Content-Security-Policy';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes->set(self::ATTR, $nonce);

        /** @var Response $response */
        $response = $next($request);

        // JSON API responses are not documents; a CSP on them is noise and
        // would not be enforced by the browser anyway.
        if ($request->is('Api/*', 'App/*')) {
            return $response;
        }

        // Never clobber a policy set upstream (edge/CDN) or by a test.
        if ($response->headers->has(self::HEADER)) {
            return $response;
        }

        $response->headers->set(self::HEADER, $this->policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    private function policy(string $nonce): string
    {
        $isDev = app()->environment(['local', 'testing']);

        $script = "'self' 'nonce-{$nonce}'";
        $connect = "'self'";
        if ($isDev) {
            // Vite dev server serves the module graph and the HMR socket.
            $script .= " 'unsafe-eval'";
            $connect .= ' ws: http://localhost:5173 http://127.0.0.1:5173';
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            // Tailwind injects style elements at runtime in dev; the nonce
            // covers our own inline styles in prod.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src {$connect}",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "object-src 'none'",
            "form-action 'self'",
        ]);
    }
}
