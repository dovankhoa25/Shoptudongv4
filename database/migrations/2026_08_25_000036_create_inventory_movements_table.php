<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('bot_id');
            $table->string('bot_type', 30);
            $table->string('asset_type', 30);
            $table->string('movement_type', 40);
            $table->bigInteger('quantity_delta');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('transaction_type', 40)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('source', 30);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->longText('meta')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['bot_type', 'bot_id'], 'inventory_bot_index');
            $table->index(['admin_user_id'], 'inventory_movements_admin_user_id_foreign');
            $table->unique(['idempotency_key'], 'inventory_movements_idempotency_key_unique');
            $table->index(['occurred_at'], 'inventory_movements_occurred_at_index');
            $table->index(['server_id', 'asset_type', 'occurred_at'], 'inventory_server_asset_date_index');
            $table->index(['transaction_type', 'transaction_id'], 'inventory_transaction_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
