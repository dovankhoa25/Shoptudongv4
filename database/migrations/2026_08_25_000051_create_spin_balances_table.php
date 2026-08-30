<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spin_balances', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('gold_balance')->default(0);
            $table->unsignedBigInteger('gem_balance')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user_id'], 'spin_balances_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_balances');
    }
};
