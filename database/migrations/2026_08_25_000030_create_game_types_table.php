<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_types', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('slug', 191)->nullable();
            $table->string('icon', 191)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_types');
    }
};
