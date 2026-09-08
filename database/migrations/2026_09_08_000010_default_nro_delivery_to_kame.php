<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('nro_accounts', function (Blueprint $t) {
            $t->unsignedSmallInteger('delivery_map')->default(5)->change();
            $t->unsignedSmallInteger('delivery_zone')->default(4)->change();
            $t->string('delivery_zone_mode', 10)->default('auto');
        });
        DB::table('nro_accounts')->whereNotNull('usage_type')->where('delivery_map', 24)->update(['delivery_map' => 5]);
        DB::table('nro_accounts')->whereNotNull('usage_type')->where('delivery_zone', 0)->update(['delivery_zone' => 4]);
    }
    public function down(): void {
        Schema::table('nro_accounts', function (Blueprint $t) {
            $t->unsignedSmallInteger('delivery_map')->default(24)->change();
            $t->unsignedSmallInteger('delivery_zone')->default(0)->change();
            $t->dropColumn('delivery_zone_mode');
        });
    }
};
