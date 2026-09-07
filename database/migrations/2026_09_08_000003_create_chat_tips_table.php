<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_tips', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->restrictOnDelete();
            $table->foreignId('payer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->unique()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('payer_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->foreignId('recipient_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->decimal('amount', 12, 0)->unsigned();
            $table->decimal('platform_fee', 12, 0)->unsigned()->default(0);
            $table->decimal('recipient_amount', 12, 0)->unsigned();
            $table->char('currency', 3)->default('VND');
            $table->string('status', 24)->default('pending');
            $table->uuid('idempotency_key');
            $table->string('note', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['payer_id', 'idempotency_key'], 'chat_tips_payer_idempotency_unique');
            $table->index(['conversation_id', 'created_at'], 'chat_tips_conversation_index');
            $table->index(['recipient_id', 'status', 'created_at'], 'chat_tips_recipient_index');
            $table->index(['payer_id', 'status', 'created_at'], 'chat_tips_payer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_tips');
    }
};
