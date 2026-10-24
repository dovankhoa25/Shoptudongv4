<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_trading_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('asset_type', 24);
            $table->boolean('sell_enabled')->default(true);
            $table->boolean('import_enabled')->default(true);
            $table->unsignedBigInteger('import_stock_limit_bars')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'asset_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_trading_policies');
    }
};
