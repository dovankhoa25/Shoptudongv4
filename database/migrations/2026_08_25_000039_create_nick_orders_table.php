<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nick_orders', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('nick_id');
            $table->unsignedBigInteger('buyer_id');
            $table->unsignedBigInteger('seller_id');
            $table->decimal('price', 12, 0);
            $table->decimal('commission', 12, 0)->nullable();
            $table->enum('status', ['pending', 'completed', 'refunded'])->default('completed');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nick_orders');
    }
};
