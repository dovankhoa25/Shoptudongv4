<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('item_listings','cost_price')) Schema::table('item_listings',fn(Blueprint $t)=>$t->unsignedBigInteger('cost_price')->nullable());
        if (!Schema::hasColumn('item_orders','unit_cost_price')) Schema::table('item_orders',fn(Blueprint $t)=>$t->unsignedBigInteger('unit_cost_price')->nullable());
    }
    public function down(): void {
        Schema::table('item_orders',fn(Blueprint $t)=>$t->dropColumn('unit_cost_price'));
        Schema::table('item_listings',fn(Blueprint $t)=>$t->dropColumn('cost_price'));
    }
};
