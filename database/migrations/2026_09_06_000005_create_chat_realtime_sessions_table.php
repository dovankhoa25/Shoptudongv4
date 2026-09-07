<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_realtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('credential_hash', 64)->unique();
            $table->text('session_locator');
            $table->dateTime('last_seen_at');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'expires_at'], 'chat_realtime_sessions_delivery_index');
        });

        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'revoked', 'expires_at'],
                'oauth_access_tokens_chat_delivery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('oauth_access_tokens_chat_delivery_index');
        });

        Schema::dropIfExists('chat_realtime_sessions');
    }
};
