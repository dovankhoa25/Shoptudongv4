<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->decimal('amount', 12, 0);
            $table->decimal('balance_before', 12, 0)->unsigned();
            $table->decimal('balance_after', 12, 0)->unsigned();
            $table->text('description')->nullable();
            $table->string('related_id', 64)->nullable();
            // 127 + 64 = 191 ký tự cho composite index trên hosting cũ.
            $table->string('related_type', 127)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['performed_by', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
