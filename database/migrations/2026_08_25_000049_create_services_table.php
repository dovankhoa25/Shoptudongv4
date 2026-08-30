<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->decimal('default_price', 10, 0)->nullable();
            $table->decimal('original_price', 10, 0)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->string('processing_time', 191)->nullable();
            $table->string('warranty', 191)->nullable();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
