<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spins', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->string('name', 191);
            $table->string('image', 191)->nullable();
            $table->enum('type', ['wheel', 'flip'])->default('wheel');
            $table->decimal('price_per_turn', 10, 0);
            $table->unsignedInteger('total_slots')->default(8);
            $table->boolean('is_public')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['category_id'], 'spins_category_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spins');
    }
};
