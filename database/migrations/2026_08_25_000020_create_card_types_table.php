<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_types', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('telco', 191);
            $table->decimal('discount_rate', 5, 2)->default('0.00');
            $table->boolean('status')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['telco'], 'card_types_telco_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_types');
    }
};
