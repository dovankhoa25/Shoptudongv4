<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_device_id')->nullable();
            $table->string('session_id', 191)->nullable();
            $table->string('oauth_access_token_id', 100)->nullable();
            $table->char('oauth_client_id', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_revoked')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 191)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['oauth_access_token_id'], 'user_sessions_oauth_access_token_id_index');
            $table->index(['oauth_client_id'], 'user_sessions_oauth_client_id_index');
            $table->index(['session_id'], 'user_sessions_session_id_index');
            $table->index(['user_device_id'], 'user_sessions_user_device_id_foreign');
            $table->index(['user_id', 'is_revoked'], 'user_sessions_user_id_is_revoked_index');
            $table->foreign(['user_device_id'], 'user_sessions_user_device_id_foreign')->references(['id'])->on('user_devices')->noActionOnUpdate()->nullOnDelete();
            $table->foreign(['user_id'], 'user_sessions_user_id_foreign')->references(['id'])->on('users')->noActionOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
