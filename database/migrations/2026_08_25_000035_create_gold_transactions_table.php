<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_transactions', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->enum('type', ['import', 'order']);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->string('character_name', 191);
            $table->decimal('amount_vnd', 20, 0)->nullable();
            $table->bigInteger('gold_qty')->nullable();
            $table->bigInteger('gold_bar_qty')->nullable();
            $table->bigInteger('pure_gold_qty')->nullable();
            $table->decimal('price_at_transaction', 20, 0);
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('pending');
            $table->enum('updated_by', ['web', 'app'])->default('web');
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['bot_id'], 'gold_transactions_bot_id_foreign');
            $table->index(['server_id', 'type'], 'gold_transactions_server_id_type_index');
            $table->index(['user_id'], 'gold_transactions_user_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_transactions');
    }
};
