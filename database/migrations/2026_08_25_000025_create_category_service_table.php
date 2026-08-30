<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_service', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('service_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['category_id'], 'category_service_category_id_index');
            $table->unique(['category_id', 'service_id'], 'category_service_category_id_service_id_unique');
            $table->index(['service_id'], 'category_service_service_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_service');
    }
};
