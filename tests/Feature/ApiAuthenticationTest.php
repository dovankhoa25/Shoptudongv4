<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_party_frontend_can_login_and_receive_passport_tokens(): void
    {
        $client = $this->firstPartyPasswordClient();
        $user = User::factory()->create();

        $response = $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/auth/login', [
            'login' => $user->username,
            'password' => 'password',
            'client_id' => (string) $client->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'authorization' => [
                    'token_type',
                    'expires_in',
                    'access_token',
                    'refresh_token',
                ],
            ]);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'provider' => 'password',
            'is_success' => true,
        ]);
        $this->assertDatabaseHas('user_security_logs', [
            'user_id' => $user->id,
            'event' => 'login_success',
        ]);
    }

    public function test_login_rejects_an_origin_not_allowed_for_the_frontend_client(): void
    {
        $client = $this->firstPartyPasswordClient();
        $user = User::factory()->create();

        $this->withHeader('Origin', 'https://untrusted.example')->postJson('/api/auth/login', [
            'login' => $user->username,
            'password' => 'password',
            'client_id' => (string) $client->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    }

    public function test_locked_user_cannot_login_from_a_first_party_frontend(): void
    {
        $client = $this->firstPartyPasswordClient();
        $user = User::factory()->create([
            'status' => User::STATUS_LOCKED,
            'locked_reason' => 'Vi phạm quy định',
        ]);

        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/auth/login', [
            'login' => $user->username,
            'password' => 'password',
            'client_id' => (string) $client->id,
        ])->assertStatus(423)
            ->assertJsonPath('success', false)
            ->assertJsonPath('locked_reason', 'Vi phạm quy định');

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'is_success' => false,
            'failure_reason' => 'user_locked',
        ]);
    }

    private function firstPartyPasswordClient(): Client
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient(
            'Frontend test client',
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
