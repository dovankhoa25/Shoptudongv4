<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atm_topups', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('payment_code', 100)->nullable()->change();
            $table->string('review_reason', 191)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('atm_topups')->whereNull('user_id')->orWhereNull('payment_code')->exists()) {
            throw new LogicException('Resolve unmatched bank topups before rolling back this migration.');
        }

        Schema::table('atm_topups', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->string('payment_code', 100)->nullable(false)->change();
            $table->dropColumn('review_reason');
        });
    }
};
