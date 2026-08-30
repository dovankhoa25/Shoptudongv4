<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\User;
use App\Services\FrontendClientRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFrontendClientManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Permission::findOrCreate(AppPermission::FrontendClientsView->value, 'web');
        Permission::findOrCreate(AppPermission::FrontendClientsManage->value, 'web');
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
    }

    public function test_frontend_client_page_hides_internal_password_client(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $internal = $this->createFrontendClient($superAdmin, 'Internal token issuer', 'https://internal.example');
        config()->set('sso.password_client_id', $internal->id);
        $frontend = $this->createFrontendClient($superAdmin, 'ShopHHP Web', 'https://shophhp.net');

        $this->actingAs($superAdmin)
            ->get('/admin/frontend-clients')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/FrontendClients/Index')
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $frontend->id)
                ->where('clients.data.0.name', 'ShopHHP Web')
                ->where('clients.data.0.allowed_origins.0', 'https://shophhp.net')
                ->missing('clients.data.0.secret')
                ->where('can_manage', true));
    }

    public function test_manager_can_create_update_and_disable_frontend_client(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('admin');
        $manager->givePermissionTo(AppPermission::FrontendClientsManage->value);

        $this->actingAs($manager)
            ->post('/admin/frontend-clients', [
                'name' => 'VangHHP Web',
                'allowed_origins' => ['https://VANGHHP.vn/'],
                'allows_direct_login' => true,
                'active' => true,
            ])
            ->assertRedirect();

        $client = Client::query()->where('name', 'VangHHP Web')->firstOrFail();
        $this->assertTrue((bool) $client->is_first_party);
        $this->assertTrue((bool) $client->allows_direct_login);
        $this->assertFalse((bool) $client->revoked);
        $this->assertSame(
            ['https://vanghhp.vn'],
            json_decode($client->getRawOriginal('allowed_origins'), true),
        );

        $this->actingAs($manager)
            ->put("/admin/frontend-clients/{$client->id}", [
                'name' => 'VangHHP Production',
                'allowed_origins' => ['https://vanghhp.vn', 'https://www.vanghhp.vn'],
                'allows_direct_login' => true,
                'active' => true,
            ])
            ->assertRedirect();

        $this->actingAs($manager)
            ->patch("/admin/frontend-clients/{$client->id}/status", ['active' => false])
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('VangHHP Production', $client->name);
        $this->assertTrue((bool) $client->revoked);
        $this->assertDatabaseHas('user_security_logs', [
            'user_id' => $manager->id,
            'event' => 'frontend_client_disabled',
        ]);
    }

    public function test_viewer_cannot_mutate_clients_and_internal_client_cannot_be_edited(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('ctv');
        $viewer->givePermissionTo(AppPermission::FrontendClientsView->value);

        $manager = User::factory()->create();
        $manager->assignRole('admin');
        $manager->givePermissionTo(AppPermission::FrontendClientsManage->value);

        $internal = $this->createFrontendClient($manager, 'Internal token issuer', 'https://internal.example');
        config()->set('sso.password_client_id', $internal->id);

        $this->actingAs($viewer)
            ->get('/admin/frontend-clients')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_manage', false));

        $this->actingAs($viewer)
            ->post('/admin/frontend-clients', [
                'name' => 'Blocked',
                'allowed_origins' => ['https://blocked.example'],
                'allows_direct_login' => true,
                'active' => true,
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->put("/admin/frontend-clients/{$internal->id}", [
                'name' => 'Must remain internal',
                'allowed_origins' => ['https://changed.example'],
                'allows_direct_login' => true,
                'active' => true,
            ])
            ->assertNotFound();
    }

    public function test_active_frontend_origin_is_added_to_cors(): void
    {
        $owner = User::factory()->create();
        $this->createFrontendClient($owner, 'Dynamic CORS', 'https://frontend.example');
        app(FrontendClientRegistry::class)->forget();

        $this->withHeaders([
            'Origin' => 'https://frontend.example',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/card-types')
            ->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example');
    }

    private function createFrontendClient(User $owner, string $name, string $origin): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            $name,
            [$origin],
            false,
            $owner,
        );

        $client->forceFill([
            'is_first_party' => true,
            'allows_direct_login' => true,
            'allowed_origins' => json_encode([$origin], JSON_UNESCAPED_SLASHES),
            'revoked' => false,
        ])->save();

        return $client;
    }
}
