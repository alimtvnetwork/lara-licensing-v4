import axios from 'axios';
import { isMutatingMethod, mintRequestId } from '@/lib/lara-request-id';
import { captureEtag } from '@/lib/lara-etag';
import { idempotencyKeyFor, releaseAttempt } from '@/lib/lara-idempotency';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Plan 06 step 78. `VerifyCsrfToken` accepts either the `X-XSRF-TOKEN` cookie
 * mirror or the `X-CSRF-TOKEN` header. Axios only auto-sends the former, which
 * is absent whenever the cookie is blocked or the console is served from a
 * different host than the cookie domain; reading the `csrf-token` meta rendered
 * by `resources/views/app.blade.php` makes the header path explicit.
 */
const csrfToken = document
    .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
    ?.content;
if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}


/**
 * Plan 06 step 74. Inertia's router uses this same axios module singleton, so a
 * request interceptor registered here covers both `router.post/patch/delete`
 * visits and any direct `axios` call from a page component.
 *
 * `RequestIdMiddleware::resolveRequestId()` mints a server-side fallback when
 * the header is absent, which means the browser has no id to echo back to the
 * operator on failure. Injecting it client-side makes the id the UI shows and
 * the id in `Log::withContext(['RequestId' => ...])` the same value.
 *
 * Existing headers win: `LicenseDetailActions` and friends go through
 * `laraRequest()` in `lib/lara-api.ts`, which already sets its own id.
 */
window.axios.interceptors.request.use((config) => {
    if (!isMutatingMethod(config.method)) return config;
    const existing = config.headers?.['X-Request-Id'];
    if (existing === undefined || existing === null || existing === '') {
        config.headers.set('X-Request-Id', mintRequestId());
    }
    /**
     * Plan 06 step 76. Inertia `router.post/patch/delete` visits never touch
     * `laraRequest()`, so without this they arrive at
     * `IdempotencyKeyMiddleware::REQUIRED_PREFIXES` routes with no key and 400
     * with `IdempotencyKeyRequired`. The key is derived from the attempt
     * (method + url + body), so a retry of the same visit reuses it.
     */
    const key = config.headers?.['Idempotency-Key'];
    if (key === undefined || key === null || key === '') {
        config.headers.set(
            'Idempotency-Key',
            idempotencyKeyFor(String(config.method ?? 'POST'), String(config.url ?? ''), config.data),
        );
    }
    return config;
});

/**
 * Plan 06 step 75. Inertia visits and direct axios reads go through this same
 * singleton, so capturing `ETag` here keeps `lib/lara-etag.ts` fresh even when a
 * page never touches `laraRequest()`. Errors are re-thrown untouched: a failed
 * response carries no validator worth caching.
 */
window.axios.interceptors.response.use((response) => {
    captureEtag(String(response.config?.url ?? ''), response.headers as Record<string, unknown>);
    // Step 76: a confirmed response closes the attempt; failures deliberately
    // keep the key so the retry replays instead of re-executing.
    const method = String(response.config?.method ?? '');
    if (isMutatingMethod(method)) {
        releaseAttempt(method, String(response.config?.url ?? ''), response.config?.data);
    }
    return response;
});
