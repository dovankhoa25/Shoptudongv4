<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_bots', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('name', 191)->nullable();
            $table->string('account_name', 191);
            $table->string('account_password', 191);
            $table->unsignedBigInteger('server_id');
            $table->tinyInteger('server_game_id')->nullable();
            $table->bigInteger('gem_qty')->default(0);
            $table->string('map_name', 191)->nullable();
            $table->string('map_id', 191);
            $table->string('area_number', 191);
            $table->string('coordinates', 191)->nullable();
            $table->string('proxy', 150)->nullable();
            $table->enum('updated_by', ['web', 'app'])->default('web');
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['server_id'], 'gem_bots_server_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_bots');
    }
};
