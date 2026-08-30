<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_game_login', function (Blueprint $table): void {

            $table->integer('id');
            $table->string('ip', 50)->nullable();
            $table->string('port', 50)->nullable();
            $table->string('name', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_game_login');
    }
};
