<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_histories', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 50)->default('updated');
            $table->string('source', 50);
            $table->string('category', 50)->nullable();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('transaction_type', 100)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
            $table->longText('changed_fields')->nullable();
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['action'], 'bot_histories_action_index');
            $table->index(['admin_user_id'], 'bot_histories_admin_user_id_index');
            $table->index(['category'], 'bot_histories_category_index');
            $table->index(['entity_type', 'entity_id'], 'bot_histories_entity_type_entity_id_index');
            $table->index(['source'], 'bot_histories_source_index');
            $table->index(['transaction_id'], 'bot_histories_transaction_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_histories');
    }
};
