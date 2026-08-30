<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_service', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('service_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['field_id'], 'field_service_field_id_index');
            $table->unique(['field_id', 'service_id'], 'field_service_field_id_service_id_unique');
            $table->index(['service_id'], 'field_service_service_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service');
    }
};
