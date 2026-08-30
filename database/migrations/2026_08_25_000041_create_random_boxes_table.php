<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('random_boxes', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->string('name', 191);
            $table->decimal('price', 10, 0);
            $table->string('image', 191)->nullable();
            $table->boolean('is_public')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('random_boxes');
    }
};
