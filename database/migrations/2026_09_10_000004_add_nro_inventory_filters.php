<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('nro_inventory_items', function(Blueprint $t) {
            $t->unsignedTinyInteger('filter_stars')->nullable()->index();
            $t->boolean('filter_damage')->default(false); $t->boolean('filter_hp')->default(false); $t->boolean('filter_ki')->default(false);
        });
        DB::table('nro_inventory_items')->orderBy('id')->chunkById(200, function($rows) {
            foreach ($rows as $row) DB::table('nro_inventory_items')->where('id',$row->id)->update(
                \App\Services\NroItemFilters::inventoryColumns(json_decode($row->item_json,true) ?: []));
        });
    }
    public function down(): void {
        Schema::table('nro_inventory_items', function(Blueprint $t) { $t->dropIndex(['filter_stars']); $t->dropColumn(['filter_stars','filter_damage','filter_hp','filter_ki']); });
    }
};
