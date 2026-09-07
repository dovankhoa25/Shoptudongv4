<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            // Keep this as an indexed reference instead of a foreign key. Messages
            // already cascade from conversations, so this avoids a circular delete
            // path while still allowing one pinned note per conversation.
            $table->unsignedBigInteger('pinned_note_id')->nullable()->after('last_message_id');
            $table->index('pinned_note_id', 'chat_conversations_pinned_note_index');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropIndex('chat_conversations_pinned_note_index');
            $table->dropColumn('pinned_note_id');
        });
    }
};
