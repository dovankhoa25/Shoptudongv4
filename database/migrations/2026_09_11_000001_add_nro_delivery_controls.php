<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('nro_accounts','snapshot_failures')) Schema::table('nro_accounts',fn(Blueprint $t)=>$t->unsignedInteger('snapshot_failures')->default(0));
        $columns = ['refund_requested'=>'boolean','refund_amount'=>'unsignedBigInteger','refund_actor_id'=>'unsignedBigInteger','refund_note'=>'text','refunded_at'=>'timestamp'];
        foreach ($columns as $name=>$type) if (!Schema::hasColumn('item_orders',$name)) Schema::table('item_orders',function(Blueprint $t) use($name,$type) {
            $c=$t->$type($name); if($name==='refund_requested') $c->default(false); elseif($name==='refund_amount') $c->default(0); else $c->nullable();
        });
        if (!Schema::hasTable('nro_late_results')) Schema::create('nro_late_results',function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('job_id')->index(); $t->string('digest',64)->unique(); $t->string('kind',20); $t->longText('payload_json'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('nro_late_results');
        Schema::table('item_orders',fn(Blueprint $t)=>$t->dropColumn(['refund_requested','refund_amount','refund_actor_id','refund_note','refunded_at']));
        Schema::table('nro_accounts',fn(Blueprint $t)=>$t->dropColumn('snapshot_failures'));
    }
};
