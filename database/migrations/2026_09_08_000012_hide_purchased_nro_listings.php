<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::table('item_listings')->whereIn('id', DB::table('item_orders')->select('listing_id'))->update(['status' => 'sold']);
    }
    public function down(): void {
        // Do not put purchased goods back on sale when rolling back code.
    }
};
