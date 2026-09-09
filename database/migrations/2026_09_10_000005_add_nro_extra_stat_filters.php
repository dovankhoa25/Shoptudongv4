<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const EXTRA_COLUMNS = ['filter_life_steal', 'filter_ki_steal', 'filter_gold', 'filter_other'];

    public function up(): void
    {
        // MySQL keeps completed ALTER TABLE operations when a later backfill fails.
        // Resume safely whether none, some, or all of these columns already exist.
        foreach (self::EXTRA_COLUMNS as $column) {
            if (!Schema::hasColumn('nro_inventory_items', $column)) {
                Schema::table('nro_inventory_items', fn (Blueprint $t) => $t->boolean($column)->default(false));
            }
        }

        DB::table('nro_inventory_items')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $item = json_decode($row->item_json, true);
                DB::table('nro_inventory_items')->where('id', $row->id)->update(
                    $this->filterColumns(is_array($item) ? $item : [])
                );
            }
        });
    }

    /** Frozen migration rules; do not depend on deploy order or future service changes. */
    private function filterColumns(array $item): array
    {
        $result = ['filter_stars' => null];
        $optionIds = [
            'filter_damage' => [0, 49, 50, 147],
            'filter_hp' => [2, 6, 22, 48, 77],
            'filter_ki' => [2, 7, 23, 48, 103],
            'filter_life_steal' => [8, 95, 104],
            'filter_ki_steal' => [8, 96],
            'filter_gold' => [100],
            'filter_other' => [3,4,5,10,14,15,16,17,18,19,27,28,42,43,44,45,46,47,62,78,79,80,81,88,94,197,204,206],
        ];
        foreach ($optionIds as $column => $ids) $result[$column] = false;
        $options = $item['options'] ?? [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (!is_array($option) || !isset($option['optionId']) || !is_numeric($option['param'] ?? null)) continue;
            $id = (int) $option['optionId'];
            $value = (float) $option['param'];
            if (in_array($id, [102, 107], true) && $value >= 0 && $value <= 9) {
                $result['filter_stars'] = max($result['filter_stars'] ?? 0, (int) $value);
            }
            if ($value > 0) {
                foreach ($optionIds as $column => $ids) {
                    if (in_array($id, $ids, true)) $result[$column] = true;
                }
            }
        }
        return $result;
    }

    public function down(): void
    {
        $columns = array_values(array_filter(self::EXTRA_COLUMNS, fn ($column) => Schema::hasColumn('nro_inventory_items', $column)));
        if ($columns) Schema::table('nro_inventory_items', fn (Blueprint $t) => $t->dropColumn($columns));
    }
};
