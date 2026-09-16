<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('nro_accounts','login_failure_kind')) Schema::table('nro_accounts',function(Blueprint $table) {
            $table->string('login_failure_kind',50)->nullable();
        });
    }
    public function down(): void {
        if (Schema::hasColumn('nro_accounts','login_failure_kind')) Schema::table('nro_accounts',fn(Blueprint $table) => $table->dropColumn('login_failure_kind'));
    }
};
