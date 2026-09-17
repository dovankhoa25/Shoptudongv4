<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAuthProvider;
use App\Services\FacebookAuthService;
use App\Services\GoogleAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Laravel\Socialite\Two\User as SocialUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([\App\Events\AdminEvent::class, \App\Events\UserEvent::class]);
    }

    public function test_api_rejects_rename_but_accepts_unchanged_username_and_email_update(): void
    {
        $user = User::factory()->create(['username' => 'chidok']);
        Passport::actingAs($user, ['profile:read', 'profile:write']);
        foreach (['new-name', '<img src=x onerror=alert(1)>'] as $name) {
            $this->patchJson('/api/profile', ['username' => $name])->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->patchJson('/api/profile', ['username' => 'chidok', 'email' => 'updated@example.com'])->assertOk();
        $this->assertSame('chidok', $user->fresh()->username);
        $this->assertSame('updated@example.com', $user->fresh()->email);
    }

    public function test_web_profile_rejects_rename_and_synchronizes_email(): void
    {
        $user = User::factory()->create(['username' => 'chidok']);
        UserAuthProvider::create(['user_id' => $user->id, 'provider' => 'password', 'provider_id' => 'chidok', 'provider_email' => $user->email]);
        $this->actingAs($user)->patchJson('/profile', ['username' => 'new-name'])->assertUnprocessable();
        $this->patch('/profile', ['email' => 'updated@example.com'])->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertDatabaseHas('user_auth_providers', ['user_id' => $user->id, 'provider_id' => 'chidok', 'provider_email' => 'updated@example.com']);
    }

    public function test_admin_cannot_rename_but_can_edit_without_sending_username(): void
    {
        Role::findOrCreate('admin', 'web');
        Permission::findOrCreate('users.update', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo('users.update');
        $user = User::factory()->create();
        $this->actingAs($admin)->putJson('/admin/users/'.$user->id, ['username' => 'new-name'])->assertUnprocessable();
        $this->putJson('/admin/users/'.$user->id, ['email' => 'updated@example.com'])->assertOk();
        $this->assertSame($user->username, $user->fresh()->username);
    }

    public function test_model_prevents_a_controller_from_bypassing_username_lock(): void
    {
        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        $user->forceFill(['username' => 'new-name'])->save();
    }

    public function test_username_rule_blocks_markup_and_control_characters_without_rejecting_vietnamese(): void
    {
        foreach (['<img src=x>', "name\n", 'a b', 'a"b', 'a/b'] as $name) {
            $this->assertTrue(Validator::make(['username' => $name], ['username' => new \App\Rules\AccountUsername])->fails());
        }
        $this->assertFalse(Validator::make(['username' => 'người_dùng.123'], ['username' => new \App\Rules\AccountUsername])->fails());
        $this->postJson('/register', ['username' => '<img src=x>', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnprocessable();
        $this->postJson('/api/auth/register', ['username' => '<img src=x>', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_banned_api_and_web_sessions_cannot_read_or_write_profile(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['profile:read', 'profile:write']);
        $user->update(['status' => User::STATUS_BANNED]);
        $this->getJson('/api/profile')->assertForbidden();
        $this->patchJson('/api/profile', ['email' => 'updated@example.com'])->assertForbidden();
        $this->actingAs($user, 'web')->patchJson('/profile', ['email' => 'updated@example.com'])->assertForbidden();
    }

    public function test_read_only_token_cannot_submit_withdrawal(): void
    {
        Passport::actingAs(User::factory()->create(['balance' => 100000]), ['profile:read']);
        $this->postJson('/api/profile/withdrawal', ['amount' => 10000])->assertForbidden();
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_read_only_token_cannot_spend_money_or_change_account_data(): void
    {
        Passport::actingAs(User::factory()->create(['balance' => 100000]), ['profile:read']);
        foreach (['/api/purchase', '/api/service/orders', '/api/orders', '/api/gem/orders', '/api/imports', '/api/nro-shop/orders', '/api/profile/avatar'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
        $this->putJson('/api/auth/change-password', [])->assertForbidden();
    }

    public function test_chat_only_token_cannot_read_private_profile_or_purchase_history(): void
    {
        Passport::actingAs(User::factory()->create(), ['chat:read']);
        foreach (['/api/user/profile', '/api/profile/orders', '/api/profile/services', '/api/profile/random', '/api/nro-shop/orders'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_disabled_or_negative_priced_services_cannot_charge_or_credit_a_buyer(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = User::factory()->create(['balance' => 100000]);
        Passport::actingAs($user, ['profile:read', 'profile:write']);
        foreach ([[false, 10000], [true, -10000]] as [$status, $price]) {
            $service = \App\Models\Service::create(['name' => 'Security test', 'status' => $status, 'default_price' => $price]);
            $this->postJson('/api/service/orders', ['service_id' => $service->id, 'username' => 'game-account', 'password' => 'test-password'])->assertUnprocessable();
        }
        $this->assertSame(100000, (int) $user->fresh()->balance);
        $this->assertDatabaseCount('service_orders', 0);
        foreach ([new \App\Http\Requests\Services\ServicesStoreRequest, new \App\Http\Requests\Services\ServicesUpdateRequest] as $request) {
            $this->assertTrue(Validator::make(['name' => 'Test', 'default_price' => -10000], $request->rules())->fails());
        }
    }

    public function test_media_storage_rejects_html_even_when_named_like_an_image(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        $user->addMediaFromString('<html><script>alert(1)</script></html>')->usingFileName('fake.png')->toMediaCollection('avatar', 'public');
    }

    public function test_banning_revokes_access_refresh_and_database_sessions(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-me']);
        $token = $this->token($user);
        config(['session.driver' => 'database']);
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $user->update(['status' => User::STATUS_BANNED]);
        $this->assertTrue($token->fresh()->revoked);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => 'refresh-test', 'revoked' => true]);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_web_password_change_revokes_api_tokens(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'new-password123', 'password_confirmation' => 'new-password123'])->assertSessionHasNoErrors();
        $this->assertTrue($token->fresh()->revoked);
    }

    public function test_legacy_refresh_tokens_are_rejected_for_already_banned_users(): void
    {
        $user = User::factory()->create();
        $this->token($user);
        $repository = app(\Laravel\Passport\Bridge\RefreshTokenRepository::class);
        $this->assertFalse($repository->isRefreshTokenRevoked('refresh-test'));
        // Simulate a ban that predates the model revocation hook.
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['status' => User::STATUS_BANNED]);
        $this->assertTrue($repository->isRefreshTokenRevoked('refresh-test'));
    }

    public function test_social_login_cannot_merge_an_existing_email_account(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.com', 'email_verified_at' => null]);
        foreach (['google', 'facebook'] as $provider) {
            try {
                if ($provider === 'google') {
                    $social = (new SocialUser)->setRaw([])->map(['id' => 'victim-google', 'email' => $user->email, 'name' => 'Victim']);
                    app(GoogleAuthService::class)->resolveUser($social, Request::create('/'));
                } else {
                    app(FacebookAuthService::class)->resolveAccessTokenUser(['id' => 'victim-facebook', 'email' => $user->email], Request::create('/'));
                }
                $this->fail('Matching email must not link an account.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('email', $exception->errors());
            }
        }
        $this->assertDatabaseCount('user_auth_providers', 0);
    }

    private function token(User $user): \Laravel\Passport\Token
    {
        $client = app(\Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Security test');
        $token = $user->tokens()->create(['id' => 'security-test-token', 'client_id' => $client->id, 'scopes' => ['profile:read'], 'revoked' => false, 'expires_at' => now()->addHour()]);
        \Laravel\Passport\Passport::refreshToken()->newQuery()->create(['id' => 'refresh-test', 'access_token_id' => $token->id, 'revoked' => false, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
