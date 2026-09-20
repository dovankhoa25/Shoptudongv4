<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        foreach (['stock_mode', 'packages_remaining', 'policy_blocked'] as $column) {
            if (Schema::hasColumn('item_listings', $column)) continue;
            Schema::table('item_listings', function (Blueprint $t) use ($column) {
                match ($column) {
                    'stock_mode' => $t->string($column, 12)->default('fixed'),
                    'packages_remaining' => $t->unsignedInteger($column)->nullable(),
                    'policy_blocked' => $t->boolean($column)->default(false),
                };
            });
        }
        // Null marks only unconverted rows; rerunning after failed MySQL DDL is safe.
        DB::table('item_listings')->whereNull('packages_remaining')->update([
            'packages_remaining' => DB::raw("CASE WHEN status = 'sold' OR EXISTS (SELECT 1 FROM item_orders WHERE item_orders.listing_id = item_listings.id) THEN 0 ELSE 1 END"),
        ]);
        if (!Schema::hasColumn('item_listings', 'public_description')) Schema::table('item_listings', fn (Blueprint $t) => $t->text('public_description')->nullable());
        if (!Schema::hasColumn('item_orders', 'package_quantity')) Schema::table('item_orders', fn (Blueprint $t) => $t->unsignedInteger('package_quantity')->default(1));
        if (!Schema::hasColumn('item_orders', 'unit_price')) Schema::table('item_orders', fn (Blueprint $t) => $t->unsignedBigInteger('unit_price')->nullable());
        DB::table('item_orders')->whereNull('unit_price')->update(['unit_price' => DB::raw('price')]);
        if (!Schema::hasTable('nro_seller_policies')) Schema::create('nro_seller_policies', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users');
            $t->boolean('selling_enabled')->default(true);
            $t->json('allow_ids')->nullable(); $t->json('deny_ids')->nullable(); $t->timestamps();
        });
        DB::table('item_listings')->whereIn('id', DB::table('item_listing_items as li')
            ->join('nro_inventory_items as i', 'i.id', '=', 'li.inventory_item_id')
            ->where('i.item_json->type', 5)->select('li.listing_id'))->update(['policy_blocked' => true]);
    }
    public function down(): void
    {
        // Financial stock history is retained; reverting code must not drop these columns.
    }
};
