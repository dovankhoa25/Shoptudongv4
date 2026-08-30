<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nick_attributes', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('nick_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('attribute_option_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['nick_id', 'attribute_id'], 'nick_attr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nick_attributes');
    }
};
