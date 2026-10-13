<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gem_prices', function (Blueprint $table): void {
            $table->decimal('gift_ticket_multiplier', 5, 2)->nullable()->after('multiplier');
        });
        Schema::table('gem_transactions', function (Blueprint $table): void {
            $table->decimal('price_at_transaction', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('gem_prices', function (Blueprint $table): void {
            $table->dropColumn('gift_ticket_multiplier');
        });
        Schema::table('gem_transactions', function (Blueprint $table): void {
            $table->decimal('price_at_transaction', 10, 1)->change();
        });
    }
};
