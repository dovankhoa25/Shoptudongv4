<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('servers', function (Blueprint $table) { $table->boolean('gold_radar_enabled')->default(false); });
        Schema::table('bots', function (Blueprint $table) {
            $table->unsignedInteger('radar_qty')->default(0);
            $table->unsignedSmallInteger('bag_capacity')->nullable();
            $table->unsignedSmallInteger('bag_free_slots')->nullable();
        });
    }
    public function down(): void {
        Schema::table('bots', fn (Blueprint $table) => $table->dropColumn(['radar_qty', 'bag_capacity', 'bag_free_slots']));
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('gold_radar_enabled'));
    }
};
