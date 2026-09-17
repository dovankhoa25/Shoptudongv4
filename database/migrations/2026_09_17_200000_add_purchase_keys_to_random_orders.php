<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('random_orders', function (Blueprint $table): void {
            $table->string('purchase_key', 64)->nullable();
            $table->string('purchase_fingerprint', 64)->nullable();
            $table->unique(['user_id', 'purchase_key']);
        });
    }
    public function down(): void {
        Schema::table('random_orders', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'purchase_key']);
            $table->dropColumn(['purchase_key', 'purchase_fingerprint']);
        });
    }
};
