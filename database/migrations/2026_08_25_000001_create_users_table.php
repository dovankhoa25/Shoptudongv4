<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('username', 191);
            $table->string('email', 191)->nullable();
            $table->decimal('balance', 12, 0)->default('0');
            $table->string('avatar', 191)->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('locked_until')->nullable();
            $table->string('provider', 191)->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->string('password', 191)->nullable();
            $table->boolean('is_locked')->default(false);
            $table->string('locked_reason', 191)->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['locked_by'], 'users_locked_by_foreign');
            $table->index(['status', 'locked_until'], 'users_status_locked_until_index');
            $table->foreign(['locked_by'], 'users_locked_by_foreign')->references(['id'])->on('users')->noActionOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
