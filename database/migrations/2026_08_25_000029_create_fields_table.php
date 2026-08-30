<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fields', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('label', 191);
            $table->string('field_key', 191);
            $table->enum('type', ['text', 'textarea', 'number', 'select'])->default('text');
            $table->longText('options')->nullable();
            $table->boolean('required')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fields');
    }
};
