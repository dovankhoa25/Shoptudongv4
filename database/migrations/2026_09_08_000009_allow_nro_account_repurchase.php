<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nro_accounts', function (Blueprint $table) {
            $table->dropUnique('nro_accounts_account_name_server_unique');
            $table->index(['account_name', 'server_game_id', 'status'], 'nro_accounts_registration_lookup');
        });
    }

    public function down(): void
    {
        // Never delete purchase history just to restore the old uniqueness rule.
        if (DB::table('nro_accounts')->whereNotNull('server')->select('account_name', 'server')
            ->groupBy('account_name', 'server')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore old account uniqueness while repurchased account history exists.');
        }
        Schema::table('nro_accounts', function (Blueprint $table) {
            $table->unique(['account_name', 'server']);
            $table->dropIndex('nro_accounts_registration_lookup');
        });
    }
};
