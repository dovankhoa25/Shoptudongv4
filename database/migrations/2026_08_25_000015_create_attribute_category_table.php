<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_category', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['attribute_id', 'category_id'], 'attribute_category_attribute_id_category_id_unique');
            $table->index(['category_id'], 'attribute_category_category_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_category');
    }
};
