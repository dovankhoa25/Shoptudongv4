<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('nro_worker_jobs','delivery_protocol')) Schema::table('nro_worker_jobs',fn(Blueprint $t)=>$t->unsignedTinyInteger('delivery_protocol')->default(4));
        if (!Schema::hasColumn('nro_delivery_rounds','batch_key')) Schema::table('nro_delivery_rounds',fn(Blueprint $t)=>$t->uuid('batch_key')->nullable()->index());
        if (!Schema::hasColumn('nro_delivery_sessions','recipient_character_id')) Schema::table('nro_delivery_sessions',fn(Blueprint $t)=>$t->integer('recipient_character_id')->nullable());
        if(Schema::hasIndex('nro_delivery_sessions','nro_delivery_sessions_receiver_lock_unique')) Schema::table('nro_delivery_sessions',fn(Blueprint $t)=>$t->dropUnique('nro_delivery_sessions_receiver_lock_unique'));
        if(!Schema::hasIndex('nro_delivery_sessions','nro_delivery_sessions_receiver_lock_index')) Schema::table('nro_delivery_sessions',fn(Blueprint $t)=>$t->index('receiver_lock'));
        if(!Schema::hasTable('nro_batch_receipts')) Schema::create('nro_batch_receipts',function(Blueprint $t){
            $t->uuid('batch_key')->primary();$t->unsignedBigInteger('account_id')->index();$t->string('digest',64);$t->timestamp('created_at')->nullable();
        });
        if(!Schema::hasTable('nro_receiver_locks')) Schema::create('nro_receiver_locks',function(Blueprint $t){$t->string('key',64)->primary();});
    }
    public function down(): void {
        Schema::table('nro_delivery_sessions',function(Blueprint $t){$t->dropColumn('recipient_character_id');$t->dropIndex(['receiver_lock']);});
        // Preserve active groups on rollback; old code still checks this column in its transaction.
        Schema::dropIfExists('nro_receiver_locks');
        Schema::dropIfExists('nro_batch_receipts');
        Schema::table('nro_delivery_rounds',fn(Blueprint $t)=>$t->dropColumn('batch_key'));
        Schema::table('nro_worker_jobs',fn(Blueprint $t)=>$t->dropColumn('delivery_protocol'));
    }
};
