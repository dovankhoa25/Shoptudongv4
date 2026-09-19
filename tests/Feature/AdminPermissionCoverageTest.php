<?php

namespace Tests\Feature;

use App\Enums\Permission;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AdminPermissionCoverageTest extends TestCase
{
    public function test_every_admin_route_has_semantic_permissions_or_an_explicit_authorization_boundary(): void
    {
        // These endpoints authorize an owned session or dispatch through the target screen's
        // permissions. NRO exceptions have explicit role checks tested in AccessSecurityTest.
        $explicitBoundaries = [
            'GET admin/live-balance' => 'Closure',
            'POST admin/live-views' => 'App\\Http\\Controllers\\Admin\\LiveViewController@store',
            'POST admin/live-views/{id}/sync' => 'App\\Http\\Controllers\\Admin\\LiveViewController@sync',
            'PATCH admin/live-views/{id}' => 'App\\Http\\Controllers\\Admin\\LiveViewController@renew',
            'DELETE admin/live-views/{id}' => 'App\\Http\\Controllers\\Admin\\LiveViewController@destroy',
            'PATCH admin/nro-shop/sale-policy' => 'App\\Http\\Controllers\\Admin\\NroShopController@salePolicy',
            'POST admin/nro-shop/orders/{id}/refund' => 'App\\Http\\Controllers\\Admin\\NroShopController@refund',
        ];
        $semanticPermissions = collect(Permission::values());
        $adminRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = collect($route->gatherMiddleware());
            $permissionMiddleware = $middleware
                ->filter(fn (string $name): bool => str_starts_with($name, 'permission:'));

            $key = $route->methods()[0].' '.$route->uri();
            if (isset($explicitBoundaries[$key])) {
                $this->assertSame($explicitBoundaries[$key], $route->getActionName());
                $this->assertContains('auth', $middleware->all());
                $this->assertContains('unlocked.user', $middleware->all());

                continue;
            }

            $this->assertNotEmpty(
                $permissionMiddleware,
                "Route {$route->methods()[0]} {$route->uri()} chưa có permission middleware.",
            );
            $this->assertNotContains('check.permission', $middleware->all());

            $routePermissions = $permissionMiddleware
                ->flatMap(fn (string $name): array => explode(',', substr($name, strlen('permission:'))));

            $this->assertEmpty(
                $routePermissions->diff($semanticPermissions),
                "Route {$route->getName()} dùng permission không có trong enum.",
            );
        }
    }

    public function test_permission_middleware_helper_supports_one_or_many_permissions(): void
    {
        $this->assertSame(
            'permission:users.view',
            Permission::middleware(Permission::UsersView),
        );
        $this->assertSame(
            'permission:nicks.view,nicks.manage',
            Permission::middleware(Permission::NicksView, Permission::NicksManage),
        );
    }
}
