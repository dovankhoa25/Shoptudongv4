<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atm_topups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('sepay');
            $table->string('provider_transaction_id', 64);
            $table->string('gateway', 50);
            $table->dateTime('transaction_at');
            $table->string('account_number', 100);
            $table->string('sub_account', 100)->nullable();
            $table->string('payment_code', 100);
            $table->text('content')->nullable();
            $table->string('transfer_type', 10);
            $table->decimal('amount', 12, 0);
            $table->string('reference_code', 191)->nullable();
            $table->decimal('accumulated', 15, 0)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('completed');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atm_topups');
    }
};
