<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username', 146)->nullable();
            $table->string('email', 146)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_success')->default(false);
            $table->string('failure_reason', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['created_at'], 'login_attempts_created_at_index');
            $table->index(['email', 'ip_address'], 'login_attempts_email_ip_address_index');
            $table->index(['provider', 'is_success'], 'login_attempts_provider_is_success_index');
            $table->index(['user_id'], 'login_attempts_user_id_foreign');
            $table->index(['username', 'ip_address'], 'login_attempts_username_ip_address_index');
            $table->foreign(['user_id'], 'login_attempts_user_id_foreign')->references(['id'])->on('users')->noActionOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
