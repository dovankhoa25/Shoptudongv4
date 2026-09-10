<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach(['audit_order_id','audit_requested_by'] as $name) if(!Schema::hasColumn('nro_worker_jobs',$name)) Schema::table('nro_worker_jobs',function(Blueprint $t) use($name) { $t->unsignedBigInteger($name)->nullable()->index(); });
    }
    public function down(): void { Schema::table('nro_worker_jobs',fn(Blueprint $t)=>$t->dropColumn(['audit_order_id','audit_requested_by'])); }
};
