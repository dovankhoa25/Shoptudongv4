<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_transactions', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->string('character_name', 191);
            $table->string('item', 150)->nullable();
            $table->decimal('amount_vnd', 20, 0)->nullable();
            $table->bigInteger('gem_qty')->nullable();
            $table->decimal('price_at_transaction', 10, 1);
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled', 'refunded'])->default('pending');
            $table->enum('updated_by', ['web', 'app'])->default('web');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['server_id'], 'gem_transactions_server_id_index');
            $table->index(['user_id'], 'gem_transactions_user_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_transactions');
    }
};
