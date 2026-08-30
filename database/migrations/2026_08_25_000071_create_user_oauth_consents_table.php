<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_oauth_consents', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->char('oauth_client_id', 36);
            $table->json('scopes')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['oauth_client_id'], 'user_oauth_consents_oauth_client_id_index');
            $table->unique(['user_id', 'oauth_client_id'], 'user_oauth_consents_user_id_oauth_client_id_unique');
            $table->foreign(['user_id'], 'user_oauth_consents_user_id_foreign')->references(['id'])->on('users')->noActionOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_oauth_consents');
    }
};
