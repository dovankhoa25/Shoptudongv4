<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->decimal('declared_value', 10, 2);
            $table->decimal('value', 10, 2)->nullable();
            $table->decimal('amount_user', 10, 2)->nullable();
            $table->decimal('amount_api', 10, 2)->nullable();
            $table->decimal('difference', 10, 2)->nullable()->comment('Chênh lệch giữa số tiền API trả và số tiền user nhận');
            $table->decimal('discount_rate_at_time', 5, 2);
            $table->string('code', 95);
            $table->string('serial', 96);
            $table->string('trans_id', 191)->nullable();
            $table->string('status', 100)->default('pending');
            $table->boolean('loaded_type')->default(true);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('card_type_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['card_type_id'], 'cards_card_type_id_foreign');
            $table->unique(['code', 'serial'], 'cards_code_serial_unique');
            $table->unique(['trans_id'], 'cards_trans_id_unique');
            $table->index(['user_id'], 'cards_user_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
