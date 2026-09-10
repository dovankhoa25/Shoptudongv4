<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['failure_code','public_failure'] as $column) if (!Schema::hasColumn('item_orders',$column)) Schema::table('item_orders', fn(Blueprint $t) => $t->string($column)->nullable());
        if (!Schema::hasColumn('item_orders','cancel_requested')) Schema::table('item_orders', fn(Blueprint $t) => $t->boolean('cancel_requested')->default(false));
        if (!Schema::hasColumn('item_orders','login_retry_at')) Schema::table('item_orders', fn(Blueprint $t) => $t->timestamp('login_retry_at')->nullable());
    }
    public function down(): void {
        Schema::table('item_orders', fn(Blueprint $t) => $t->dropColumn(['failure_code','public_failure','cancel_requested','login_retry_at']));
    }
};
