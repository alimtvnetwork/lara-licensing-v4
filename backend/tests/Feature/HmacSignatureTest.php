<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan 06 step 51 (AC-SIG-001..006).
 *
 * Locks `SignedRequestMiddleware` (HMAC-SHA256 v1) contract against a
 * throwaway `POST /api/portal/hmac-probe` route protected by
 * `require.signature`. Six cases cover the closed failure set from
 * `spec/21-app/12-error-taxonomy.md`:
 *
 *   - Valid signature                             -> 200 OK
 *   - Missing signature headers                   -> AuthUnauthorized (401)
 *   - Timestamp outside +/- skew window           -> AbuseBlocked (403)
 *   - Unknown KeyId                               -> AuthInvalidCredentials (401)
 *   - Body tampered after signing                 -> AuthInvalidCredentials (401)
 *   - Nonce replayed within TTL                   -> AbuseBlocked (403)
 *
 * If any header parsing, timestamp skew, HMAC compare, or nonce cache
 * path regresses, this suite fails red before Portal traffic is served.
 */
final class HmacSignatureTest extends TestCase
{
    private const KEY_ID = 'test-key-1';
    private const RAW_SECRET = 'super-secret-portal-signing-key-32b';
    private const PATH = 'api/portal/hmac-probe';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('lara.portal_signing_keys', [
            self::KEY_ID => base64_encode(self::RAW_SECRET),
        ]);
        Route::middleware(['require.signature'])
            ->post('/api/portal/hmac-probe', fn () => response()->json(['Ok' => true]));
    }

    public function test_valid_signature_passes(): void
    {
        $body = json_encode(['Payload' => 'x']) ?: '';
        $headers = $this->signHeaders('POST', self::PATH, $body, nonce: str_repeat('a', 32));
        $res = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $body);
        $this->assertSame(200, $res->getStatusCode(), $res->getContent() ?: '');
    }

    public function test_missing_headers_returns_401(): void
    {
        $res = $this->postJson('/api/portal/hmac-probe', ['Payload' => 'x']);
        $res->assertStatus(401);
        $this->assertSame('AuthUnauthorized', $res->json('Attributes.Error.ErrorCode'));
    }

    public function test_timestamp_skew_returns_403(): void
    {
        $body = '';
        $stale = (string) (time() - 3600);
        $headers = $this->signHeaders('POST', self::PATH, $body, nonce: str_repeat('b', 32), timestamp: $stale);
        $res = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $body);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('AbuseBlocked', json_decode($res->getContent() ?: '', true)['Attributes']['Error']['ErrorCode'] ?? null);
    }

    public function test_unknown_key_id_returns_401(): void
    {
        $body = '';
        $headers = $this->signHeaders('POST', self::PATH, $body, nonce: str_repeat('c', 32), keyIdOverride: 'not-a-key');
        $res = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $body);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('AuthInvalidCredentials', json_decode($res->getContent() ?: '', true)['Attributes']['Error']['ErrorCode'] ?? null);
    }

    public function test_tampered_body_returns_401(): void
    {
        $body = '{"Payload":"one"}';
        $headers = $this->signHeaders('POST', self::PATH, $body, nonce: str_repeat('d', 32));
        $tampered = '{"Payload":"two"}';
        $res = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $tampered);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('AuthInvalidCredentials', json_decode($res->getContent() ?: '', true)['Attributes']['Error']['ErrorCode'] ?? null);
    }

    public function test_nonce_replay_returns_403(): void
    {
        $body = '';
        $nonce = str_repeat('e', 32);
        $headers = $this->signHeaders('POST', self::PATH, $body, nonce: $nonce);
        $first = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $body);
        $this->assertSame(200, $first->getStatusCode());
        $second = $this->call('POST', '/api/portal/hmac-probe', [], [], [], $this->serverFrom($headers), $body);
        $this->assertSame(403, $second->getStatusCode());
        $this->assertSame('AbuseBlocked', json_decode($second->getContent() ?: '', true)['Attributes']['Error']['ErrorCode'] ?? null);
    }

    /**
     * @return array<string,string>
     */
    private function signHeaders(
        string $method,
        string $path,
        string $body,
        string $nonce,
        ?string $timestamp = null,
        ?string $keyIdOverride = null,
    ): array {
        $ts = $timestamp ?? (string) time();
        $canonical = implode("\n", ['v1', strtoupper($method), strtolower($path), $ts, $nonce, hash('sha256', $body)]);
        $hex = hash_hmac('sha256', $canonical, self::RAW_SECRET);

        return [
            'X-Lara-KeyId' => $keyIdOverride ?? self::KEY_ID,
            'X-Lara-Timestamp' => $ts,
            'X-Lara-Nonce' => $nonce,
            'X-Lara-Signature' => 'v1=' . $hex,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function serverFrom(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
