<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictFinancialWebhookIngress;
use App\Services\FrontendClientRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialWebhookIngressTest extends TestCase
{
    public static function deniedRequests(): array
    {
        $cases = [];
        foreach (['/api/charge/callback', '/api/webhook/sepay'] as $path) {
            foreach (['203.0.113.25', '2001:db8::25', '127.0.0.1', 'invalid', ''] as $peer) {
                $cases[$path.' peer '.$peer] = [$path, $peer];
            }
            $cases[$path.' trailing slash'] = [$path.'/', '203.0.113.25'];
            $cases[$path.' query string'] = [$path.'?source=trusted', '203.0.113.25'];
            $cases[$path.' encoded path'] = [str_replace('/api/', '/%61pi/', $path), '203.0.113.25'];
            $cases[$path.' encoded separator'] = [str_replace('/api/', '/api%2F', $path), '203.0.113.25'];
        }

        return $cases;
    }

    #[DataProvider('deniedRequests')]
    public function test_denied_peers_do_not_touch_database_cache_cors_or_metrics(string $path, string $peer): void
    {
        config(['traffic_monitor.enabled' => true, 'traffic_monitor.store' => 'database']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) { $queries[] = $query->sql; });
        Cache::spy();
        $this->mock(FrontendClientRegistry::class)->shouldNotReceive('allowedOrigins');

        $this->withServerVariables(['REMOTE_ADDR' => $peer])->postJson($path, [], [
            'CF-Connecting-IP' => '173.245.48.5',
            'X-Forwarded-For' => '173.245.48.5',
            'X-Real-IP' => '173.245.48.5',
            'X-Shop-Client-IP' => '173.245.48.5',
        ])->assertForbidden()->assertExactJson(['success' => false, 'message' => 'Forbidden.']);

        $this->assertSame([], $queries);
        foreach (['get', 'store', 'remember', 'put', 'add', 'lock'] as $method) {
            Cache::shouldNotHaveReceived($method);
        }
    }

    public static function allowedRequests(): array
    {
        $cases = [];
        foreach (['/api/charge/callback' => 422, '/api/webhook/sepay' => 401] as $path => $status) {
            foreach (['173.245.48.5', '104.16.0.1', '2606:4700::1', '2400:cb00::1', '::ffff:173.245.48.5'] as $peer) {
                $cases[$path.' peer '.$peer] = [$path, $peer, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('allowedRequests')]
    public function test_allowed_ipv4_ipv6_peers_still_require_valid_callback_credentials(string $path, string $peer, int $status): void
    {
        $this->mock(FrontendClientRegistry::class)->shouldReceive('allowedOrigins')->once()->andReturn([]);
        config(['services.sepay.webhook_key' => 'test-sepay-key']);
        $this->withServerVariables(['REMOTE_ADDR' => $peer])->postJson($path, [])
            ->assertStatus($status);
    }

    public function test_empty_allowlist_denies_every_peer(): void
    {
        config(['financial_webhook_ingress.allowed_peer_networks' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.5'])
            ->postJson('/api/charge/callback', [])->assertForbidden();
    }

    public function test_a_signed_storefront_header_cannot_bypass_the_connection_peer_check(): void
    {
        $secret = str_repeat('a', 32);
        config(['access_security.proxy_secret' => $secret]);
        $time = (string) time();
        $body = '{}';
        $signature = hash_hmac('sha256', implode("\n", [
            'POST', '/api/charge/callback', $time, '173.245.48.5', 'test-agent', hash('sha256', $body),
        ]), $secret);
        $this->call('POST', '/api/charge/callback', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.25', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOP_CLIENT_IP' => '173.245.48.5', 'HTTP_X_SHOP_CLIENT_AGENT' => 'test-agent',
            'HTTP_X_SHOP_CLIENT_TIME' => $time, 'HTTP_X_SHOP_CLIENT_SIGNATURE' => $signature,
        ], $body)->assertForbidden();
    }

    public function test_original_peer_can_be_read_from_a_server_variable_when_the_host_rewrites_remote_addr(): void
    {
        config(['financial_webhook_ingress.peer_server_key' => 'REALIP_REMOTE_ADDR']);
        $this->mock(FrontendClientRegistry::class)->shouldReceive('allowedOrigins')->once()->andReturn([]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.25', 'REALIP_REMOTE_ADDR' => '173.245.48.5'])
            ->postJson('/api/charge/callback', [])->assertUnprocessable();
    }

    public function test_an_http_header_is_never_accepted_as_the_original_connection_address(): void
    {
        config(['financial_webhook_ingress.peer_server_key' => 'HTTP_CF_CONNECTING_IP']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.25'])
            ->postJson('/api/charge/callback', [], ['CF-Connecting-IP' => '173.245.48.5'])->assertForbidden();
    }

    public function test_other_routes_are_not_subject_to_this_allowlist(): void
    {
        foreach (['/api/webhook/gpmpay', '/api/servers', '/login'] as $path) {
            $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.25']);
            $response = app(RestrictFinancialWebhookIngress::class)->handle($request, fn () => response()->noContent());
            $this->assertSame(204, $response->getStatusCode());
        }
    }
}
