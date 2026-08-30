<?php

namespace Tests\Feature;

use App\Enums\Permission;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AdminPermissionCoverageTest extends TestCase
{
    public function test_every_admin_route_uses_only_semantic_permission_middleware(): void
    {
        $semanticPermissions = collect(Permission::values());
        $adminRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin'));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = collect($route->gatherMiddleware());
            $permissionMiddleware = $middleware
                ->filter(fn (string $name): bool => str_starts_with($name, 'permission:'));

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
