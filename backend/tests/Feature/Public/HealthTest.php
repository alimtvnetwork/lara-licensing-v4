<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, `Public/HealthTest` row). Locks
 * `GET /Api/Public/Health` end-to-end through the HTTP kernel.
 *
 * Root cause guarded: `App\Http\Controllers\Public\HealthController`
 * (v0.300.0) has two branches (Root reachable -> 200 `ApiEnvelope::success`;
 * Root unreachable -> 503 `ServiceUnavailable` failure envelope with
 * `Attributes.Payload` echoing the readiness dump), and neither had HTTP
 * coverage. Deploy probes (cPanel post-deploy hook, GitHub Actions release
 * smoke test) rely on the 200/503 contract; a regression that returned the
 * bare status code without the envelope, or masked the 503 branch behind a
 * caught `Throwable` -> 200, would silently break the release gate. The
 * exact failure mode we lock: `pingRoot()` catches all `Throwable`, so a
 * refactor that let the exception escape (or that always returned true)
 * would either surface a 500 to load balancers (down flapped as broken) or
 * hide a real outage as green.
 *
 * The 503 branch is exercised by rewiring the `root` connection to a
 * sqlite file inside a directory that does not exist, then purging the
 * connection so `DB::connection('root')->selectOne(...)` fails on first
 * touch inside `HealthController::pingRoot()`. This is the same technique
 * a real DB outage would exhibit (connection open, first query throws).
 */
final class HealthTest extends TestCase
{
    use AssertsLaraException;

    public function test_health_returns_200_success_envelope_when_root_is_up(): void
    {
        Log::spy();

        $res = $this->getJson('/Api/Public/Health', ['X-Request-Id' => 'health-ok']);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['Status']['IsSuccess'] ?? false, 'Health OK envelope must have Status.IsSuccess === true.');
        $this->assertSame('health-ok', $json['Status']['RequestId'] ?? null);
        $payload = $json['Results'][0] ?? null;
        $this->assertIsArray($payload, 'Results[0] must carry the readiness payload.');
        $this->assertSame('ok', $payload['RootDb'] ?? null);
        foreach (['App', 'Version', 'RootDb', 'Time'] as $key) {
            $this->assertArrayHasKey($key, $payload, "Health payload must expose '{$key}'.");
        }
        Log::shouldHaveReceived('info')->withArgs(function (string $event, array $ctx): bool {
            return $event === 'public.health.ok' && ($ctx['RequestId'] ?? null) === 'health-ok';
        })->atLeast()->once();
    }

    public function test_health_returns_503_envelope_when_root_ping_fails(): void
    {
        Log::spy();

        // Rewire the `root` connection to an unreachable sqlite path so the
        // first query inside pingRoot() throws. This is closer to a real
        // outage than mocking the facade.
        Config::set('database.connections.root.driver', 'sqlite');
        Config::set('database.connections.root.database', '/nonexistent-dir/does-not-exist.sqlite');
        Config::set('database.connections.root.foreign_key_constraints', false);
        DB::purge('root');

        $res = $this->getJson('/Api/Public/Health', ['X-Request-Id' => 'health-down']);

        $this->assertLaraException($res, 'ServiceUnavailable', 503);
        $attributes = $res->json('Attributes');
        $this->assertIsArray($attributes);
        $this->assertArrayHasKey('Payload', $attributes, '503 envelope must echo the readiness Payload for operator debugging.');
        $this->assertSame('down', $attributes['Payload']['RootDb'] ?? null);
        Log::shouldHaveReceived('error')->withArgs(function (string $event, array $ctx = []): bool {
            return $event === 'public.health.root_ping_failed';
        })->atLeast()->once();
        Log::shouldHaveReceived('error')->withArgs(function (string $event, array $ctx = []): bool {
            return $event === 'public.health.root_down' && ($ctx['RequestId'] ?? null) === 'health-down';
        })->atLeast()->once();
    }
}
