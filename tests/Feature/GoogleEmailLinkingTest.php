<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserAuthProvider;
use App\Models\UserSecurityLog;
use App\Services\GoogleAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GoogleEmailLinkingTest extends TestCase
{
    use RefreshDatabase;

    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $client = app(ClientRepository::class)->createPasswordGrantClient('Google linking test', confidential: true);
        $client->forceFill([
            'is_first_party' => true, 'allows_direct_login' => true,
            'allowed_origins' => ['http://localhost:3000'],
        ])->save();
        $this->clientId = (string) $client->id;
        config(['sso.password_client_id' => $this->clientId, 'sso.password_client_secret' => $client->plain_secret]);
    }

    private function login(array $claims = [], array $input = []): TestResponse
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => 'verified-google-identity', 'email' => 'existing@gmail.com',
            'email_verified' => true, 'name' => 'Google Display Name',
            ...$claims,
        ])]);

        return $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/auth/google', [
            'access_token' => 'synthetic-google-token', 'client_id' => $this->clientId, ...$input,
        ]);
    }

    public function test_verified_gmail_links_existing_account_and_keeps_its_identity_money_and_history(): void
    {
        $user = User::factory()->create(['username' => 'AccountA', 'email' => 'Existing@Gmail.com', 'balance' => 75000]);
        $passwordHash = $user->password;
        $user->authProviders()->create(['provider' => 'password', 'provider_id' => 'AccountA', 'is_enabled' => true]);
        $history = Transaction::withoutUserOwnedScope()->create([
            'user_id' => $user->id, 'type' => Transaction::TYPE_BANK_DEPOSIT, 'amount' => 75000,
            'balance_before' => 0, 'balance_after' => 75000, 'description' => 'Existing account history',
        ]);

        $response = $this->login(['email' => ' existing@gmail.com '])->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.username', 'AccountA')
            ->assertJsonStructure(['authorization' => ['access_token', 'refresh_token']]);
        $this->withToken($response->json('authorization.access_token'))->getJson('/api/me')
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->login()->assertOk()->assertJsonPath('user.id', $user->id);

        $user->refresh();
        $this->assertSame('AccountA', $user->username);
        $this->assertSame('Existing@Gmail.com', $user->email);
        $this->assertSame($passwordHash, $user->password);
        $this->assertSame(75000, (int) $user->balance);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame($user->id, $history->fresh()->user_id);
        $this->assertSame(1, $user->authProviders()->where('provider', 'google')->count());
        $this->assertTrue($user->authProviders()->where('provider', 'password')->first()->is_enabled);
        $this->assertSame(1, UserSecurityLog::where('user_id', $user->id)->where('event', 'oauth_linked')->count());
    }

    public function test_existing_provider_identity_takes_precedence_over_a_matching_other_email(): void
    {
        $linked = User::factory()->create(['email' => 'old-address@gmail.com']);
        $other = User::factory()->create(['email' => 'existing@gmail.com']);
        $linked->authProviders()->create(['provider' => 'google', 'provider_id' => 'verified-google-identity', 'is_enabled' => true]);

        $this->login()->assertOk()->assertJsonPath('user.id', $linked->id);
        $this->assertFalse($other->authProviders()->exists());
        $this->assertSame('old-address@gmail.com', $linked->refresh()->email);
    }

    public function test_link_never_replaces_an_existing_different_google_identity(): void
    {
        $user = User::factory()->create(['email' => 'existing@gmail.com']);
        $user->authProviders()->create(['provider' => 'google', 'provider_id' => 'previous-google-identity', 'is_enabled' => true]);
        $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('user_auth_providers', 1);
        $this->assertSame('previous-google-identity', $user->authProviders()->first()->provider_id);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_disabled_google_login_is_not_reenabled_by_matching_identity_or_email(): void
    {
        $user = User::factory()->create(['email' => 'existing@gmail.com']);
        $provider = $user->authProviders()->create(['provider' => 'google', 'provider_id' => 'verified-google-identity', 'is_enabled' => false]);
        $this->login()->assertForbidden();
        $this->login(['sub' => 'different-google-identity'])->assertForbidden();
        $this->assertFalse($provider->fresh()->is_enabled);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_locked_and_deleted_accounts_cannot_be_linked_or_recreated(): void
    {
        $user = User::factory()->create(['email' => 'existing@gmail.com', 'status' => User::STATUS_LOCKED]);
        $this->login()->assertStatus(423);
        $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $user->delete();
        $this->login()->assertForbidden();
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertDatabaseCount('user_auth_providers', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_deleted_provider_owner_is_rejected_without_server_error(): void
    {
        $user = User::factory()->create(['email' => 'existing@gmail.com']);
        $user->authProviders()->create(['provider' => 'google', 'provider_id' => 'verified-google-identity', 'is_enabled' => true]);
        $user->delete();
        $this->login()->assertForbidden();
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_ambiguous_normalized_emails_are_not_merged(): void
    {
        User::factory()->create(['email' => 'existing@gmail.com']);
        User::factory()->create(['email' => ' Existing@Gmail.com ']);
        $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('user_auth_providers', 0);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_ambiguous_provider_id_is_rejected_instead_of_choosing_a_user(): void
    {
        foreach (User::factory()->count(2)->create() as $user) {
            $user->authProviders()->create(['provider' => 'google', 'provider_id' => 'verified-google-identity', 'is_enabled' => true]);
        }
        $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_verified_claim_must_be_boolean_true_from_google_not_client_input(): void
    {
        User::factory()->create(['email' => 'existing@gmail.com']);
        foreach ([false, null, 'false', 'true', 1] as $claim) {
            $this->login(['email_verified' => $claim], ['email_verified' => true])
                ->assertUnprocessable()->assertJsonPath('message', 'Email chưa được Google xác thực.');
        }
        $this->assertDatabaseCount('user_auth_providers', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_verified_third_party_email_cannot_link_without_google_hosted_domain_claim(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $this->login(['email' => $user->email], ['hd' => 'example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('user_auth_providers', 0);
        $this->login(['email' => $user->email, 'hd' => 'example.com'])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_privileged_accounts_cannot_gain_google_link_even_when_admin_approval_is_disabled(): void
    {
        config(['access_security.admin_approval_required' => false]);
        $permissionUser = User::factory()->create(['email' => 'existing@gmail.com']);
        Permission::findOrCreate('dashboard.view', 'web');
        $permissionUser->givePermissionTo('dashboard.view');
        $this->login()->assertUnprocessable()->assertJsonValidationErrors('email');

        $configuredAdmin = User::factory()->create(['email' => 'configured@gmail.com']);
        config(['sso.admin_user_ids' => [(string) $configuredAdmin->id]]);
        $this->login(['sub' => 'configured-admin', 'email' => $configuredAdmin->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('user_auth_providers', 0);
    }

    public function test_admin_resolver_never_links_matching_email_or_creates_a_user(): void
    {
        User::factory()->create(['email' => 'existing@gmail.com']);
        foreach (['existing@gmail.com', 'unknown@gmail.com'] as $email) {
            $social = (new SocialiteUser)->setRaw(['email_verified' => true])
                ->map(['id' => 'unlinked-google', 'email' => $email, 'name' => 'Admin email match']);
            try {
                app(GoogleAuthService::class)->resolveExistingUser($social, Request::create('/'));
                $this->fail('Admin must require a previously linked Google ID.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_auth_providers', 0);
    }

    public function test_new_google_user_gets_valid_unique_username_without_entering_one(): void
    {
        $old = User::factory()->create(['username' => 'o_connor']);
        $old->delete();
        $this->login(['email' => "o'connor@example.com"])->assertOk()->assertJsonPath('user.username', 'o_connor_1');
        $new = User::where('email', "o'connor@example.com")->firstOrFail();
        $this->assertSame('o_connor_1', $new->username);
        $this->assertNull($new->password);
        $this->assertNotNull($new->email_verified_at);
        $this->assertSame(0, (int) $new->balance);
        $this->assertSame(1, UserAuthProvider::where('user_id', $new->id)->where('provider', 'google')->count());
    }
}
