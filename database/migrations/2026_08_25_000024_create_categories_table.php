<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('game_type_id');
            $table->string('name', 191);
            $table->string('slug', 191)->nullable();
            $table->string('image', 191)->nullable();
            $table->string('template', 191)->nullable();
            $table->boolean('is_public')->default(false);
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['game_type_id'], 'categories_game_type_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
