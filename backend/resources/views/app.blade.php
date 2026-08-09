<!DOCTYPE html>
{{-- Plan 06 step 78: the nonce is minted by ContentSecurityPolicyMiddleware and
     read back here so the strict `script-src 'self' 'nonce-...'` policy does not
     block Vite's module tags or the Inertia head payload. --}}
@php($laraCspNonce = request()->attributes->get(\App\Http\Middleware\ContentSecurityPolicyMiddleware::ATTR, ''))
@php($laraCspNonce !== '' ? \Illuminate\Support\Facades\Vite::useCspNonce($laraCspNonce) : null)
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Read by resources/js/bootstrap.ts into the axios X-CSRF-TOKEN
             default so mutating console requests survive VerifyCsrfToken even
             when the XSRF-TOKEN cookie is unavailable (SameSite/strict hosts). --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="csp-nonce" content="{{ $laraCspNonce }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @viteReactRefresh
        {{-- Plan 06 step 79: entries resolve through App\Support\ViteEntries so a
             page chunk missing from public/build/manifest.json degrades to the
             app entry instead of throwing during render. --}}
        @vite(\App\Support\ViteEntries::forPage($page['component'] ?? null))
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
