<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('device_id', 191);
            $table->string('device_name', 191)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('trusted_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['device_id'], 'user_devices_device_id_unique');
            $table->index(['user_id', 'last_seen_at'], 'user_devices_user_id_last_seen_at_index');
            $table->foreign(['user_id'], 'user_devices_user_id_foreign')->references(['id'])->on('users')->noActionOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
