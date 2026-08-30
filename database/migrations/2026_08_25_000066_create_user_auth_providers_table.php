<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_auth_providers', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 50);
            $table->string('provider_id', 141);
            $table->string('provider_email', 191)->nullable();
            $table->string('provider_username', 191)->nullable();
            $table->string('avatar', 191)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['provider', 'provider_id'], 'user_auth_providers_provider_provider_id_index');
            $table->unique(['user_id', 'provider'], 'user_auth_providers_user_id_provider_unique');
            $table->foreign(['user_id'], 'user_auth_providers_user_id_foreign')->references(['id'])->on('users')->noActionOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_providers');
    }
};
