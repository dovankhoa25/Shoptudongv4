<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class GoogleApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_party_frontend_can_login_with_google(): void
    {
        $client = $this->firstPartyPasswordClient();

        Http::fake([
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-user-123',
                'email' => 'google-user@example.com',
                'email_verified' => true,
                'name' => 'Google User',
                'picture' => 'https://example.com/avatar.png',
            ]),
        ]);

        $response = $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/google', [
                'access_token' => 'valid-google-access-token',
                'provider' => 'google',
                'client_id' => (string) $client->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'google-user@example.com')
            ->assertJsonStructure([
                'authorization' => [
                    'token_type',
                    'expires_in',
                    'access_token',
                    'refresh_token',
                ],
            ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/refresh', [
                'client_id' => (string) $client->id,
                'refresh_token' => $response->json('authorization.refresh_token'),
            ])
            ->assertOk()
            ->assertJsonStructure([
                'token_type',
                'expires_in',
                'access_token',
                'refresh_token',
            ]);

        $user = User::where('email', 'google-user@example.com')->firstOrFail();

        $this->assertDatabaseHas('user_auth_providers', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-user-123',
        ]);
        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'is_success' => true,
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/oauth2/v3/userinfo'
            && $request->hasHeader('Authorization', 'Bearer valid-google-access-token'));
    }

    public function test_google_login_rejects_an_invalid_token(): void
    {
        $client = $this->firstPartyPasswordClient();

        Http::fake([
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response([], 401),
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/google', [
                'access_token' => 'invalid-google-access-token',
                'provider' => 'google',
                'client_id' => (string) $client->id,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_google_login_reports_when_google_is_unreachable(): void
    {
        $client = $this->firstPartyPasswordClient();

        Http::fake(fn () => throw new ConnectionException('Could not connect to Google'));

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/auth/google', [
                'access_token' => 'valid-google-access-token',
                'provider' => 'google',
                'client_id' => (string) $client->id,
            ])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Máy chủ hiện không thể kết nối đến Google. Vui lòng thử lại sau.',
            );
    }

    private function firstPartyPasswordClient(): Client
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient(
            'Google frontend test client',
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
