<?php

namespace Tests\Feature;

use App\Enums\Permission as PermissionName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminGoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_authorized_admin_can_login_with_google(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'status' => User::STATUS_ACTIVE,
        ]);
        Permission::findOrCreate(PermissionName::DashboardView->value, 'web');
        $admin->givePermissionTo(PermissionName::DashboardView->value);
        $this->mockGoogleUser('google-admin-id', 'admin@example.com');

        $this->get(route('social.google.callback'))
            ->assertRedirect(route('admin.home', absolute: false));

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('user_auth_providers', [
            'user_id' => $admin->id,
            'provider' => 'google',
            'provider_id' => 'google-admin-id',
        ]);
    }

    public function test_unknown_google_account_cannot_create_an_admin_account(): void
    {
        $this->mockGoogleUser('unknown-google-id', 'unknown@example.com');

        $this->get(route('social.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'unknown@example.com']);
    }

    private function mockGoogleUser(string $id, string $email): void
    {
        $socialUser = (new SocialiteUser)
            ->setRaw([
                'sub' => $id,
                'email' => $email,
                'name' => 'Google Admin',
            ])
            ->map([
                'id' => $id,
                'nickname' => null,
                'name' => 'Google Admin',
                'email' => $email,
                'avatar' => 'https://example.com/avatar.png',
            ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->once()->andReturn($socialUser);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);
    }
}
