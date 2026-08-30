<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('title', 191);
            $table->text('message')->nullable();
            $table->string('type', 191)->default('info');
            $table->string('position', 191)->nullable();
            $table->string('link', 191)->nullable();
            $table->string('image', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
