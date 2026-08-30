<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_templates', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->longText('features')->nullable();
            $table->longText('requirements')->nullable();
            $table->longText('instructions')->nullable();
            $table->longText('faq')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['category_id'], 'category_templates_category_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_templates');
    }
};
