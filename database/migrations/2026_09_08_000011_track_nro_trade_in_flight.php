<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Null means an older session whose trade stage cannot be proven automatically.
        Schema::table('nro_delivery_sessions', fn (Blueprint $t) => $t->boolean('trade_in_flight')->nullable());
    }
    public function down(): void {
        Schema::table('nro_delivery_sessions', fn (Blueprint $t) => $t->dropColumn('trade_in_flight'));
    }
};
