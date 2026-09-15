<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gem_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('min_amount')->default(10000);
        });
    }

    public function down(): void
    {
        Schema::table('gem_prices', function (Blueprint $table) {
            $table->dropColumn('min_amount');
        });
    }
};
