<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->foreignId('last_read_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'chat_participants_conversation_user_unique');
            $table->index(['user_id', 'last_read_at'], 'chat_participants_user_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
