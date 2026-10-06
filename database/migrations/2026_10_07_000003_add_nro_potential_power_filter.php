<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A failed backfill can leave the column in place on MySQL; allow a safe retry.
        if (!Schema::hasColumn('nro_inventory_items', 'filter_potential_power')) {
            Schema::table('nro_inventory_items', fn (Blueprint $t) => $t->boolean('filter_potential_power')->default(false));
        }

        DB::table('nro_inventory_items')->select(['id', 'item_json'])->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $item = json_decode($row->item_json, true);
                $options = is_array($item) ? ($item['options'] ?? []) : [];
                $matches = false;
                // Frozen rules: do not depend on future service changes or deployment order.
                foreach (is_array($options) ? $options : [] as $option) {
                    if (!is_array($option)) continue;
                    $id = (int) ($option['optionId'] ?? -1);
                    if ($id === 83 || (in_array($id, [101, 230], true) && is_numeric($option['param'] ?? null) && $option['param'] > 0)) {
                        $matches = true;
                        break;
                    }
                }
                // Never replace a flag calculated from a newer snapshot during the backfill.
                DB::table('nro_inventory_items')->where('id', $row->id)->where('item_json', $row->item_json)
                    ->update(['filter_potential_power' => $matches]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('nro_inventory_items', 'filter_potential_power')) {
            Schema::table('nro_inventory_items', fn (Blueprint $t) => $t->dropColumn('filter_potential_power'));
        }
    }
};
