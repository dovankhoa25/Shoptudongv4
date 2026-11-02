<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicIngressRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access_security.trusted_proxies' => [],
            'access_security.proxy_secret' => null,
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => self::PEER]);

        Route::get('/api/ingress-test/{suffix?}', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'source' => $request->attributes->get('security_ip_source'),
        ]))->middleware('api');
    }

    public function test_invalid_tokens_share_an_ip_budget_across_protected_paths_before_authentication(): void
    {
        config(['ingress_rate_limits.per_minute.api-read' => 2]);
        $this->withToken('invalid-token-one')->getJson('/api/me')->assertUnauthorized();
        $this->withToken('invalid-token-two')->getJson('/api/profile')->assertUnauthorized();

        // The first requests warm dynamic CORS/IP-block caches. Rejection must not
        // run protected route authentication, controller queries, or traffic writes.
        config(['traffic_monitor.enabled' => true, 'traffic_monitor.store' => 'database']);
        DB::enableQueryLog();
        $this->withToken('invalid-token-three')->getJson('/api/me/balance')
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame([], DB::getQueryLog());
        $this->assertFalse($this->app['request']->attributes->get('_traffic_enabled'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->getJson('/api/me/balance')->assertUnauthorized();
    }

    public function test_changing_accounts_does_not_reset_the_same_ip_ingress_budget(): void
    {
        config(['ingress_rate_limits.per_minute.api-read' => 1]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        Passport::actingAs($first, ['profile:read']);
        $this->getJson('/api/me/balance')->assertOk();

        Passport::actingAs($second, ['profile:read']);
        $this->getJson('/api/me/balance')->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->getJson('/api/me/balance')->assertOk();
    }

    public function test_unsigned_forwarding_headers_cannot_rotate_an_exhausted_bucket(): void
    {
        config(['ingress_rate_limits.per_minute.api-read' => 1]);
        $this->getJson('/api/ingress-test')->assertOk()->assertJsonPath('ip', self::PEER);

        foreach (['198.51.100.20', '198.51.100.21'] as $forgedIp) {
            $this->withHeaders([
                'X-Forwarded-For' => $forgedIp,
                'CF-Connecting-IP' => $forgedIp,
                'X-Real-IP' => $forgedIp,
                'X-Shop-Client-IP' => $forgedIp,
                'X-Shop-Client-Time' => (string) time(),
                'X-Shop-Client-Signature' => str_repeat('a', 64),
            ])->getJson('/api/ingress-test')->assertTooManyRequests();
        }
    }

    public function test_signed_clients_sharing_one_server_peer_have_independent_budgets(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 1,
            'access_security.proxy_secret' => str_repeat('k', 40),
        ]);
        $this->signedGet('/api/ingress-test', '198.51.100.30')
            ->assertOk()->assertJsonPath('ip', '198.51.100.30')->assertJsonPath('source', 'signed_storefront');
        $this->signedGet('/api/ingress-test/second', '198.51.100.31')
            ->assertOk()->assertJsonPath('ip', '198.51.100.31');
        $this->signedGet('/api/ingress-test/third', '198.51.100.30')->assertTooManyRequests();
        $this->getJson('/api/ingress-test')->assertOk()->assertJsonPath('ip', self::PEER);
    }

    public function test_modified_or_expired_signed_requests_fall_back_to_the_real_peer_budget(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 1,
            'access_security.proxy_secret' => str_repeat('k', 40),
        ]);
        $this->getJson('/api/ingress-test')->assertOk();
        $this->signedGet('/api/ingress-test/changed', '198.51.100.40', signedPath: '/api/ingress-test')
            ->assertTooManyRequests();
        $this->signedGet('/api/ingress-test', '198.51.100.41', timestamp: time() - 120)
            ->assertTooManyRequests();
        $this->signedGet('/api/ingress-test', '198.51.100.42', body: '{"changed":true}')
            ->assertTooManyRequests();
    }

    public function test_only_a_configured_proxy_can_split_budgets_using_forwarded_client_ips(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 1,
            'access_security.trusted_proxies' => [self::PEER],
        ]);
        $this->withHeader('X-Forwarded-For', '198.51.100.50')->getJson('/api/ingress-test')
            ->assertOk()->assertJsonPath('ip', '198.51.100.50');
        $this->withHeader('X-Forwarded-For', '198.51.100.51')->getJson('/api/ingress-test')
            ->assertOk()->assertJsonPath('ip', '198.51.100.51');
        $this->withHeader('X-Forwarded-For', '198.51.100.50')->getJson('/api/ingress-test')
            ->assertTooManyRequests();
    }

    public function test_api_read_write_authentication_chat_and_health_budgets_are_isolated(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 1,
            'ingress_rate_limits.per_minute.api-write' => 1,
            'ingress_rate_limits.per_minute.auth' => 1,
            'chat.rate_limits.ingress_per_minute' => 1,
        ]);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/profile')->assertTooManyRequests();
        $this->postJson('/api/purchase')->assertUnauthorized();
        $this->postJson('/api/orders')->assertTooManyRequests();
        $this->postJson('/api/auth/login')->assertUnprocessable();
        $this->postJson('/api/auth/register')->assertTooManyRequests();
        $this->getJson('/api/chat/unread-summary')->assertUnauthorized();
        $this->postJson('/api/broadcasting/auth')->assertTooManyRequests();
        $this->get('/up')->assertOk();
    }

    public function test_preflight_does_not_consume_budget_and_rejection_retains_cors(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 1,
            'cors.static_allowed_origins' => ['https://store.example.test'],
        ]);
        $origin = 'https://store.example.test';
        $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/ingress-test')->assertNoContent();
        $this->getJson('/api/ingress-test')->assertOk();
        $this->getJson('/api/ingress-test')->assertTooManyRequests()
            ->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Retry-After');
    }

    public function test_encoded_paths_trailing_slashes_and_query_strings_do_not_create_new_budgets(): void
    {
        config(['ingress_rate_limits.per_minute.api-read' => 1]);
        $this->getJson('/api/ingress-test')->assertOk();
        foreach (['/%61pi/ingress-test', '/api%2Fingress-test', '/api/ingress-test/', '/api/ingress-test?fresh=1'] as $path) {
            $this->getJson($path)->assertTooManyRequests();
        }
    }

    public function test_rotating_unknown_api_and_web_paths_is_still_bounded(): void
    {
        config([
            'ingress_rate_limits.per_minute.api-read' => 2,
            'ingress_rate_limits.per_minute.web' => 2,
        ]);
        $this->getJson('/api/unknown-ingress-one')->assertNotFound();
        $this->getJson('/api/unknown-ingress-two')->assertNotFound();
        $this->getJson('/api/unknown-ingress-three')->assertTooManyRequests();
        $this->getJson('/unknown-ingress-one')->assertNotFound();
        $this->getJson('/unknown-ingress-two')->assertNotFound();
        $this->getJson('/unknown-ingress-three')->assertTooManyRequests();
    }

    public static function boundedFamilies(): array
    {
        return [
            'admin' => ['admin', '/admin/unknown-ingress-one', '/admin/unknown-ingress-two', 404],
            'legacy app' => ['legacy-tool', '/app/v1/unknown-ingress', '/api/app/unknown-ingress', 404],
            'trading app' => ['trading-tool', '/app/v3/unknown-ingress-one', '/app/v3/unknown-ingress-two', 404],
            'worker' => ['worker', '/app/nro-worker/unknown-ingress-one', '/app/nro-worker/unknown-ingress-two', 404],
            // Laravel's signed local-storage route rejects an unsigned URL first.
            'storage' => ['storage', '/storage/unknown-ingress-one', '/storage/unknown-ingress-two', 403],
        ];
    }

    #[DataProvider('boundedFamilies')]
    public function test_other_path_families_cannot_evade_ingress_with_unknown_routes(string $bucket, string $first, string $second, int $unthrottledStatus): void
    {
        config(['ingress_rate_limits.per_minute.'.$bucket => 1]);
        $this->getJson($first)->assertStatus($unthrottledStatus);
        $this->getJson($second)->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.13'])->getJson($second)->assertStatus($unthrottledStatus);
    }

    public function test_failed_legacy_and_worker_authentication_is_also_bounded(): void
    {
        config([
            'ingress_rate_limits.per_minute.legacy-tool' => 1,
            'ingress_rate_limits.per_minute.worker' => 1,
        ]);
        $this->getJson('/app/v1/bots')->assertUnauthorized();
        $this->getJson('/api/app/carot/recharges/pending')->assertTooManyRequests();
        $this->getJson('/app/nro-worker/accounts')->assertUnauthorized();
        $this->postJson('/app/nro-worker/claim')->assertTooManyRequests();
    }

    public function test_card_types_uses_the_public_read_limit_without_blocking_authenticated_profile(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/card-types')->assertOk();
        }
        $this->getJson('/api/card-types')->assertTooManyRequests();

        Passport::actingAs(User::factory()->create(), ['profile:read']);
        $this->getJson('/api/me/balance')->assertOk();
    }

    public function test_worker_requests_do_not_consume_login_or_trading_route_throttles(): void
    {
        config(['trading_v3.enabled' => true, 'trading_v3.hmac_secret' => str_repeat('t', 40)]);
        for ($i = 0; $i < 11; $i++) {
            $this->getJson('/app/nro-worker/accounts')->assertUnauthorized();
        }

        // Numeric route throttles used to share an IP counter across these families.
        $this->postJson('/api/auth/login')->assertUnprocessable();
        $this->getJson('/app/v3/gem/bots')->assertUnauthorized();
    }

    private function signedGet(
        string $path,
        string $clientIp,
        ?string $signedPath = null,
        ?int $timestamp = null,
        string $body = '',
    ): TestResponse {
        $time = (string) ($timestamp ?? time());
        $agent = 'Ingress test browser';
        // Sign an empty body, so a supplied body deliberately invalidates the signature.
        $signature = hash_hmac('sha256', implode("\n", [
            'GET', $signedPath ?? $path, $time, $clientIp, $agent, hash('sha256', ''),
        ]), (string) config('access_security.proxy_secret'));

        return $this->call('GET', $path, [], [], [], [
            'REMOTE_ADDR' => self::PEER,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOP_CLIENT_IP' => $clientIp,
            'HTTP_X_SHOP_CLIENT_AGENT' => $agent,
            'HTTP_X_SHOP_CLIENT_TIME' => $time,
            'HTTP_X_SHOP_CLIENT_SIGNATURE' => $signature,
            // Valid signed forwarding must take precedence over unrelated proxy headers.
            'HTTP_X_FORWARDED_FOR' => '192.0.2.99',
        ], $body);
    }
}
