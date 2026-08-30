<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_punishments', function (Blueprint $table): void {

            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50)->default('ok');
            $table->string('reason', 191);
            $table->text('note')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 191)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'is_active']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_punishments');
    }
};
