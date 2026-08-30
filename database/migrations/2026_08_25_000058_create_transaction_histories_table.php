<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_histories', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('transaction_type', 50);
            $table->unsignedBigInteger('transaction_id');
            $table->string('action', 50)->default('updated');
            $table->string('source', 50);
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->string('bot_type', 50)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
            $table->longText('changed_fields')->nullable();
            $table->longText('meta')->nullable();
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['action'], 'transaction_histories_action_index');
            $table->index(['admin_user_id'], 'transaction_histories_admin_user_id_index');
            $table->index(['bot_id'], 'transaction_histories_bot_id_index');
            $table->index(['source'], 'transaction_histories_source_index');
            $table->index(['transaction_type', 'transaction_id'], 'transaction_histories_transaction_type_transaction_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_histories');
    }
};
