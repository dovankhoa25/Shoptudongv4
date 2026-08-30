<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spin_results', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('spin_id');
            $table->enum('reward_type', ['text', 'coin', 'gem', 'nick', 'item']);
            $table->string('reward_value', 191);
            $table->unsignedBigInteger('reward_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['spin_id'], 'spin_results_spin_id_foreign');
            $table->index(['user_id'], 'spin_results_user_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_results');
    }
};
