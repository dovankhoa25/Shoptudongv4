<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class FacebookApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_party_frontend_can_login_with_facebook(): void
    {
        $client = $this->firstPartyPasswordClient();
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/v23.0/debug_token*' => Http::response([
                'data' => [
                    'app_id' => 'facebook-app-id',
                    'is_valid' => true,
                    'user_id' => 'facebook-user-123',
                ],
            ]),
            'graph.facebook.com/v23.0/me*' => Http::response([
                'id' => 'facebook-user-123',
                'name' => 'Facebook User',
                'email' => 'facebook-user@example.com',
                'picture' => ['data' => ['url' => 'https://example.com/facebook-avatar.png']],
            ]),
        ]);

        $response = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/facebook', [
                'access_token' => 'valid-facebook-access-token',
                'provider' => 'facebook',
                'client_id' => (string) $client->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'facebook-user@example.com')
            ->assertJsonStructure([
                'authorization' => [
                    'token_type',
                    'expires_in',
                    'access_token',
                    'refresh_token',
                ],
            ]);

        $user = User::where('email', 'facebook-user@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_auth_providers', [
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_id' => 'facebook-user-123',
        ]);
        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'provider' => 'facebook',
            'is_success' => true,
        ]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me?')
            && $request->hasHeader('Authorization', 'Bearer valid-facebook-access-token'));
    }

    public function test_facebook_login_rejects_a_token_for_another_app(): void
    {
        $client = $this->firstPartyPasswordClient();
        $this->configureFacebook();

        Http::fake([
            'graph.facebook.com/v23.0/debug_token*' => Http::response([
                'data' => [
                    'app_id' => 'another-facebook-app',
                    'is_valid' => true,
                    'user_id' => 'facebook-user-123',
                ],
            ]),
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/facebook', [
                'access_token' => 'wrong-app-facebook-token',
                'provider' => 'facebook',
                'client_id' => (string) $client->id,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    private function configureFacebook(): void
    {
        config()->set('services.facebook.client_id', 'facebook-app-id');
        config()->set('services.facebook.client_secret', 'facebook-app-secret');
        config()->set('services.facebook.graph_version', 'v23.0');
    }

    private function firstPartyPasswordClient(): Client
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient(
            'Facebook frontend test client',
            confidential: true,
        );

        $client->forceFill([
            'is_first_party' => true,
            'allows_direct_login' => true,
            'allowed_origins' => ['http://localhost:3000'],
        ])->save();

        config()->set('sso.password_client_id', (string) $client->id);
        config()->set('sso.password_client_secret', $client->plain_secret);

        return $client;
    }
}
