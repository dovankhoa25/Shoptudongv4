<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 32)->default('general');
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('waiting_agent');
            $table->string('priority', 16)->default('normal');
            $table->string('source_app', 64)->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id'], 'chat_conversations_subject_unique');
            $table->index(['customer_id', 'status', 'last_message_at'], 'chat_conversations_customer_inbox_index');
            $table->index(['assigned_to_id', 'status', 'last_message_at'], 'chat_conversations_agent_inbox_index');
            $table->index(['customer_id', 'last_message_at', 'id'], 'chat_conversations_customer_sort_index');
            $table->index(['assigned_to_id', 'last_message_at', 'id'], 'chat_conversations_assignee_sort_index');
            $table->index(['last_message_at', 'id'], 'chat_conversations_admin_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
