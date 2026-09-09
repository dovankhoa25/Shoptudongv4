<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nro_worker_jobs', function (Blueprint $t) { $t->uuid('worker_instance')->nullable(); $t->index(['account_id', 'worker_instance', 'lease_until'], 'nro_worker_account_instance_lease'); });
        Schema::table('nro_delivery_sessions', function (Blueprint $t) {
            $t->timestamp('retry_at')->nullable();
            $t->string('trade_phase', 20)->nullable();
            $t->timestamp('phase_deadline')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('nro_worker_jobs', function (Blueprint $t) { $t->dropIndex('nro_worker_account_instance_lease'); $t->dropColumn('worker_instance'); });
        Schema::table('nro_delivery_sessions', function (Blueprint $t) { $t->dropColumn(['retry_at', 'trade_phase', 'phase_deadline']); });
    }
};
