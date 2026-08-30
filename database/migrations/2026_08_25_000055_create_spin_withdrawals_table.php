<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spin_withdrawals', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->enum('currency', ['gold', 'gem']);
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('note', 191)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id'], 'spin_withdrawals_user_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_withdrawals');
    }
};
