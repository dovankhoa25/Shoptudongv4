<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\AblyAuthorizationTestCase;

class AdminRealtimeAuthorizationTest extends AblyAuthorizationTestCase
{
    use RefreshDatabase;

    public function test_admin_can_authorize_the_private_admin_realtime_channel(): void
    {
        $admin = User::factory()->create();
        Role::findOrCreate('admin', 'web');
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-Admin.realtime',
            'socket_id' => '123.456',
        ]);

        $response->assertOk()->assertJsonPath('auth', 'test.key:'.hash_hmac(
            'sha256', '123.456:private-Admin.realtime', 'secret',
        ));
    }

    public function test_regular_user_cannot_authorize_the_private_admin_realtime_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-Admin.realtime',
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

}
