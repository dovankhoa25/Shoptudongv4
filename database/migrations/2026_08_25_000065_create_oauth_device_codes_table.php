<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_device_codes', function (Blueprint $table): void {

            $table->char('id', 80);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('client_id', 36);
            $table->char('user_code', 8);
            $table->text('scopes');
            $table->boolean('revoked');
            $table->dateTime('user_approved_at')->nullable();
            $table->dateTime('last_polled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->index(['client_id'], 'oauth_device_codes_client_id_index');
            $table->unique(['user_code'], 'oauth_device_codes_user_code_unique');
            $table->index(['user_id'], 'oauth_device_codes_user_id_index');
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_device_codes');
    }
};
