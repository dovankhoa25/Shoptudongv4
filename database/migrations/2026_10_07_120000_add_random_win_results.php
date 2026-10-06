<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('random_boxes', function (Blueprint $table) {
            $table->decimal('win_rate', 5, 2)->default(100);
        });
        Schema::table('random_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('random_nick_id')->nullable()->change();
            $table->unsignedBigInteger('random_box_id')->nullable()->index();
            $table->string('result', 8)->default('win');
            $table->decimal('win_rate_snapshot', 5, 2)->default(100);
            $table->string('lose_reason', 24)->nullable();
            $table->unsignedTinyInteger('selected_slot')->nullable();
        });
        DB::table('random_orders')->update([
            'random_box_id' => DB::raw('(SELECT random_box_id FROM random_nicks WHERE random_nicks.id = random_orders.random_nick_id)'),
        ]);
        Schema::table('random_nicks', function (Blueprint $table) {
            $table->index(['random_box_id', 'status', 'deleted_at', 'id'], 'random_nicks_available_stock');
        });
    }

    public function down(): void
    {
        // Losing orders cannot be represented by the old schema. Never discard paid history.
        if (DB::table('random_orders')->whereNull('random_nick_id')->exists()) {
            throw new RuntimeException('Cannot roll back random results while losing orders exist.');
        }
        Schema::table('random_nicks', fn (Blueprint $table) => $table->dropIndex('random_nicks_available_stock'));
        Schema::table('random_orders', function (Blueprint $table) {
            $table->dropIndex(['random_box_id']);
            $table->dropColumn(['random_box_id', 'result', 'win_rate_snapshot', 'lose_reason', 'selected_slot']);
            $table->unsignedBigInteger('random_nick_id')->nullable(false)->change();
        });
        Schema::table('random_boxes', fn (Blueprint $table) => $table->dropColumn('win_rate'));
    }
};
