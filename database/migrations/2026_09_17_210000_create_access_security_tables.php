<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_access_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_hash', 'ip_address'], 'admin_access_device_ip_unique');
        });
        Schema::create('access_ip_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('network', 49);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['revoked_at', 'expires_at']);
        });
        Schema::table('login_attempts', function (Blueprint $table): void {
            $table->string('username', 191)->nullable()->change();
            $table->index(['ip_address', 'user_id'], 'login_attempts_ip_user_index');
        });
        Schema::table('chat_realtime_sessions', function (Blueprint $table): void {
            $table->foreignId('admin_access_device_id')->nullable()->constrained('admin_access_devices')->nullOnDelete();
        });
        $permission = \Spatie\Permission\Models\Permission::findOrCreate('access-security.manage', 'web');
        \Spatie\Permission\Models\Role::where('guard_name', 'web')->whereIn('name', ['admin', 'super-admin'])
            ->get()->each(fn ($role) => $role->givePermissionTo($permission));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('login_attempts', fn (Blueprint $table) => $table->dropIndex('login_attempts_ip_user_index'));
        Schema::table('chat_realtime_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('admin_access_device_id'));
        Schema::dropIfExists('access_ip_blocks');
        Schema::dropIfExists('admin_access_devices');
    }
};
