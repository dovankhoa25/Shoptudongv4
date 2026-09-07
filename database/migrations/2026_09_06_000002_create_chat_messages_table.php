<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_kind', 16);
            $table->string('type', 24)->default('text');
            $table->text('body')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->uuid('client_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'id'], 'chat_messages_conversation_cursor_index');
            $table->unique(['conversation_id', 'client_message_id'], 'chat_messages_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
