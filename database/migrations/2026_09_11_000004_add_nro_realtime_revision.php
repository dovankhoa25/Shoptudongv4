<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('item_orders', 'realtime_revision')) Schema::table('item_orders', fn (Blueprint $t) => $t->unsignedBigInteger('realtime_revision')->default(0));
        if (!Schema::hasIndex('nro_worker_jobs','nro_jobs_expiration_index')) Schema::table('nro_worker_jobs', fn (Blueprint $t) => $t->index(['status','lease_until'], 'nro_jobs_expiration_index'));
    }
    public function down(): void {
        Schema::table('nro_worker_jobs', fn (Blueprint $t) => $t->dropIndex('nro_jobs_expiration_index'));
        Schema::table('item_orders', fn (Blueprint $t) => $t->dropColumn('realtime_revision'));
    }
};
