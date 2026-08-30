<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminRealtimeAuthorizationTest extends TestCase
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

        $response->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_regular_user_cannot_authorize_the_private_admin_realtime_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => 'private-Admin.realtime',
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=ably');
        putenv('ABLY_KEY=test.key:secret');
        $_ENV['BROADCAST_CONNECTION'] = 'ably';
        $_ENV['ABLY_KEY'] = 'test.key:secret';
        $_SERVER['BROADCAST_CONNECTION'] = 'ably';
        $_SERVER['ABLY_KEY'] = 'test.key:secret';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('BROADCAST_CONNECTION=null');
        putenv('ABLY_KEY');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        unset($_ENV['ABLY_KEY']);
        $_SERVER['BROADCAST_CONNECTION'] = 'null';
        unset($_SERVER['ABLY_KEY']);

        parent::tearDown();
    }
}
