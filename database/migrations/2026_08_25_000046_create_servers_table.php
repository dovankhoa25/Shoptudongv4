<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {

            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->string('name_view', 191)->nullable();
            $table->string('ip', 100)->nullable();
            $table->string('port', 50)->nullable();
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('min_money_amount')->default(10000);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
