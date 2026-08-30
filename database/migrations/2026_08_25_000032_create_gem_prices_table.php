<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gem_prices', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id');
            $table->decimal('multiplier', 5, 1);
            $table->boolean('status')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['server_id'], 'gem_prices_server_id_foreign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_prices');
    }
};
